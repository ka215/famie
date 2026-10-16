<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\EmailController;
use App\Mail\AccountMail;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\AccountEmailService;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EmailPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.ip_restriction_enabled' => false, 'mail.default' => 'array', 'famie.frontend_url' => 'https://famie.example.test', 'famie.mail_restrict_recipients' => false, 'famie.mail_allowed_recipients' => []]);
        Cache::flush();
    }

    public function test_verified_pending_and_unused_addresses_have_identical_receipts_and_pending_state(): void
    {
        $this->freezeTime();
        Mail::fake();
        $owner = $this->member(['email' => 'owned@example.test', 'email_verified_at' => now()]);
        $waiting = $this->member(['email' => null, 'pending_email' => 'waiting@example.test', 'email_verification_token' => 'original', 'email_verification_expires_at' => now()->addHour()]);
        $ownerBefore = $owner->fresh()->getAttributes();
        $waitingBefore = $waiting->fresh()->getAttributes();
        foreach (['owned@example.test', 'waiting@example.test', 'unused@example.test'] as $email) {
            $actor = $this->member(['email' => null]);
            Sanctum::actingAs($actor);
            $this->postJson('/v1/auth/me/email', ['email' => $email, 'current_password' => 'password'])
                ->assertOk()->assertExactJson(['message' => EmailController::ACCEPTED]);
            $this->getJson('/v1/auth/me')->assertOk()->assertJsonPath('user.pending_email', $email)
                ->assertJsonMissingPath('user.email_verification_token');
            $this->assertNull($actor->fresh()->email);
            $this->assertSame(now()->addHour()->toIso8601String(), $actor->fresh()->email_verification_expires_at->toIso8601String());
            $this->postJson('/v1/auth/me/email/resend')->assertTooManyRequests();
            $this->travel(61)->seconds();
            $this->postJson('/v1/auth/me/email/resend')->assertOk()->assertExactJson(['message' => EmailController::ACCEPTED]);
            $this->deleteJson('/v1/auth/me/email')->assertOk();
            $this->assertNull($actor->fresh()->pending_email);
        }
        $this->assertSame($ownerBefore, $owner->fresh()->getAttributes());
        $this->assertSame($waitingBefore, $waiting->fresh()->getAttributes());
        Mail::assertNotSent(AccountMail::class, fn (AccountMail $mail) => $mail->hasTo('owned@example.test'));
        Mail::assertSentCount(4);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_pending_addresses_are_not_reserved_and_first_confirmation_wins(bool $secondFirst): void
    {
        $this->freezeTime();
        Mail::fake();
        $first = $this->member(['email' => null]);
        $second = $this->member(['email' => null]);
        Sanctum::actingAs($first);
        $this->postJson('/v1/auth/me/email', ['email' => 'shared@example.test', 'current_password' => 'password'])->assertOk();
        $firstLink = $this->lastLink();
        $firstHash = $first->fresh()->email_verification_token;
        $this->travel(61)->seconds();
        Sanctum::actingAs($second);
        $this->postJson('/v1/auth/me/email', ['email' => 'SHARED@example.test', 'current_password' => 'password'])->assertOk();
        $secondLink = $this->lastLink();
        $this->assertSame($firstHash, $first->fresh()->email_verification_token);
        $winner = $secondFirst ? $second : $first;
        $loser = $secondFirst ? $first : $second;
        $this->postJson('/v1/auth/email/verify', $secondFirst ? $secondLink : $firstLink)->assertOk();
        $this->postJson('/v1/auth/email/verify', $secondFirst ? $firstLink : $secondLink)->assertUnprocessable()
            ->assertJsonPath('message', AccountEmailService::INVALID_LINK);
        $this->assertSame('shared@example.test', $winner->fresh()->email);
        $this->assertNotNull($winner->fresh()->email_verified_at);
        $this->assertNull($loser->fresh()->email);
        $this->assertSame(1, User::query()->where('email', 'shared@example.test')->count());
        Mail::assertSentCount(2);
    }

    public function test_address_delivery_suppression_is_not_exposed_to_another_requester(): void
    {
        Mail::fake();
        foreach ([1, 2] as $index) {
            $actor = $this->member(['email' => null]);
            Sanctum::actingAs($actor);
            $this->postJson('/v1/auth/me/email', ['email' => 'shared@example.test', 'current_password' => 'password'])
                ->assertOk()->assertExactJson(['message' => EmailController::ACCEPTED]);
            $this->assertSame('shared@example.test', $actor->fresh()->pending_email);
        }
        Mail::assertSentCount(1);
    }

    #[TestWith(['cancel'])]
    #[TestWith(['inactive'])]
    #[TestWith(['password'])]
    #[TestWith(['occupied'])]
    #[TestWith(['expired'])]
    public function test_deferred_delivery_rechecks_changes_after_receipt(string $change): void
    {
        Mail::fake();
        $this->withoutMiddleware(InvokeDeferredCallbacks::class);
        $actor = $this->member(['email' => null]);
        Sanctum::actingAs($actor);
        $receipt = $this->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])
            ->assertOk()->assertExactJson(['message' => EmailController::ACCEPTED]);
        $receipt->assertHeader('Content-Length', (string) strlen($receipt->getContent()));
        Mail::assertNothingSent();
        if ($change === 'cancel') {
            $this->deleteJson('/v1/auth/me/email')->assertOk();
        } elseif ($change === 'inactive') {
            $actor->membership()->update(['status' => 'inactive']);
        } elseif ($change === 'password') {
            $this->putJson('/v1/auth/me/password', ['current_password' => 'password', 'new_password' => 'river otter orchard sunset', 'new_password_confirmation' => 'river otter orchard sunset'])->assertOk();
        } elseif ($change === 'occupied') {
            $this->member(['email' => 'next@example.test', 'email_verified_at' => now()]);
        } else {
            $this->travel(61)->minutes();
        }
        app(DeferredCallbackCollection::class)->invoke();
        Mail::assertNothingSent();
    }

    public function test_delivery_failure_has_the_same_receipt_and_mail_identifies_the_applicant(): void
    {
        Mail::fake();
        $actor = $this->member(['email' => null]);
        Sanctum::actingAs($actor);
        $this->postJson('/v1/auth/me/email', ['email' => 'next@example.test', 'current_password' => 'password'])->assertOk();
        $mail = Mail::sent(AccountMail::class)->first();
        $mail->assertSeeInText($actor->username);
        $mail->assertSeeInText('ご自身がこのアカウントで申請した場合のみ');
        $this->travel(61)->seconds();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP failed'));
        $this->postJson('/v1/auth/me/email/resend')->assertOk()->assertExactJson(['message' => EmailController::ACCEPTED]);
        $this->assertSame('next@example.test', $actor->fresh()->pending_email);
    }

    private function member(array $attributes): User
    {
        $user = User::factory()->create($attributes);
        GroupMember::query()->create(['group_id' => Group::factory()->create()->id, 'user_id' => $user->id, 'role' => 'member', 'status' => 'active']);

        return $user;
    }

    private function lastLink(): array
    {
        $mail = Mail::sent(AccountMail::class)->last();
        parse_str(parse_url($mail->actionUrl, PHP_URL_FRAGMENT), $data);

        return $data;
    }
}
