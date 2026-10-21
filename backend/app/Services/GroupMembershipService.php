<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class GroupMembershipService
{
    public function __construct(private AccountEmailService $emails, private RewardService $rewards) {}

    public function updateMember(User $actor, GroupMember $target, string $displayName, string $role): GroupMember
    {
        return DB::transaction(function () use ($actor, $target, $displayName, $role): GroupMember {
            Group::query()->whereKey($target->group_id)->lockForUpdate()->firstOrFail();
            $memberships = GroupMember::query()->where('group_id', $target->group_id)->orderBy('id')->lockForUpdate()->get();
            $actorMembership = $memberships->firstWhere('user_id', $actor->id);
            $lockedTarget = $memberships->firstWhere('id', $target->id);
            abort_unless($actorMembership?->isAdmin() && $lockedTarget, 403, __('messages.membership_forbidden'));
            abort_if($lockedTarget->status !== GroupMember::STATUS_ACTIVE, 422, __('messages.inactive_edit'));
            if (! in_array($role, [GroupMember::ROLE_ADMIN, GroupMember::ROLE_MEMBER], true)) {
                throw ValidationException::withMessages(['role' => [__('messages.role_invalid')]]);
            }
            if ($lockedTarget->role !== $role) {
                abort_if($lockedTarget->user_id === $actor->id, 422, __('messages.self_role'));
                $activeAdmins = $memberships->where('status', GroupMember::STATUS_ACTIVE)->where('role', GroupMember::ROLE_ADMIN)->count();
                abort_if($lockedTarget->isAdmin() && $activeAdmins <= 1, 422, __('messages.last_admin_demote'));
            }
            $lockedTarget->user->update(['display_name' => $displayName]);
            $lockedTarget->update(['role' => $role]);

            return $lockedTarget->fresh('user');
        });
    }

    public function changeStatus(User $actor, GroupMember $target, string $status): GroupMember
    {
        if (! in_array($status, [GroupMember::STATUS_ACTIVE, GroupMember::STATUS_INACTIVE], true)) {
            throw ValidationException::withMessages(['status' => [__('messages.status_invalid')]]);
        }

        return DB::transaction(function () use ($actor, $target, $status): GroupMember {
            Group::query()->whereKey($target->group_id)->lockForUpdate()->firstOrFail();
            $memberships = GroupMember::query()->where('group_id', $target->group_id)->orderBy('id')->lockForUpdate()->get();
            $actorMembership = $memberships->firstWhere('user_id', $actor->id);
            $lockedTarget = $memberships->firstWhere('id', $target->id);
            abort_unless($actorMembership?->isAdmin() && $lockedTarget, 403, __('messages.membership_forbidden'));

            if ($status === GroupMember::STATUS_INACTIVE) {
                abort_if($lockedTarget->user_id === $actor->id, 422, __('messages.self_deactivate'));
                $activeAdmins = $memberships->where('status', GroupMember::STATUS_ACTIVE)->where('role', GroupMember::ROLE_ADMIN)->count();
                abort_if($lockedTarget->isAdmin() && $activeAdmins <= 1, 422, __('messages.last_admin_deactivate'));
            } else {
                $activeCount = $memberships->where('status', GroupMember::STATUS_ACTIVE)->count();
                abort_if($lockedTarget->status !== GroupMember::STATUS_ACTIVE && $activeCount >= config('famie.max_group_members'), 422, __('messages.member_limit'));
            }

            $lockedTarget->update(['status' => $status]);
            if ($status === GroupMember::STATUS_INACTIVE) {
                $lockedTarget->user->tokens()->delete();
                $this->emails->clearChallenges($lockedTarget->user);
            }
            $this->rewards->evaluate(Group::query()->findOrFail($target->group_id));

            return $lockedTarget->fresh();
        });
    }

    public function resetPassword(User $actor, GroupMember $target, string $currentPassword, string $newPassword): void
    {
        DB::transaction(function () use ($actor, $target, $currentPassword, $newPassword): void {
            Group::query()->whereKey($target->group_id)->lockForUpdate()->firstOrFail();
            $memberships = GroupMember::query()->where('group_id', $target->group_id)->orderBy('id')->lockForUpdate()->get();
            $actorMembership = $memberships->firstWhere('user_id', $actor->id);
            $lockedTarget = $memberships->firstWhere('id', $target->id);
            abort_unless($actorMembership?->isAdmin() && $lockedTarget, 403, __('messages.member_forbidden'));
            abort_if($lockedTarget->user_id === $actor->id, 422, __('messages.self_password'));
            abort_if($lockedTarget->status !== GroupMember::STATUS_ACTIVE, 422, __('messages.inactive_reset'));
            $parent = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($currentPassword, $parent->password)) {
                throw ValidationException::withMessages(['current_password' => [__('messages.parent_password_incorrect')]]);
            }
            $subject = User::query()->whereKey($lockedTarget->user_id)->lockForUpdate()->firstOrFail();
            $subject->forceFill([
                'password' => Hash::make($newPassword), 'password_is_weak' => false, 'password_warning_snoozed_until' => null,
            ])->save();
            $this->emails->clearChallenges($subject);
            $subject->tokens()->delete();
        });
    }
}
