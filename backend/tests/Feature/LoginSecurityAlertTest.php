<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Notifications\RepeatedLoginLockout;
use App\Services\LoginSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

    public function test_parent_without_unread_alerts_receives_empty_list_and_zero_totals(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        Sanctum::actingAs($parent->user);

        $this->getJson("/v1/groups/{$group->id}/security-alerts")->assertOk()->assertExactJson([
            'data' => [],
            'meta' => ['unread_total' => 0, 'unread_by_member' => [], 'limit' => 20, 'has_more' => false],
        ]);
    }

    public function test_member_filter_preserves_all_member_counts_beyond_twenty_and_reading_is_parent_specific(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $otherParent = $this->member($group, 'admin');
        $older = $this->member($group, 'member', 'inactive');
        $recent = $this->member($group);
        $foreign = $this->member(Group::factory()->create());
        foreach ([$parent, $otherParent] as $recipient) {
            foreach ([$older, ...array_fill(0, 21, $recent), $foreign] as $index => $target) {
                $notification = new RepeatedLoginLockout([
                    'group_id' => $target->group_id, 'membership_id' => $target->id,
                    'display_name' => '同名メンバー', 'username' => $target->user->username,
                    'occurred_at' => now()->toIso8601String(),
                ]);
                $notification->id = (string) Str::uuid();
                $recipient->user->notify($notification);
                $recipient->user->notifications()->findOrFail($notification->id)->update(['created_at' => now()->addSeconds($index)]);
            }
        }
        Sanctum::actingAs($parent->user);
        $base = "/v1/groups/{$group->id}/security-alerts";
        $this->getJson($base)->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.unread_total', 22)->assertJsonPath('meta.has_more', true)
            ->assertJsonFragment(['membership_id' => $older->id, 'count' => 1])
            ->assertJsonMissing(['membership_id' => $foreign->id]);
        $filtered = $this->getJson($base.'?membership_id='.$older->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_total', 1)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonFragment(['membership_id' => $recent->id, 'count' => 21]);
        $this->putJson($base.'/'.$filtered->json('data.0.id').'/read')->assertOk();
        $this->getJson($base.'?membership_id='.$older->id)->assertOk()->assertJsonPath('meta.unread_total', 0);
        $recentPage = $this->getJson($base.'?membership_id='.$recent->id)->assertOk()
            ->assertJsonCount(20, 'data')->assertJsonPath('meta.unread_total', 21);
        $readId = $recentPage->json('data.0.id');
        $this->putJson($base.'/'.$readId.'/read')->assertOk();
        $this->getJson($base.'?membership_id='.$recent->id)->assertOk()
            ->assertJsonCount(20, 'data')->assertJsonPath('meta.unread_total', 20)
            ->assertJsonPath('meta.has_more', false)->assertJsonMissing(['id' => $readId]);
        Sanctum::actingAs($otherParent->user);
        $this->getJson($base.'?membership_id='.$older->id)->assertOk()->assertJsonPath('meta.unread_total', 1);
    }

    public function test_member_filter_rejects_invalid_and_foreign_members_without_disclosing_alerts(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $foreign = $this->member(Group::factory()->create());
        Sanctum::actingAs($parent->user);
        $base = "/v1/groups/{$group->id}/security-alerts";
        foreach (['0', '-1', 'abc', '1.5', '', '1&membership_id[]=2'] as $invalid) {
            $this->getJson($base.'?membership_id='.$invalid)->assertUnprocessable()->assertJsonValidationErrors('membership_id');
        }
        $this->getJson($base.'?membership_id='.$foreign->id)->assertNotFound();
        $this->getJson($base.'?membership_id=99999999')->assertNotFound();
        Sanctum::actingAs($this->member($group)->user);
        $this->getJson($base.'?membership_id='.$parent->id)->assertForbidden();
    }

    private function member(Group $group, string $role = 'member', string $status = 'active'): GroupMember
    {
        return GroupMember::query()->create(['group_id' => $group->id, 'user_id' => User::factory()->create()->id, 'role' => $role, 'status' => $status])->load('user');
    }
}
