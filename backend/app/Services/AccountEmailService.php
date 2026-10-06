<?php

namespace App\Services;

use App\Mail\AccountMail;
use App\Models\Group;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountEmailService
{
    public const INVALID_LINK = 'リンクが無効か、有効期限が切れています。もう一度申請してください。';

    public function withActiveUser(User $user, Closure $callback, bool $public = false): mixed
    {
        return DB::transaction(function () use ($user, $callback, $public): mixed {
            $membership = $user->membership()->first();
            abort_unless($membership, $public ? 422 : 403, $public ? self::INVALID_LINK : 'このアカウントは現在利用できません。');
            Group::query()->whereKey($membership->group_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->activeMembership()->exists(), $public ? 422 : 403, $public ? self::INVALID_LINK : 'このアカウントは現在利用できません。');

            return $callback(User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail());
        });
    }

    public function clearChallenges(User $user): void
    {
        if ($user->email) {
            Password::deleteToken($user);
        }
        $user->forceFill(['pending_email' => null, 'email_verification_token' => null, 'email_verification_expires_at' => null])->save();
    }

    public function ensureAvailable(string $email, User $user): void
    {
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => ['このメールアドレスは使用できません。']]);
        }
    }

    public function consumeDelivery(string $email, ?int $userId = null): bool
    {
        $keys = ['account-mail:address:'.hash('sha256', $email)];
        if ($userId !== null) {
            $keys[] = 'account-mail:user:'.$userId;
        }
        foreach ($keys as $key) {
            if (! Cache::add($key, true, 60)) {
                return false;
            }
        }

        return true;
    }

    public function link(string $path, array $parameters): string
    {
        $base = rtrim((string) config('famie.frontend_url'), '/');
        if (! filter_var($base, FILTER_VALIDATE_URL) || ! in_array(parse_url($base, PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new \LogicException('FAMIE_FRONTEND_URL must be an HTTP(S) URL.');
        }

        return $base.'/'.$path.'#'.http_build_query($parameters);
    }

    public function deliver(string $email, AccountMail $mail): bool
    {
        $allowed = config('famie.mail_allowed_recipients');
        if ((config('famie.mail_restrict_recipients', true) || $allowed !== []) && ! in_array(strtolower($email), $allowed, true)) {
            Log::warning('Account mail recipient blocked by allowlist.');

            return false;
        }
        // logドライバは秘密リンクを記録するため、この用途では使用しない。
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (in_array($transport, ['log', 'failover', 'roundrobin'], true)) {
            Log::warning('Account mail requires a non-logging mailer.');

            return false;
        }
        try {
            Mail::to($email)->send($mail);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Account mail delivery failed.', ['exception_type' => $exception::class]);

            return false;
        }
    }
}
