<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountMail;
use App\Models\User;
use App\Services\AccountEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Illuminate\Support\defer;

class PasswordResetController extends Controller
{
    public function __construct(private AccountEmailService $emails) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']], ['email.*' => '有効なメールアドレスを255文字以内で入力してください。']);
        $email = strtolower(trim($data['email']));
        defer(fn () => $this->sendResetLink($email));

        return response()->json(['message' => '再設定が可能な場合、メールを送信しました。届かない場合は迷惑メールを確認し、60秒以上待って再試行してください。']);
    }

    private function sendResetLink(string $email): void
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($this->emails->consumeDelivery($email, $user?->id) && $user) {
            try {
                $mail = $this->emails->withActiveUser($user, function (User $locked) use ($email): ?AccountMail {
                    if (! $locked->email_verified_at || strtolower((string) $locked->email) !== $email) {
                        return null;
                    }
                    $token = Password::createToken($locked);

                    return new AccountMail('パスワードを再設定', 'リンク先で新しいパスワードを設定してください。有効期限は30分です。', $this->emails->link('reset-password', ['id' => $locked->id, 'token' => $token]));
                }, true);
                if ($mail) {
                    $this->emails->deliver($email, $mail);
                }
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 422) {
                    throw $exception;
                }
            }
        }
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'], 'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], ['password.*' => 'パスワードは6文字以上で、確認入力と一致させてください。']);
        $user = User::query()->find($data['id']);
        abort_unless($user, 422, AccountEmailService::INVALID_LINK);
        $email = $this->emails->withActiveUser($user, function (User $user) use ($data): string {
            abort_unless($user->email && $user->email_verified_at && Password::tokenExists($user, $data['token']), 422, AccountEmailService::INVALID_LINK);
            $this->emails->clearChallenges($user);
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();

            return $user->email;
        }, true);
        $this->emails->deliver($email, new AccountMail('パスワード再設定完了', 'パスワードを再設定しました。すべての端末で再ログインが必要です。'));

        return response()->json(['message' => 'パスワードを再設定しました。新しいパスワードでログインしてください。']);
    }
}
