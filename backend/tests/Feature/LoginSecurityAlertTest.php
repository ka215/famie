<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\LoginSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginSecurityAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.ip_restriction_enabled' => false]);
        Cache::flush();
    }

    public function test_second_lockout_notifies_all_active_parents_only_and_deduplicates_for_24_hours(): void
    {
        $this->freezeTime();
        Mail::fake();
        $group = Group::factory()->create();
        $first = $this->member($group, 'admin');
        $second = $this->member($group, 'admin');
        $inactive = $this->member($group, 'admin', 'inactive');
        $child = $this->member($group);
        $foreign = $this->member(Group::factory()->create(), 'admin');
        $this->failFiveTimes($child->user);
        $this->assertDatabaseCount('notifications', 0);
        $this->travel(10)->minutes();
        $this->failFiveTimes($child->user);
        $this->assertSame(1, $first->user->unreadNotifications()->count());
        $this->assertSame(1, $second->user->unreadNotifications()->count());
        $this->assertSame(0, $child->user->notifications()->count());
        $this->assertSame(0, $inactive->user->notifications()->count());
        $this->assertSame(0, $foreign->user->notifications()->count());
        $this->travel(10)->minutes();
        $this->failFiveTimes($child->user);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('group_members', ['id' => $child->id, 'status' => 'active']);
        Mail::assertNothingSent();
    }

    public function test_lockout_history_survives_cache_clear_and_shares_accounts_across_ips_and_email(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $child = $this->member($group);
        $this->failFiveTimes($child->user);
        Cache::flush();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.15']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/login', ['login' => strtoupper($child->user->email), 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->assertSame(1, $parent->user->notifications()->count());
    }

    public function test_lockouts_more_than_24_hours_apart_do_not_notify_and_later_repeated_events_notify_again(): void
    {
        $this->freezeTime();
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $child = $this->member($group);
        $service = app(LoginSecurityService::class);
        $service->recordLockout($child->user);
        $this->travel(24 * 60 + 1)->minutes();
        $service->recordLockout($child->user);
        $this->assertDatabaseCount('notifications', 0);
        $this->travel(10)->minutes();
        $service->recordLockout($child->user);
        $this->assertDatabaseCount('notifications', 1);
        $this->travel(24)->hours();
        $service->recordLockout($child->user);
        $this->assertSame(2, $parent->user->notifications()->count());
    }

    public function test_alerts_are_private_to_current_parent_and_read_status_is_independent(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $otherParent = $this->member($group, 'admin');
        $child = $this->member($group);
        app(LoginSecurityService::class)->recordLockout($child->user);
        app(LoginSecurityService::class)->recordLockout($child->user);
        Sanctum::actingAs($parent->user);
        $path = '/v1/groups/'.$group->id.'/security-alerts';
        $response = $this->getJson($path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.username', $child->user->username);
        $id = $response->json('data.0.id');
        $foreignId = $otherParent->user->notifications()->firstOrFail()->id;
        $this->putJson($path.'/'.$foreignId.'/read')->assertNotFound();
        $this->putJson($path.'/'.$id.'/read', ['read_at' => '2099-01-01'])->assertUnprocessable();
        $this->putJson($path.'/'.$id.'/read')->assertOk();
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, $otherParent->user->unreadNotifications()->count());
        Sanctum::actingAs($child->user);
        $this->getJson($path)->assertForbidden();
        $this->putJson($path.'/'.$id.'/read')->assertForbidden();
        $parent->update(['role' => 'member']);
        Sanctum::actingAs($parent->user);
        $this->getJson($path)->assertForbidden();
    }

    public function test_alerts_require_authentication_and_cannot_be_read_across_families(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $this->getJson('/v1/groups/'.$group->id.'/security-alerts')->assertUnauthorized();
        $foreign = Group::factory()->create();
        Sanctum::actingAs($parent->user);
        $this->getJson('/v1/groups/'.$foreign->id.'/security-alerts')->assertNotFound();
    }

    private function failFiveTimes(User $user): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'wrong'])->assertUnprocessable();
        }
    }

    public function test_blocked_retries_and_unknown_or_inactive_accounts_do_not_create_alerts(): void
    {
        $group = Group::factory()->create();
        $this->member($group, 'admin');
        $child = $this->member($group);
        $this->failFiveTimes($child->user);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/login', ['login' => $child->user->username, 'password' => 'wrong'])->assertTooManyRequests();
        }
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/login', ['login' => 'unknown-user', 'password' => 'wrong'])->assertUnprocessable();
        }
        $inactive = $this->member($group, 'member', 'inactive');
        app(LoginSecurityService::class)->recordLockout($inactive->user);
        app(LoginSecurityService::class)->recordLockout($inactive->user);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_success_between_lockouts_does_not_erase_the_24_hour_alert_history(): void
    {
        $this->freezeTime();
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $child = $this->member($group);
        $this->failFiveTimes($child->user);
        $this->travel(10)->minutes();
        $this->postJson('/v1/auth/login', ['login' => $child->user->username, 'password' => 'password'])->assertOk();
        $this->failFiveTimes($child->user);
        $this->assertSame(1, $parent->user->unreadNotifications()->count());
    }

    private function member(Group $group, string $role = 'member', string $status = 'active'): GroupMember
    {
        return GroupMember::query()->create(['group_id' => $group->id, 'user_id' => User::factory()->create()->id, 'role' => $role, 'status' => $status])->load('user');
    }
}
