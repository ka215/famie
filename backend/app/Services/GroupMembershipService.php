<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupMembershipService
{
    public function changeStatus(User $actor, GroupMember $target, string $status): GroupMember
    {
        if (! in_array($status, [GroupMember::STATUS_ACTIVE, GroupMember::STATUS_INACTIVE], true)) {
            throw ValidationException::withMessages(['status' => ['所属状態が不正です。']]);
        }

        return DB::transaction(function () use ($actor, $target, $status): GroupMember {
            Group::query()->whereKey($target->group_id)->lockForUpdate()->firstOrFail();
            $memberships = GroupMember::query()->where('group_id', $target->group_id)->orderBy('id')->lockForUpdate()->get();
            $actorMembership = $memberships->firstWhere('user_id', $actor->id);
            $lockedTarget = $memberships->firstWhere('id', $target->id);
            abort_unless($actorMembership?->isAdmin() && $lockedTarget, 403, '所属の変更権限がありません。');

            if ($status === GroupMember::STATUS_INACTIVE) {
                abort_if($lockedTarget->user_id === $actor->id, 422, '自分自身を無効化できません。');
                $activeAdmins = $memberships->where('status', GroupMember::STATUS_ACTIVE)->where('role', GroupMember::ROLE_ADMIN)->count();
                abort_if($lockedTarget->isAdmin() && $activeAdmins <= 1, 422, '最後の管理者は無効化できません。');
            } else {
                $activeCount = $memberships->where('status', GroupMember::STATUS_ACTIVE)->count();
                abort_if($lockedTarget->status !== GroupMember::STATUS_ACTIVE && $activeCount >= config('famie.max_group_members'), 422, '家族メンバーの上限に達しています。');
            }

            $lockedTarget->update(['status' => $status]);
            if ($status === GroupMember::STATUS_INACTIVE) {
                $lockedTarget->user->tokens()->delete();
            }

            return $lockedTarget->fresh();
        });
    }
}
