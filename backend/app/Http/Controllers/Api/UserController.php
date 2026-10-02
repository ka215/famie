<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\UsernameRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Group $group): JsonResponse
    {
        $members = $group->memberships()->where('status', GroupMember::STATUS_ACTIVE)
            ->with('user:id,username,display_name')->orderBy('id')->get()
            ->map(fn (GroupMember $membership): array => [
                ...$membership->user->only(['id', 'username', 'display_name']),
                'membership_id' => $membership->id,
                'role' => $membership->role,
            ]);

        return response()->json($members);
    }

    public function store(Request $request, Group $group): JsonResponse
    {
        $membership = $request->attributes->get('group_membership');
        abort_unless($membership instanceof GroupMember && $membership->isAdmin(), 403, 'アカウントの作成権限がありません。');
        if (array_intersect(array_keys($request->all()), ['group_id', 'status', 'user_id', 'email'])) {
            throw ValidationException::withMessages(['member' => ['家族・所属状態・ユーザーID・メールアドレスは指定できません。']]);
        }
        $validated = $request->validate([
            'username' => UsernameRules::create(),
            'display_name' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['required', Rule::in([GroupMember::ROLE_ADMIN, GroupMember::ROLE_MEMBER])],
        ], UsernameRules::messages());

        [$user, $newMembership] = DB::transaction(function () use ($group, $validated): array {
            Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_if(
                $group->memberships()->where('status', GroupMember::STATUS_ACTIVE)->count() >= config('famie.max_group_members'),
                422,
                '家族メンバーは最大'.config('famie.max_group_members').'人です。',
            );
            $user = User::query()->create([
                'username' => $validated['username'], 'display_name' => $validated['display_name'],
                'email' => null, 'password' => Hash::make($validated['password']),
            ]);
            $newMembership = $group->memberships()->create([
                'user_id' => $user->id, 'role' => $validated['role'], 'status' => GroupMember::STATUS_ACTIVE,
            ]);

            return [$user, $newMembership];
        });

        return response()->json([
            'message' => 'ユーザーを作成しました。',
            'data' => [...$user->only(['id', 'username', 'display_name']), 'membership_id' => $newMembership->id, 'role' => $newMembership->role],
        ], 201);
    }
}
