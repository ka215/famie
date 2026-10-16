<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Notifications\RepeatedLoginLockout;
use Illuminate\Support\Facades\DB;

class LoginSecurityService
{
    public function recordLockout(User $user): void
    {
        $membership = $user->activeMembership()->first();
        if (! $membership) {
            return;
        }
        DB::transaction(function () use ($user, $membership): void {
            Group::query()->whereKey($membership->group_id)->lockForUpdate()->firstOrFail();
            $membership = $user->activeMembership()->where('group_id', $membership->group_id)->first();
            if (! $membership) {
                return;
            }
            $subject = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $now = now()->startOfSecond();
            $repeated = $subject->last_login_lockout_at?->gte($now->copy()->subDay()) ?? false;
            $alreadyNotified = $subject->last_login_alert_at?->gt($now->copy()->subDay()) ?? false;
            $subject->forceFill(['last_login_lockout_at' => $now])->save();
            if (! $repeated || $alreadyNotified) {
                return;
            }
            $parents = GroupMember::query()->where('group_id', $membership->group_id)
                ->where('status', GroupMember::STATUS_ACTIVE)->where('role', GroupMember::ROLE_ADMIN)->with('user')->get();
            foreach ($parents as $parent) {
                $parent->user->notify(new RepeatedLoginLockout([
                    'group_id' => $membership->group_id,
                    'membership_id' => $membership->id,
                    'display_name' => $subject->display_name,
                    'username' => $subject->username,
                    'occurred_at' => $now->toIso8601String(),
                ]));
            }
            $subject->forceFill(['last_login_alert_at' => $now])->save();
        });
    }
}
