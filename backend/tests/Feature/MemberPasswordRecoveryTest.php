<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.ip_restriction_enabled' => false]);
        Cache::flush();
    }

    public function test_parent_reissues_password_without_email_and_clears_old_account_cooldown(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $child = $this->member($group);
        $child->user->forceFill(['email' => null])->save();
        $child->user->createToken('old-session');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/login', ['login' => $child->user->username, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/v1/auth/login', ['login' => $child->user->username, 'password' => 'password'])->assertTooManyRequests();
        Sanctum::actingAs($parent->user);
        $this->putJson($this->path($child), $this->payload())->assertOk()->assertJsonMissingPath('password');
        $this->assertTrue(Hash::check('river otter orchard sunset', $child->user->fresh()->password));
        $this->assertFalse($child->user->fresh()->password_is_weak);
        $this->assertSame(0, $child->user->tokens()->count());
        $this->postJson('/v1/auth/login', ['login' => $child->user->username, 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/v1/auth/login', ['login' => $child->user->username, 'password' => 'river otter orchard sunset'])->assertOk();
    }

    public function test_reissue_invalidates_reset_links_and_pending_email_challenges_but_keeps_parent_session(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $child = $this->member($group, 'admin');
        $parent->user->createToken('parent-session');
        $child->user->forceFill(['pending_email' => 'pending@example.test', 'email_verification_token' => 'old-challenge'])->save();
        $resetToken = Password::createToken($child->user);
        Sanctum::actingAs($parent->user);
        $this->putJson($this->path($child), $this->payload())->assertOk();
        $this->assertFalse(Password::tokenExists($child->user->fresh(), $resetToken));
        $this->assertNull($child->user->fresh()->email_verification_token);
        $this->assertNull($child->user->fresh()->pending_email);
        $this->assertSame(1, $parent->user->tokens()->count());
    }

    public function test_reissue_requires_parent_password_and_strong_confirmed_replacement(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $child = $this->member($group);
        $original = $child->user->password;
        Sanctum::actingAs($parent->user);
        $this->putJson($this->path($child), [...$this->payload(), 'current_password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson($this->path($child), [...$this->payload(), 'new_password' => 'aaaaaaaaaaaaaaa', 'new_password_confirmation' => 'aaaaaaaaaaaaaaa'])->assertUnprocessable()->assertJsonValidationErrors('new_password');
        $this->putJson($this->path($child), [...$this->payload(), 'new_password_confirmation' => 'different'])->assertUnprocessable()->assertJsonValidationErrors('new_password');
        $this->putJson($this->path($child), [...$this->payload(), 'user_id' => $parent->user_id])->assertUnprocessable();
        $this->assertSame($original, $child->user->fresh()->password);
    }

    public function test_self_inactive_other_family_and_non_parent_reissue_are_rejected(): void
    {
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $child = $this->member($group);
        $foreign = $this->member(Group::factory()->create());
        Sanctum::actingAs($parent->user);
        $this->putJson($this->path($parent), $this->payload())->assertUnprocessable();
        $this->putJson('/v1/groups/'.$group->id.'/members/'.$foreign->id.'/password', $this->payload())->assertNotFound();
        $child->update(['status' => 'inactive']);
        $this->putJson($this->path($child), $this->payload())->assertUnprocessable();
        $child->update(['status' => 'active']);
        Sanctum::actingAs($child->user);
        $this->putJson($this->path($parent), $this->payload())->assertForbidden();
    }

    public function test_reissue_requires_authentication_and_limits_parent_password_guessing(): void
    {
        $this->freezeTime();
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $child = $this->member($group);
        $this->putJson($this->path($child), $this->payload())->assertUnauthorized();
        Sanctum::actingAs($parent->user);
        $this->postJson('/v1/auth/me/password-warning/snooze')->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->putJson($this->path($child), [...$this->payload(), 'current_password' => 'wrong'])->assertUnprocessable();
        }
        $this->putJson($this->path($child), $this->payload())->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.33'])
            ->putJson($this->path($child), $this->payload())->assertTooManyRequests();
        $this->travel(539)->seconds();
        $this->putJson($this->path($child), $this->payload())->assertOk();
    }

    private function member(Group $group, string $role = 'member'): GroupMember
    {
        return GroupMember::query()->create(['group_id' => $group->id, 'user_id' => User::factory()->create()->id, 'role' => $role, 'status' => 'active'])->load('user');
    }

    public function test_reissue_does_not_bypass_the_shared_ip_request_limit(): void
    {
        config(['famie.login_ip_per_minute' => 5]);
        $group = Group::factory()->create();
        $parent = $this->member($group, 'admin');
        $child = $this->member($group);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/login', ['login' => $child->user->username, 'password' => 'wrong'])->assertUnprocessable();
        }
        Sanctum::actingAs($parent->user);
        $this->putJson($this->path($child), $this->payload())->assertOk();
        $this->postJson('/v1/auth/login', ['login' => $child->user->username, 'password' => 'river otter orchard sunset'])->assertTooManyRequests();
    }

    private function path(GroupMember $member): string
    {
        return '/v1/groups/'.$member->group_id.'/members/'.$member->id.'/password';
    }

    private function payload(): array
    {
        return ['current_password' => 'password', 'new_password' => 'river otter orchard sunset', 'new_password_confirmation' => 'river otter orchard sunset'];
    }
}
