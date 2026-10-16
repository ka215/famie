<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginThrottle
{
    public function run(Request $request, string $identity, Closure $authenticate, ?User $user = null): JsonResponse
    {
        // パスワード再発行後は、変更前の試行による停止を引き継がない。
        $key = 'login:'.hash_hmac('sha256', $identity.'|'.($user?->password ?? '').'|'.$request->ip(), config('app.key'));
        try {
            return Cache::lock($key.':lock', 10)->block(2, function () use ($key, $authenticate, $user): JsonResponse {
                if (RateLimiter::tooManyAttempts($key.':blocked', 1)) {
                    return $this->blocked(RateLimiter::availableIn($key.':blocked'));
                }
                try {
                    $response = $authenticate();
                } catch (ValidationException $exception) {
                    $attempts = RateLimiter::hit($key, config('famie.login_window_seconds'));
                    if ($attempts >= config('famie.login_max_attempts')) {
                        RateLimiter::hit($key.':blocked', config('famie.login_cooldown_seconds'));
                        RateLimiter::clear($key);
                        if ($user) {
                            app(LoginSecurityService::class)->recordLockout($user);
                        }
                    }
                    throw $exception;
                }
                RateLimiter::clear($key);

                return $response;
            });
        } catch (LockTimeoutException) {
            return $this->blocked(2);
        }
    }

    private function blocked(int $seconds): JsonResponse
    {
        return response()->json([
            'message' => 'ログイン試行回数が上限に達しました。時間を置いて再試行してください。',
        ], 429, ['Retry-After' => (string) max(1, $seconds)]);
    }
}
