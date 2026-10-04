<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\GroupMembershipService;
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

    public function inactive(Request $request, Group $group): JsonResponse
    {
        $this->authorizeAdmin($request);
        $members = $group->memberships()->where('status', GroupMember::STATUS_INACTIVE)
            ->with('user:id,username,display_name')->orderBy('id')->get();

        return response()->json($members->map(fn (GroupMember $member): array => $this->memberPayload($member)));
    }

    public function update(Request $request, Group $group, int $member, GroupMembershipService $service): JsonResponse
    {
        $this->authorizeAdmin($request);
        $target = $group->memberships()->findOrFail($member);
        $this->rejectUnexpectedFields($request, ['display_name', 'role']);
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:50'],
            'role' => ['required', Rule::in([GroupMember::ROLE_ADMIN, GroupMember::ROLE_MEMBER])],
        ], [
            'display_name.required' => '表示名を入力してください。',
            'display_name.string' => '表示名は文字列で入力してください。',
            'display_name.max' => '表示名は50文字以内で入力してください。',
            'role.required' => '役割を選択してください。',
            'role.in' => '役割が不正です。',
        ]);
        $updated = $service->updateMember($request->user(), $target, $validated['display_name'], $validated['role']);

        return response()->json(['message' => 'メンバーを更新しました。', 'data' => $this->memberPayload($updated)]);
    }

    public function updateStatus(Request $request, Group $group, int $member, GroupMembershipService $service): JsonResponse
    {
        $this->authorizeAdmin($request);
        $target = $group->memberships()->findOrFail($member);
        $this->rejectUnexpectedFields($request, ['status']);
        $validated = $request->validate([
            'status' => ['required', Rule::in([GroupMember::STATUS_ACTIVE, GroupMember::STATUS_INACTIVE])],
        ], ['status.required' => '所属状態を指定してください。', 'status.in' => '所属状態が不正です。']);
        $updated = $service->changeStatus($request->user(), $target, $validated['status']);

        return response()->json([
            'message' => $updated->status === GroupMember::STATUS_ACTIVE ? 'メンバーを復元しました。' : 'メンバーを無効化しました。',
            'data' => $this->memberPayload($updated),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $membership = $request->attributes->get('group_membership');
        abort_unless($membership instanceof GroupMember && $membership->isAdmin(), 403, 'メンバーの管理権限がありません。');
    }

    /** @param array<int, string> $allowed */
    private function rejectUnexpectedFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed)) {
            throw ValidationException::withMessages(['member' => ['許可されていない項目が含まれています。']]);
        }
    }

    /** @return array<string, mixed> */
    private function memberPayload(GroupMember $member): array
    {
        return [
            ...$member->user->only(['id', 'username', 'display_name']),
            'membership_id' => $member->id, 'role' => $member->role, 'status' => $member->status,
        ];
    }
}
