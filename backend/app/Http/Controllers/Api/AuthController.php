<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\InitialCategories;
use App\Support\UsernameRules;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate(['login' => ['required', 'string'], 'password' => ['required', 'string']]);
        $user = User::query()->where('username', $request->string('login'))->orWhere('email', $request->string('login'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages(['login' => ['ログインIDまたはパスワードが正しくありません。']]);
        }

        return $this->withMembershipLock($user, function (GroupMember $membership) use ($user): JsonResponse {
            $user->tokens()->delete();
            $token = $user->createToken('auth_token', expiresAt: now()->addDays(30))->plainTextToken;

            return response()->json([
                'message' => 'ログインに成功しました。', 'access_token' => $token, 'token_type' => 'Bearer',
                ...$this->identityPayload($user, $membership),
            ]);
        });
    }

    public function register(Request $request): JsonResponse
    {
        if ($response = $this->consumeRegistrationAttempt($request)) {
            return $response;
        }
        if (array_intersect(array_keys($request->all()), ['group_id', 'type', 'role', 'status', 'email'])) {
            throw ValidationException::withMessages(['registration' => ['家族種別・所属・権限・メールアドレスは指定できません。']]);
        }

        $validated = $request->validate([
            'group_name' => ['required', 'string', 'max:50'],
            'username' => UsernameRules::create(),
            'display_name' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], UsernameRules::messages());

        [$user, $membership] = DB::transaction(function () use ($validated): array {
            $user = User::query()->create([
                'username' => $validated['username'], 'display_name' => $validated['display_name'],
                'email' => null, 'password' => Hash::make($validated['password']),
            ]);
            $group = Group::query()->create(['name' => $validated['group_name'], 'type' => Group::TYPE_FAMILY]);
            $membership = $group->memberships()->create([
                'user_id' => $user->id, 'role' => GroupMember::ROLE_ADMIN, 'status' => GroupMember::STATUS_ACTIVE,
            ]);
            InitialCategories::createFor($group);

            return [$user, $membership->setRelation('group', $group)];
        });

        $token = $user->createToken('auth_token', expiresAt: now()->addDays(30))->plainTextToken;

        return response()->json([
            'message' => '家族を登録しました。', 'access_token' => $token, 'token_type' => 'Bearer',
            ...$this->identityPayload($user, $membership),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'ログアウトしました。']);
    }

    public function usernameAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate(['username' => UsernameRules::syntax()], UsernameRules::messages());

        return response()->json([
            'available' => ! User::query()->where('username', $validated['username'])->exists(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->identityPayload($request->user(), $this->activeMembership($request->user())));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $this->activeMembership($request->user());
        if (array_diff(array_keys($request->all()), ['display_name'])) {
            throw ValidationException::withMessages(['profile' => ['表示名以外は変更できません。']]);
        }
        $validated = $request->validate(['display_name' => ['required', 'string', 'max:50']], [
            'display_name.required' => '表示名を入力してください。', 'display_name.string' => '表示名は文字列で入力してください。',
            'display_name.max' => '表示名は50文字以内で入力してください。',
        ]);

        return $this->withMembershipLock($request->user(), function (GroupMember $membership) use ($request, $validated): JsonResponse {
            $request->user()->update($validated);

            return response()->json($this->identityPayload($request->user()->fresh(), $membership));
        });
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $this->activeMembership($request->user());
        $validated = $request->validate([
            'current_password' => ['required', 'string'], 'new_password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        return $this->withMembershipLock($request->user(), function () use ($request, $validated): JsonResponse {
            $user = $request->user()->fresh();
            if (! Hash::check($validated['current_password'], $user->password)) {
                return response()->json(['message' => '現在のパスワードが正しくありません。'], 422);
            }
            $user->update(['password' => Hash::make($validated['new_password'])]);

            return response()->json(['message' => 'パスワードを変更しました。']);
        });
    }

    private function withMembershipLock(User $user, Closure $callback): JsonResponse
    {
        $membership = $this->activeMembership($user);

        return DB::transaction(function () use ($user, $membership, $callback): JsonResponse {
            Group::query()->whereKey($membership->group_id)->lockForUpdate()->firstOrFail();

            return $callback($this->activeMembership($user));
        });
    }

    private function activeMembership(User $user): GroupMember
    {
        $memberships = $user->activeMembership()->with('group')->get();
        abort_if($memberships->isEmpty(), 403, 'このアカウントは現在利用できません。');
        abort_if($memberships->count() !== 1, 409, '所属情報を特定できません。');

        return $memberships->first();
    }

    /** @return array{user: array<string, mixed>, membership: array<string, mixed>} */
    private function identityPayload(User $user, GroupMember $membership): array
    {
        return [
            'user' => $user->only(['id', 'username', 'display_name', 'email']),
            'membership' => [
                'id' => $membership->id, 'role' => $membership->role, 'status' => $membership->status,
                'group' => $membership->group->only(['id', 'name', 'type']),
            ],
        ];
    }

    private function consumeRegistrationAttempt(Request $request): ?JsonResponse
    {
        $attemptKey = 'register:attempts:'.$request->ip();
        $blockedKey = 'register:blocked:'.$request->ip();
        $maxAttempts = config('famie.register_max_attempts');
        $window = config('famie.register_window_seconds');
        $cooldown = config('famie.register_cooldown_seconds');
        foreach ([$maxAttempts, $window, $cooldown] as $value) {
            abort_if(! is_int($value) || $value < 1, 500, '公開登録の制限設定が不正です。');
        }

        $retryAfter = Cache::lock('register:lock:'.$request->ip(), 5)->block(2, function () use ($attemptKey, $blockedKey, $maxAttempts, $window, $cooldown): int {
            if (RateLimiter::tooManyAttempts($blockedKey, 1)) {
                return RateLimiter::availableIn($blockedKey);
            }
            if (RateLimiter::attempts($attemptKey) >= $maxAttempts) {
                RateLimiter::clear($attemptKey);
                RateLimiter::hit($blockedKey, $cooldown);

                return $cooldown;
            }
            RateLimiter::hit($attemptKey, $window);

            return 0;
        });

        return $retryAfter > 0
            ? response()->json(['message' => '登録試行回数が上限に達しました。時間を置いて再試行してください。'], 429, ['Retry-After' => (string) $retryAfter])
            : null;
    }
}
