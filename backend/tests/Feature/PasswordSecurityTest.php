<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\PasswordStrength;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1']]);
    }

    #[TestWith(['short123'])]
    #[TestWith(['river otter ab'])]
    #[TestWith(["river\notter orchard"])]
    #[TestWith(['aaaaaaaaaaaaaaa'])]
    #[TestWith(['abcabcabcabcabc'])]
    #[TestWith(['password12345678'])]
    #[TestWith(['0123456789012345'])]
    #[TestWith(['静かな庭で毎朝家族とお茶を飲んでから今日の予定を話す'])]
    public function test_registration_rejects_weak_or_overlong_passwords_with_a_japanese_reason(string $password): void
    {
        $this->postJson('/v1/auth/register', [
            'group_name' => '家族', 'username' => 'new-parent', 'display_name' => '親',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertUnprocessable()->assertJsonValidationErrors('password')
            ->assertJsonPath('errors.password.0', PasswordStrength::MESSAGE);
        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith(['river otter orchard'])]
    #[TestWith(['river otter abc'])]
    #[TestWith(['静かな庭で家族と毎朝お茶を飲みます'])]
    public function test_registration_accepts_long_phrases_without_mandatory_character_classes(string $password): void
    {
        $this->postJson('/v1/auth/register', [
            'group_name' => '家族', 'username' => 'new-parent', 'display_name' => '親',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertCreated()->assertJsonPath('user.password_security.status', 'ok');
        $user = User::query()->where('username', 'new-parent')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse($user->password_is_weak);
    }

    public function test_legacy_passwords_can_login_and_are_marked_weak_without_exposing_the_hash(): void
    {
        $user = $this->activeUser();
        $hash = $user->password;

        $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.password_security.status', 'weak')->assertJsonMissingPath('user.password');
        $this->assertTrue($user->fresh()->password_is_weak);
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_existing_session_reports_unknown_instead_of_guessing_strength_from_a_hash(): void
    {
        $user = $this->activeUser();
        Sanctum::actingAs($user);

        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('user.password_security.status', 'unknown');
    }

    public function test_snooze_expires_at_next_midnight_in_japan_without_changing_another_user(): void
    {
        $this->travelTo(now()->parse('2026-10-16T14:30:00Z'));
        $user = $this->activeUser();
        $other = $this->activeUser();
        Sanctum::actingAs($user);

        $this->postJson('/v1/auth/me/password-warning/snooze')->assertOk()
            ->assertJsonPath('user.password_security.snoozed_until', '2026-10-16T15:00:00+00:00');
        $this->assertSame('2026-10-16T15:00:00+00:00', $user->fresh()->password_warning_snoozed_until->toIso8601String());
        $this->assertNull($other->fresh()->password_warning_snoozed_until);
    }

    public function test_snooze_rejects_client_controlled_expiry_and_user_ids(): void
    {
        $user = $this->activeUser();
        Sanctum::actingAs($user);

        $this->postJson('/v1/auth/me/password-warning/snooze', ['user_id' => $user->id, 'snoozed_until' => '2099-01-01'])->assertUnprocessable();
        $this->assertNull($user->fresh()->password_warning_snoozed_until);
    }

    public function test_unauthenticated_clients_cannot_snooze_or_change_passwords(): void
    {
        $this->postJson('/v1/auth/me/password-warning/snooze')->assertUnauthorized();
        $this->putJson('/v1/auth/me/password', ['new_password' => 'river otter orchard'])->assertUnauthorized();
    }

    public function test_password_change_requires_the_current_password_and_rejects_weak_replacements(): void
    {
        $user = $this->activeUser();
        $hash = $user->password;
        Sanctum::actingAs($user);

        $this->putJson('/v1/auth/me/password', ['current_password' => 'incorrect', 'new_password' => 'river otter orchard', 'new_password_confirmation' => 'river otter orchard'])->assertUnprocessable();
        $this->putJson('/v1/auth/me/password', ['current_password' => 'password', 'new_password' => 'short123', 'new_password_confirmation' => 'short123'])->assertUnprocessable()->assertJsonValidationErrors('new_password');
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_inactive_members_cannot_snooze_or_change_passwords(): void
    {
        $user = $this->activeUser();
        $hash = $user->password;
        $user->activeMembership()->update(['status' => 'inactive']);
        Sanctum::actingAs($user);

        $this->postJson('/v1/auth/me/password-warning/snooze')->assertForbidden();
        $this->putJson('/v1/auth/me/password', ['current_password' => 'password', 'new_password' => 'river otter orchard', 'new_password_confirmation' => 'river otter orchard'])->assertForbidden();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertNull($user->fresh()->password_warning_snoozed_until);
    }

    public function test_password_change_clears_warning_preserves_spaces_and_revokes_other_tokens(): void
    {
        $user = $this->activeUser();
        $user->forceFill(['password_is_weak' => true, 'password_warning_snoozed_until' => now()->addDay()])->save();
        $current = $user->createToken('current');
        $other = $user->createToken('other');
        $password = ' river otter orchard ';

        $this->withToken($current->plainTextToken)->putJson('/v1/auth/me/password', [
            'current_password' => 'password', 'new_password' => $password, 'new_password_confirmation' => $password,
        ])->assertOk();
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertFalse(Hash::check(trim($password), $user->fresh()->password));
        $this->assertFalse($user->fresh()->password_is_weak);
        $this->assertNull($user->fresh()->password_warning_snoozed_until);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_admin_cannot_create_a_member_with_a_weak_password(): void
    {
        $admin = $this->activeUser();
        Sanctum::actingAs($admin);
        $group = $admin->activeMembership->group_id;

        $this->postJson("/v1/groups/{$group}/members", [
            'username' => 'new-child', 'display_name' => '子', 'role' => 'member',
            'password' => 'short123', 'password_confirmation' => 'short123',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['username' => 'new-child']);
    }

    private function activeUser(): User
    {
        $user = User::factory()->create();
        GroupMember::query()->create(['group_id' => Group::factory()->create()->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);

        return $user;
    }
}
