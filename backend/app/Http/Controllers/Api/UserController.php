<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Rules\StrongPassword;
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
        abort_unless($membership instanceof GroupMember && $membership->isAdmin(), 403, __('messages.account_create_forbidden'));
        if (array_intersect(array_keys($request->all()), ['group_id', 'status', 'user_id', 'email', 'ui_locale'])) {
            throw ValidationException::withMessages(['member' => [__('messages.member_fields')]]);
        }
        $validated = $request->validate([
            'username' => UsernameRules::create(),
            'display_name' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', new StrongPassword, 'confirmed'],
            'role' => ['required', Rule::in([GroupMember::ROLE_ADMIN, GroupMember::ROLE_MEMBER])],
        ], UsernameRules::messages());

        [$user, $newMembership] = DB::transaction(function () use ($group, $validated): array {
            Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_if(
                $group->memberships()->where('status', GroupMember::STATUS_ACTIVE)->count() >= config('famie.max_group_members'),
                422,
                __('messages.member_max', ['max' => config('famie.max_group_members')]),
            );
            $user = User::query()->create([
                'username' => $validated['username'], 'display_name' => $validated['display_name'],
                'email' => null, 'password' => Hash::make($validated['password']),
            ]);
            $user->forceFill(['password_is_weak' => false])->save();
            $newMembership = $group->memberships()->create([
                'user_id' => $user->id, 'role' => $validated['role'], 'status' => GroupMember::STATUS_ACTIVE,
            ]);

            return [$user, $newMembership];
        });

        return response()->json([
            'message' => __('messages.user_created'),
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
            'display_name.required' => __('messages.display_name_required'),
            'display_name.string' => __('messages.display_name_string'),
            'display_name.max' => __('messages.display_name_max'),
            'role.required' => __('messages.role_required'),
            'role.in' => __('messages.role_invalid'),
        ]);
        $updated = $service->updateMember($request->user(), $target, $validated['display_name'], $validated['role']);

        return response()->json(['message' => __('messages.member_updated'), 'data' => $this->memberPayload($updated)]);
    }

    public function updateStatus(Request $request, Group $group, int $member, GroupMembershipService $service): JsonResponse
    {
        $this->authorizeAdmin($request);
        $target = $group->memberships()->findOrFail($member);
        $this->rejectUnexpectedFields($request, ['status']);
        $validated = $request->validate([
            'status' => ['required', Rule::in([GroupMember::STATUS_ACTIVE, GroupMember::STATUS_INACTIVE])],
        ], ['status.required' => __('messages.status_required'), 'status.in' => __('messages.status_invalid')]);
        $updated = $service->changeStatus($request->user(), $target, $validated['status']);

        return response()->json([
            'message' => $updated->status === GroupMember::STATUS_ACTIVE ? __('messages.member_restored') : __('messages.member_deactivated'),
            'data' => $this->memberPayload($updated),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $membership = $request->attributes->get('group_membership');
        abort_unless($membership instanceof GroupMember && $membership->isAdmin(), 403, __('messages.member_forbidden'));
    }

    public function resetPassword(Request $request, Group $group, int $member, GroupMembershipService $service): JsonResponse
    {
        $this->authorizeAdmin($request);
        $target = $group->memberships()->findOrFail($member);
        $this->rejectUnexpectedFields($request, ['current_password', 'new_password', 'new_password_confirmation']);
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
            'new_password' => ['required', 'string', new StrongPassword, 'confirmed'],
        ]);
        $service->resetPassword($request->user(), $target, $validated['current_password'], $validated['new_password']);

        return response()->json(['message' => __('messages.member_password_reset')]);
    }

    /** @param array<int, string> $allowed */
    private function rejectUnexpectedFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed)) {
            throw ValidationException::withMessages(['member' => [__('messages.unexpected_fields')]]);
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
