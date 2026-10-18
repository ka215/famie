<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountMail;
use App\Models\User;
use App\Services\AccountEmailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Illuminate\Support\defer;

class EmailController extends Controller
{
    public const ACCEPTED = 'messages.email_accepted';

    public function __construct(private AccountEmailService $emails) {}

    public function store(Request $request): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['email', 'current_password']), 422, __('messages.email_fields'));
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'current_password' => ['required', 'string'],
        ], ['email.*' => __('messages.email_invalid'), 'current_password.*' => __('messages.password_required')]);
        $email = strtolower(trim($validated['email']));
        $token = $this->emails->withActiveUser($request->user(), function (User $user) use ($validated, $email): string {
            if (! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => [__('messages.password_incorrect')]]);
            }
            abort_if($user->email_verified_at && strtolower((string) $user->email) === $email, 422, __('messages.email_verified'));

            return $this->prepareRequest($user, $email);
        });

        return $this->accept($request->user()->id, $token);
    }

    public function resend(Request $request): JsonResponse
    {
        $token = $this->emails->withActiveUser($request->user(), function (User $user): string {
            abort_unless($user->pending_email, 422, __('messages.email_no_pending'));

            return $this->prepareRequest($user, $user->pending_email);
        });

        return $this->accept($request->user()->id, $token);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->emails->withActiveUser($request->user(), function (User $user): void {
            $user->forceFill(['pending_email' => null, 'email_verification_token' => null, 'email_verification_expires_at' => null])->save();
        });

        return response()->json(['message' => __('messages.email_cancelled')]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer'], 'token' => ['required', 'string', 'size:64']]);
        $user = User::query()->find($data['id']);
        abort_unless($user, 422, __(AccountEmailService::INVALID_LINK));
        try {
            $oldEmail = $this->emails->withActiveUser($user, function (User $user) use ($data): ?string {
                abort_unless($user->pending_email && $user->email_verification_expires_at?->isFuture()
                    && hash_equals((string) $user->email_verification_token, hash('sha256', $data['token'])), 422, __(AccountEmailService::INVALID_LINK));
                abort_unless($this->emails->isAvailable($user->pending_email, $user), 422, __(AccountEmailService::INVALID_LINK));
                $oldEmail = $user->email_verified_at ? $user->email : null;
                $email = $user->pending_email;
                $this->emails->clearChallenges($user);
                $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();

                return $oldEmail;
            }, true);
        } catch (UniqueConstraintViolationException) {
            abort(422, __(AccountEmailService::INVALID_LINK));
        }
        if ($oldEmail) {
            $this->emails->deliver($oldEmail, new AccountMail('メールアドレス変更のお知らせ', 'アカウントのメールアドレスが変更されました。'));
        }

        return response()->json(['message' => __('messages.email_confirmed')]);
    }

    private function prepareRequest(User $user, string $email): string
    {
        abort_unless(Cache::add('email-verification:request:'.$user->id, true, 60), 429, __('messages.email_limited'));
        $token = Str::random(64);
        $user->forceFill(['pending_email' => $email, 'email_verification_token' => hash('sha256', $token), 'email_verification_expires_at' => now()->addMinutes(60)])->save();

        return $token;
    }

    private function accept(int $userId, string $token): JsonResponse
    {
        defer(fn () => $this->sendConfirmation($userId, $token));

        $response = response()->json(['message' => __(self::ACCEPTED)]);
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));

        return $response;
    }

    private function sendConfirmation(int $userId, string $token): void
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }
        try {
            $delivery = $this->emails->withActiveUser($user, function (User $user) use ($token): ?array {
                if (! $user->pending_email || ! $user->email_verification_expires_at?->isFuture()
                    || ! hash_equals((string) $user->email_verification_token, hash('sha256', $token))
                    || ! $this->emails->isAvailable($user->pending_email, $user)
                    || ! $this->emails->consumeDelivery($user->pending_email, $user->id)) {
                    return null;
                }
                $url = $this->emails->link('verify-email', ['id' => $user->id, 'token' => $token]);

                return [$user->pending_email, new AccountMail('メールアドレスを確認',
                    '申請元のログインID：'.$user->username.'。ご自身がこのアカウントで申請した場合のみ、リンク先の確認ボタンを押してください。有効期限は60分です。', $url)];
            }, true);
            if ($delivery) {
                $this->emails->deliver(...$delivery);
            }
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 422) {
                throw $exception;
            }
        }
    }
}
