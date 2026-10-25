<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\AccountEmailService;
use App\Services\LoginThrottle;
use App\Support\InitialCategories;
use App\Support\PasswordStrength;
use App\Support\ReservedUsernames;
use App\Support\UiLocale;
use App\Support\UsernameRules;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request, LoginThrottle $throttle): JsonResponse
    {
        $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:4096']]);
        $user = User::query()->where('username', $request->string('login'))->orWhereRaw('LOWER(email) = ?', [strtolower((string) $request->string('login'))])->first();

        $identity = $user ? 'user:'.$user->id : 'unknown:'.mb_strtolower((string) $request->string('login'));

        return $throttle->run($request, $identity, function () use ($user, $request): JsonResponse {
            $hash = $user?->password ?? Cache::rememberForever('login:dummy:'.config('hashing.driver').':'.config('hashing.bcrypt.rounds'), fn () => Hash::make(Str::random(48)));
            $matches = Hash::check((string) $request->string('password'), $hash);
            if (! $user || ! $matches) {
                throw ValidationException::withMessages(['login' => [__('messages.invalid_credentials')]]);
            }

            return $this->withMembershipLock($user, function (GroupMember $membership) use ($user, $request): JsonResponse {
                $user->refresh();
                if (! Hash::check((string) $request->string('password'), $user->password)
                    || ($user->username !== (string) $request->string('login') && strtolower((string) $user->email) !== strtolower((string) $request->string('login')))) {
                    throw ValidationException::withMessages(['login' => [__('messages.invalid_credentials')]]);
                }
                $weak = ! PasswordStrength::acceptable((string) $request->string('password'));
                $user->forceFill(['password_is_weak' => $weak, ...(! $weak ? ['password_warning_snoozed_until' => null] : [])])->save();
                $user->tokens()->delete();
                $token = $user->createToken('auth_token', expiresAt: now()->addDays(30))->plainTextToken;

                return response()->json([
                    'message' => __('messages.logged_in'), 'access_token' => $token, 'token_type' => 'Bearer',
                    ...$this->identityPayload($user, $membership),
                ]);
            });
        }, $user);
    }

    public function register(Request $request): JsonResponse
    {
        if ($response = $this->consumeRegistrationAttempt($request)) {
            return $response;
        }
        if (array_intersect(array_keys($request->all()), ['group_id', 'type', 'role', 'status', 'email', 'ui_locale'])) {
            throw ValidationException::withMessages(['registration' => [__('messages.registration_fields')]]);
        }

        $validated = $request->validate([
            'group_name' => ['required', 'string', 'max:50'],
            'username' => UsernameRules::create(),
            'display_name' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', new StrongPassword, 'confirmed'],
        ], UsernameRules::messages());

        [$user, $membership] = DB::transaction(function () use ($validated, $request): array {
            $user = User::query()->create([
                'username' => $validated['username'], 'display_name' => $validated['display_name'],
                'email' => null, 'password' => Hash::make($validated['password']),
            ]);
            $user->forceFill(['password_is_weak' => false])->save();
            $group = Group::query()->create(['name' => $validated['group_name'], 'type' => Group::TYPE_FAMILY]);
            $membership = $group->memberships()->create([
                'user_id' => $user->id, 'role' => GroupMember::ROLE_ADMIN, 'status' => GroupMember::STATUS_ACTIVE,
            ]);
            InitialCategories::createFor($group, (string) $request->attributes->get('ui_locale'));

            return [$user, $membership->setRelation('group', $group)];
        });

        $token = $user->createToken('auth_token', expiresAt: now()->addDays(30))->plainTextToken;

        return response()->json([
            'message' => __('messages.family_registered'), 'access_token' => $token, 'token_type' => 'Bearer',
            ...$this->identityPayload($user, $membership),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => __('messages.logged_out')]);
    }

    public function usernameAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate(['username' => UsernameRules::syntax()], UsernameRules::messages());

        return response()->json([
            'available' => ! app(ReservedUsernames::class)->contains($validated['username'])
                && ! User::query()->where('username', $validated['username'])->exists(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->identityPayload($request->user()->fresh(), $this->activeMembership($request->user())));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $this->activeMembership($request->user());
        if (array_diff(array_keys($request->all()), ['display_name', 'show_name_suffix', 'ui_locale'])) {
            throw ValidationException::withMessages(['profile' => [__('messages.profile_fields')]]);
        }
        if ($request->all() === []) {
            throw ValidationException::withMessages(['profile' => [__('messages.no_changes')]]);
        }
        if ($request->attributes->get('has_ui_locale_input')) {
            $inputLocale = $request->attributes->get('ui_locale_input');
            if ($inputLocale !== null && ! in_array($inputLocale, UiLocale::supported(), true)) {
                throw ValidationException::withMessages(['ui_locale' => [__('validation.in', ['attribute' => __('validation.attributes.ui_locale')])]]);
            }
            $request->merge(['ui_locale' => $inputLocale]);
        }
        $validated = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:50'],
            'show_name_suffix' => ['sometimes', 'required', 'boolean:strict'],
            'ui_locale' => ['sometimes', 'nullable', 'string', Rule::in(UiLocale::supported())],
        ], [
            'show_name_suffix.boolean' => __('messages.suffix_boolean'),
            'display_name.required' => __('messages.display_name_required'), 'display_name.string' => __('messages.display_name_string'),
            'display_name.max' => __('messages.display_name_max'),
        ]);

        return $this->withMembershipLock($request->user(), function (GroupMember $membership) use ($request, $validated): JsonResponse {
            $request->user()->update($validated);

            return response()->json($this->identityPayload($request->user()->fresh(), $membership));
        });
    }

    public function updatePassword(Request $request, AccountEmailService $emails): JsonResponse
    {
        $this->activeMembership($request->user());
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'], 'new_password' => ['required', 'string', new StrongPassword, 'confirmed'],
        ]);

        return $this->withMembershipLock($request->user(), function () use ($request, $validated, $emails): JsonResponse {
            $user = $request->user()->fresh();
            if (! Hash::check($validated['current_password'], $user->password)) {
                return response()->json(['message' => __('messages.password_incorrect')], 422);
            }
            $user->forceFill(['password' => Hash::make($validated['new_password']), 'password_is_weak' => false, 'password_warning_snoozed_until' => null])->save();
            $emails->clearChallenges($user);
            $currentToken = $request->user()->currentAccessToken();
            $user->tokens()->when($currentToken instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))->delete();

            return response()->json(['message' => __('messages.password_changed')]);
        });
    }

    public function snoozePasswordWarning(Request $request): JsonResponse
    {
        abort_if($request->all() !== [], 422, __('messages.snooze_fields'));

        return $this->withMembershipLock($request->user(), function (GroupMember $membership) use ($request): JsonResponse {
            $user = $request->user()->fresh();
            $user->forceFill(['password_warning_snoozed_until' => now('Asia/Tokyo')->addDay()->startOfDay()->utc()])->save();

            return response()->json($this->identityPayload($user, $membership));
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
        abort_if($memberships->isEmpty(), 403, __('messages.account_unavailable'));
        abort_if($memberships->count() !== 1, 409, __('messages.membership_unknown'));

        return $memberships->first();
    }

    /** @return array{user: array<string, mixed>, membership: array<string, mixed>} */
    private function identityPayload(User $user, GroupMember $membership): array
    {
        return [
            'user' => [
                ...$user->fresh()->only(['id', 'username', 'display_name', 'show_name_suffix', 'ui_locale', 'email', 'email_verified_at', 'pending_email', 'email_verification_expires_at']),
                'password_security' => [
                    'status' => $user->password_is_weak === null ? 'unknown' : ($user->password_is_weak ? 'weak' : 'ok'),
                    'snoozed_until' => $user->password_warning_snoozed_until?->toIso8601String(),
                ],
            ],
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
            abort_if(! is_int($value) || $value < 1, 500, __('messages.registration_config'));
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
            ? response()->json(['message' => __('messages.registration_limited')], 429, ['Retry-After' => (string) $retryAfter])
            : null;
    }
}
