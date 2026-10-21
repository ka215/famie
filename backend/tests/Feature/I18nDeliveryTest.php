<?php

namespace Tests\Feature;

use App\Mail\AccountMail;
use App\Models\GroupMember;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class I18nDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.ip_restriction_enabled' => false, 'mail.default' => 'array', 'famie.frontend_url' => 'https://famie.example.test', 'famie.mail_restrict_recipients' => false, 'famie.mail_allowed_recipients' => []]);
    }

    #[TestWith(['ja', 'verify'])]
    #[TestWith(['en', 'verify'])]
    #[TestWith(['ja', 'reset'])]
    #[TestWith(['en', 'reset'])]
    #[TestWith(['ja', 'email_changed'])]
    #[TestWith(['en', 'email_changed'])]
    #[TestWith(['ja', 'reset_complete'])]
    #[TestWith(['en', 'reset_complete'])]
    public function test_mail_renders_both_parts_in_its_frozen_language_and_restores_global_locale(string $locale, string $kind): void
    {
        app()->setLocale($locale === 'en' ? 'ja' : 'en');
        $previous = app()->getLocale();
        $url = in_array($kind, ['verify', 'reset'], true) ? 'https://famie.example.test/verify-email#id=1&token=secret' : null;
        $mail = unserialize(serialize(new AccountMail($kind, $locale, $url, ['username' => 'Original_User', 'minutes' => $kind === 'verify' ? 60 : 30])));
        $mail->assertSeeInHtml('lang="'.$locale.'"', false);
        $mail->assertSeeInText($locale === 'ja' ? '無断転載を禁じます。' : 'All rights reserved.');
        $mail->assertSeeInHtml($locale === 'ja' ? '無断転載を禁じます。' : 'All rights reserved.');
        $mail->assertSeeInText(__($url ? 'mail.ignore_link' : 'mail.unrecognized_action', [], $locale));
        $this->assertSame('Famie: '.__('mail.'.$kind.'.title', [], $locale), $mail->envelope()->subject);
        if ($url) {
            $mail->assertSeeInHtml($url);
            $mail->assertSeeInText($url);
            $mail->assertSeeInText($kind === 'verify' ? '60' : '30');
        }
        $this->assertSame($previous, app()->getLocale());
    }

    #[TestWith(['ja', 'en', 'ja'])]
    #[TestWith(['en', 'ja', 'en'])]
    #[TestWith([null, 'ja', 'ja'])]
    #[TestWith([null, 'en', 'en'])]
    public function test_confirmation_captures_request_language_even_if_preferences_change_before_deferred_delivery(?string $fixed, string $header, string $expected): void
    {
        Mail::fake();
        $this->withoutMiddleware(InvokeDeferredCallbacks::class);
        $member = GroupMember::factory()->create();
        $user = $member->user;
        $user->forceFill(['ui_locale' => $fixed, 'email' => null])->save();
        Sanctum::actingAs($user);
        $this->withHeader('Accept-Language', $header)->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertOk();
        Mail::assertNothingSent();
        $user->forceFill(['ui_locale' => $expected === 'en' ? 'ja' : 'en'])->save();
        app()->setLocale($expected === 'en' ? 'ja' : 'en');
        app(DeferredCallbackCollection::class)->invoke();
        Mail::assertSent(AccountMail::class, fn ($mail) => $mail->kind === 'verify' && $mail->uiLocale === $expected);
    }

    #[TestWith(['ja', 'en', 'ja'])]
    #[TestWith(['en', 'ja', 'en'])]
    #[TestWith([null, 'ja', 'ja'])]
    #[TestWith([null, 'en', 'en'])]
    public function test_public_reset_response_is_request_language_but_mail_prefers_recipient_language(?string $fixed, string $header, string $expected): void
    {
        Mail::fake();
        $this->withoutMiddleware(InvokeDeferredCallbacks::class);
        $user = GroupMember::factory()->create()->user;
        $user->forceFill(['ui_locale' => $fixed, 'email_verified_at' => now()])->save();
        $known = $this->withHeader('Accept-Language', $header)->postJson('/v1/auth/password/forgot', ['email' => $user->email])->assertOk()->assertHeader('Content-Language', $header);
        $unknown = $this->postJson('/v1/auth/password/forgot', ['email' => 'missing@example.test'])->assertOk()->assertHeader('Content-Language', $header);
        $this->assertSame($known->json(), $unknown->json());
        app()->setLocale($expected === 'en' ? 'ja' : 'en');
        app(DeferredCallbackCollection::class)->invoke();
        Mail::assertSent(AccountMail::class, fn ($mail) => $mail->kind === 'reset' && $mail->uiLocale === $expected);
        Mail::assertSentCount(1);
    }

    #[TestWith(['ja'])]
    #[TestWith(['en'])]
    public function test_old_address_and_reset_completion_notifications_follow_the_recipient_preference(string $locale): void
    {
        Mail::fake();
        $user = GroupMember::factory()->create()->user;
        $user->forceFill(['ui_locale' => $locale, 'email_verified_at' => now()])->save();
        Sanctum::actingAs($user);
        $this->withHeader('Accept-Language', $locale === 'en' ? 'ja' : 'en')->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertOk();
        $mail = Mail::sent(AccountMail::class)->first();
        parse_str(parse_url($mail->actionUrl, PHP_URL_FRAGMENT), $data);
        $oldEmail = $user->email;
        $this->postJson('/v1/auth/email/verify', $data)->assertOk();
        Mail::assertSent(AccountMail::class, fn ($mail) => $mail->kind === 'email_changed' && $mail->uiLocale === $locale && $mail->hasTo($oldEmail));
        $token = Password::createToken($user->fresh());
        $this->postJson('/v1/auth/password/reset', ['id' => $user->id, 'token' => $token, 'password' => 'river otter orchard sunset', 'password_confirmation' => 'river otter orchard sunset'])->assertOk();
        Mail::assertSent(AccountMail::class, fn ($mail) => $mail->kind === 'reset_complete' && $mail->uiLocale === $locale && $mail->hasTo('next@example.test'));
    }
}
