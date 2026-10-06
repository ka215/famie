<?php

namespace Tests\Feature;

use App\Mail\AccountMail;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\GroupMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.allowed_ips' => ['127.0.0.1'], 'mail.default' => 'array', 'famie.frontend_url' => 'https://famie.example.test', 'famie.mail_allowed_recipients' => [], 'famie.mail_restrict_recipients' => false]);
    }

    #[TestWith(['admin'])]
    #[TestWith(['member'])]
    public function test_addition_requires_confirmation_and_link_is_single_use(string $role): void
    {
        Mail::fake();
        $user = $this->member(['email' => null], $role);
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => ' NEW@example.test ', 'current_password' => 'password'])->assertOk();
        $this->assertNull($user->fresh()->email);
        $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('user.pending_email', 'new@example.test')->assertJsonMissingPath('user.email_verification_token');
        $link = $this->linkData('メールアドレスを確認');
        $this->assertNotSame($link['token'], $user->fresh()->email_verification_token);
        $this->getJson('/v1/auth/email/verify')->assertMethodNotAllowed();
        $this->postJson('/v1/auth/email/verify', $link)->assertOk();
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->pending_email);
        $this->postJson('/v1/auth/email/verify', $link)->assertUnprocessable();
        Mail::assertSentCount(1);
    }

    public function test_existing_unverified_same_email_can_be_confirmed_and_login_is_preserved(): void
    {
        Mail::fake();
        $user = $this->member(['email' => 'legacy@example.test']);
        $this->postJson('/v1/auth/login', ['login' => 'legacy@example.test', 'password' => 'password'])->assertOk();
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => 'legacy@example.test', 'current_password' => 'password'])->assertOk();
        $this->postJson('/v1/auth/email/verify', $this->linkData('メールアドレスを確認'))->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        Mail::assertSentCount(1);
    }

    public function test_change_keeps_old_email_until_confirmed_and_invalidates_reset(): void
    {
        Mail::fake();
        $user = $this->member(['email' => 'old@example.test', 'email_verified_at' => now()]);
        $reset = Password::createToken($user);
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertOk();
        $this->assertSame('old@example.test', $user->fresh()->email);
        $this->postJson('/v1/auth/login', ['login' => 'next@example.test', 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/v1/auth/email/verify', $this->linkData('メールアドレスを確認'))->assertOk();
        $this->assertSame('next@example.test', $user->fresh()->email);
        $this->assertFalse(Password::tokenExists($user, $reset));
        Mail::assertSent(AccountMail::class, fn (AccountMail $mail) => $mail->hasTo('old@example.test') && $mail->title === 'メールアドレス変更のお知らせ');
    }

    public function test_resend_and_cancel_invalidate_old_links(): void
    {
        Mail::fake();
        $user = $this->member();
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertOk();
        $old = $this->linkData('メールアドレスを確認');
        $this->postJson('/v1/auth/me/email/resend')->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->postJson('/v1/auth/me/email/resend')->assertOk();
        $latest = $this->linkData('メールアドレスを確認');
        $this->postJson('/v1/auth/email/verify', $old)->assertUnprocessable();
        $this->deleteJson('/v1/auth/me/email')->assertOk();
        $this->postJson('/v1/auth/email/verify', $latest)->assertUnprocessable();
        $this->assertNull($user->fresh()->pending_email);
        Mail::assertSentCount(2);
    }

    public function test_confirmation_rechecks_duplicate_email_and_expiry(): void
    {
        Mail::fake();
        $user = $this->member();
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertOk();
        $link = $this->linkData('メールアドレスを確認');
        $other = $this->member(['email' => 'NEXT@example.test']);
        $this->postJson('/v1/auth/email/verify', $link)->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
        $other->update(['email' => null]);
        $this->travel(60)->minutes();
        $this->postJson('/v1/auth/email/verify', $link)->assertUnprocessable();
        Mail::assertSentCount(1);
    }

    #[TestWith(['current_password', 'incorrect'])]
    #[TestWith(['email', 'invalid'])]
    #[TestWith(['email', ''])]
    #[TestWith(['user_id', 999])]
    #[TestWith(['email_verified_at', '2026-10-05'])]
    public function test_invalid_email_requests_make_no_changes(string $key, mixed $value): void
    {
        Mail::fake();
        $user = $this->member();
        Sanctum::actingAs($user);
        $data = ['email' => 'next@example.test', 'current_password' => 'password'];
        $data[$key] = $value;
        $this->postJson('/v1/auth/me/email', $data)->assertUnprocessable();
        $this->assertNull($user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    public function test_guest_and_inactive_users_cannot_manage_email(): void
    {
        Mail::fake();
        $this->postJson('/v1/auth/me/email', [])->assertUnauthorized();
        $this->postJson('/v1/auth/me/email/resend')->assertUnauthorized();
        $this->deleteJson('/v1/auth/me/email')->assertUnauthorized();
        $user = $this->member();
        $user->membership()->update(['status' => 'inactive']);
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_only_verified_active_users_receive_reset_mail_with_uniform_response(): void
    {
        Mail::fake();
        $this->member(['email' => 'unverified@example.test']);
        $inactive = $this->member(['email' => 'inactive@example.test', 'email_verified_at' => now()]);
        $inactive->membership()->update(['status' => 'inactive']);
        $this->member(['email' => 'verified@example.test', 'email_verified_at' => now()]);
        $responses = [];
        foreach (['absent', 'unverified', 'inactive', 'verified'] as $name) {
            $responses[] = $this->postJson('/v1/auth/password/forgot', ['email' => $name.'@example.test'])->assertOk()->json();
        }
        $this->assertSame($responses[0], $responses[1]);
        $this->assertSame($responses[0], $responses[2]);
        $this->assertSame($responses[0], $responses[3]);
        Mail::assertSentCount(1);
        Mail::assertSent(AccountMail::class, fn (AccountMail $mail) => $mail->hasTo('verified@example.test'));
    }

    public function test_reset_revokes_sessions_and_pending_email_and_cannot_be_reused(): void
    {
        Mail::fake();
        $user = $this->member(['email' => 'verified@example.test', 'email_verified_at' => now(), 'pending_email' => 'pending@example.test']);
        $user->createToken('first');
        $user->createToken('second');
        $this->postJson('/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $data = $this->linkData('パスワードを再設定') + ['password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/v1/auth/password/reset', $data)->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($user->fresh()->pending_email);
        $this->postJson('/v1/auth/password/reset', $data)->assertUnprocessable();
        Mail::assertSent(AccountMail::class, fn (AccountMail $mail) => $mail->title === 'パスワード再設定完了');
        $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/v1/auth/login', ['login' => $user->username, 'password' => 'new-password'])->assertOk();
    }

    public function test_reset_resend_invalidates_old_token_and_expires_after_thirty_minutes(): void
    {
        Mail::fake();
        $user = $this->member(['email_verified_at' => now()]);
        $this->postJson('/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $old = $this->linkData('パスワードを再設定');
        $this->postJson('/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        Mail::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->postJson('/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $latest = $this->linkData('パスワードを再設定');
        $password = ['password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/v1/auth/password/reset', $old + $password)->assertUnprocessable();
        $this->travel(31)->minutes();
        $this->postJson('/v1/auth/password/reset', $latest + $password)->assertUnprocessable();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        Mail::assertSentCount(2);
    }

    public function test_normal_password_change_invalidates_both_challenges(): void
    {
        Mail::fake();
        $user = $this->member(['email_verified_at' => now(), 'pending_email' => 'next@example.test']);
        $token = Password::createToken($user);
        Sanctum::actingAs($user);
        $this->putJson('/v1/auth/me/password', ['current_password' => 'password', 'new_password' => 'new-password', 'new_password_confirmation' => 'new-password'])->assertOk();
        $this->assertFalse(Password::tokenExists($user, $token));
        $this->assertNull($user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    public function test_deactivation_cancels_both_challenges_even_after_restoration(): void
    {
        Mail::fake();
        $user = $this->member(['email_verified_at' => now()]);
        $admin = User::factory()->create();
        GroupMember::query()->create(['group_id' => $user->membership->group_id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertOk();
        $verify = $this->linkData('メールアドレスを確認');
        $reset = Password::createToken($user);
        app(GroupMembershipService::class)->changeStatus($admin, $user->membership, 'inactive');
        $this->postJson('/v1/auth/email/verify', $verify)->assertUnprocessable();
        app(GroupMembershipService::class)->changeStatus($admin, $user->membership, 'active');
        $this->postJson('/v1/auth/email/verify', $verify)->assertUnprocessable();
        $this->assertFalse(Password::tokenExists($user, $reset));
        Mail::assertSentCount(1);
    }

    public function test_delivery_allowlist_failure_keeps_pending_request_and_public_response(): void
    {
        Mail::fake();
        config(['famie.mail_allowed_recipients' => ['allowed@example.test']]);
        $user = $this->member(['email_verified_at' => now()]);
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => 'blocked@example.test', 'current_password' => 'password'])->assertServiceUnavailable();
        $this->assertSame('blocked@example.test', $user->fresh()->pending_email);
        $this->travel(61)->seconds();
        $this->postJson('/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        Mail::assertNothingSent();
    }

    public function test_mail_renders_fragment_link_without_query_token(): void
    {
        Mail::fake();
        $mail = new AccountMail('メールアドレスを確認', '確認してください。', 'https://famie.example.test/verify-email#id=1&token=secret');
        $mail->assertSeeInHtml('https://famie.example.test/verify-email#id=1');
        $mail->assertSeeInText('確認してください。');
        Mail::assertNothingSent();
    }

    public function test_delivery_exception_is_redacted_and_does_not_undo_password_reset(): void
    {
        Mail::fake();
        $user = $this->member(['email_verified_at' => now()]);
        $token = Password::createToken($user);
        Mail::shouldReceive('to')->once()->with($user->email)->andThrow(new \RuntimeException('secret-token-and-smtp-password'));
        Log::shouldReceive('warning')->once()->with('Account mail delivery failed.', ['exception_type' => \RuntimeException::class]);

        $this->postJson('/v1/auth/password/reset', ['id' => $user->id, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertFalse(Password::tokenExists($user, $token));
    }

    public function test_logging_transport_does_not_receive_secret_links(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $user = $this->member();
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertServiceUnavailable();
        Mail::assertNothingSent();
    }

    public function test_duplicate_verified_email_and_wrong_link_owner_are_rejected(): void
    {
        Mail::fake();
        $user = $this->member(['email_verified_at' => now()]);
        $other = $this->member(['email' => 'other@example.test']);
        Sanctum::actingAs($user);
        $this->postJson('/v1/auth/me/email', ['email' => $user->email, 'current_password' => 'password'])->assertUnprocessable();
        $this->postJson('/v1/auth/me/email', ['email' => 'OTHER@example.test', 'current_password' => 'password'])->assertUnprocessable();
        $this->postJson('/v1/auth/me/email', ['email' => 'new@example.test', 'current_password' => 'password'])->assertOk();
        $link = $this->linkData('メールアドレスを確認');
        $link['id'] = $other->id;
        $this->postJson('/v1/auth/email/verify', $link)->assertUnprocessable();
        $this->assertSame('other@example.test', $other->fresh()->email);
        Mail::assertSentCount(1);
    }

    #[TestWith(['short', 'short'])]
    #[TestWith(['new-password', 'different-password'])]
    public function test_invalid_new_password_does_not_consume_reset_link(string $password, string $confirmation): void
    {
        Mail::fake();
        $user = $this->member(['email_verified_at' => now()]);
        $token = Password::createToken($user);
        $this->postJson('/v1/auth/password/reset', ['id' => $user->id, 'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        Mail::assertNothingSent();
    }

    public function test_restricted_delivery_with_empty_allowlist_blocks_all_recipients(): void
    {
        Mail::fake();
        config(['famie.mail_restrict_recipients' => true]);
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertServiceUnavailable();

        $this->assertSame('next@example.test', $user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    public function test_restricted_delivery_accepts_only_allowed_recipient(): void
    {
        Mail::fake();
        config(['famie.mail_restrict_recipients' => true, 'famie.mail_allowed_recipients' => ['allowed@example.test']]);
        $user = $this->member();
        Sanctum::actingAs($user);

        $this->postJson('/v1/auth/me/email', ['email' => 'allowed@example.test', 'current_password' => 'password'])->assertOk();

        Mail::assertSent(AccountMail::class, fn (AccountMail $mail) => $mail->hasTo('allowed@example.test'));
    }

    private function member(array $attributes = [], string $role = 'member'): User
    {
        $user = User::factory()->create($attributes);
        GroupMember::query()->create(['group_id' => Group::factory()->create()->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return $user;
    }

    private function linkData(string $title): array
    {
        $mail = Mail::sent(AccountMail::class, fn (AccountMail $mail) => $mail->title === $title)->last();
        $this->assertNotNull($mail);
        parse_str(parse_url($mail->actionUrl, PHP_URL_FRAGMENT), $data);

        return $data;
    }
}
