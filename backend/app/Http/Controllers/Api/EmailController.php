<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountMail;
use App\Models\User;
use App\Services\AccountEmailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailController extends Controller
{
    public function __construct(private AccountEmailService $emails) {}

    public function store(Request $request): JsonResponse
    {
        abort_if(array_diff(array_keys($request->all()), ['email', 'current_password']), 422, 'メールアドレスと現在のパスワードのみ指定できます。');
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'current_password' => ['required', 'string'],
        ], ['email.*' => '有効なメールアドレスを255文字以内で入力してください。', 'current_password.*' => '現在のパスワードを入力してください。']);
        $email = strtolower(trim($validated['email']));
        $mail = $this->emails->withActiveUser($request->user(), function (User $user) use ($validated, $email): AccountMail {
            if (! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['現在のパスワードが正しくありません。']]);
            }
            abort_if($user->email_verified_at && strtolower((string) $user->email) === $email, 422, 'このメールアドレスは確認済みです。');
            $this->emails->ensureAvailable($email, $user);

            return $this->prepareMail($user, $email);
        });

        return $this->send($email, $mail);
    }

    public function resend(Request $request): JsonResponse
    {
        [$email, $mail] = $this->emails->withActiveUser($request->user(), function (User $user): array {
            abort_unless($user->pending_email, 422, '確認待ちの申請がありません。');
            $this->emails->ensureAvailable($user->pending_email, $user);

            return [$user->pending_email, $this->prepareMail($user, $user->pending_email)];
        });

        return $this->send($email, $mail);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->emails->withActiveUser($request->user(), function (User $user): void {
            $user->forceFill(['pending_email' => null, 'email_verification_token' => null, 'email_verification_expires_at' => null])->save();
        });

        return response()->json(['message' => '確認待ちの申請を取り消しました。']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer'], 'token' => ['required', 'string', 'size:64']]);
        $user = User::query()->find($data['id']);
        abort_unless($user, 422, AccountEmailService::INVALID_LINK);
        try {
            $oldEmail = $this->emails->withActiveUser($user, function (User $user) use ($data): ?string {
                abort_unless($user->pending_email && $user->email_verification_expires_at?->isFuture()
                    && hash_equals((string) $user->email_verification_token, hash('sha256', $data['token'])), 422, AccountEmailService::INVALID_LINK);
                $this->emails->ensureAvailable($user->pending_email, $user);
                $oldEmail = $user->email_verified_at ? $user->email : null;
                $email = $user->pending_email;
                $this->emails->clearChallenges($user);
                $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();

                return $oldEmail;
            }, true);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['このメールアドレスは使用できません。別のアドレスで申請してください。']]);
        }
        if ($oldEmail) {
            $this->emails->deliver($oldEmail, new AccountMail('メールアドレス変更のお知らせ', 'アカウントのメールアドレスが変更されました。'));
        }

        return response()->json(['message' => 'メールアドレスを確認しました。']);
    }

    private function prepareMail(User $user, string $email): AccountMail
    {
        abort_unless($this->emails->consumeDelivery($email, $user->id), 429, '送信間隔が短すぎます。60秒待ってから再試行してください。');
        $token = Str::random(64);
        $url = $this->emails->link('verify-email', ['id' => $user->id, 'token' => $token]);
        $user->forceFill(['pending_email' => $email, 'email_verification_token' => hash('sha256', $token), 'email_verification_expires_at' => now()->addMinutes(60)])->save();

        return new AccountMail('メールアドレスを確認', 'リンク先の確認ボタンを押して登録を完了してください。有効期限は60分です。', $url);
    }

    private function send(string $email, AccountMail $mail): JsonResponse
    {
        if (! $this->emails->deliver($email, $mail)) {
            return response()->json(['message' => '確認メールを送信できませんでした。申請は保存されています。60秒後に再送してください。'], 503);
        }

        return response()->json(['message' => '確認メールを送信しました。メール内のリンクから確認してください。']);
    }
}
