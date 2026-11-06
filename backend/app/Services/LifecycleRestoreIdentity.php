<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use App\Models\AccountLifecycleSubject;
use App\Models\Group;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LifecycleRestoreIdentity
{
    /** @param array<string,mixed> $evidence */
    public function operation(Group $group, string $receipt, array $evidence, string $recordSha, bool $apply): AccountLifecycleOperation
    {
        $operation = AccountLifecycleOperation::query()->whereKey($receipt)->lockForUpdate()->first();
        $identity = $evidence['recovery_identity'] ?? null;
        if ($operation === null && $identity === null) {
            throw new RuntimeException('Missing receipt requires independently recorded recovery identity.');
        }
        if ($identity !== null) {
            if (! LifecycleRecoveryIdentity::valid($identity, $evidence['subject_ids'], $evidence['kind'])
                || $group->representative_user_id !== $identity['actor_user_id']
                || ! DB::table('users')->where('id', $identity['actor_user_id'])->exists()
                || ! DB::table('group_members')->where('group_id', $group->id)->where('user_id', $identity['actor_user_id'])->exists()) {
                throw new RuntimeException('Recovery actor cannot be established from the restored family.');
            }
            foreach ($identity['subjects'] as $subject) {
                if (! DB::table('users')->where('id', $subject['user_id'])->exists()
                    || ! DB::table('group_members')->where('id', $subject['membership_id'])->where('group_id', $group->id)->where('user_id', $subject['user_id'])->exists()) {
                    throw new RuntimeException('Recovery membership mapping differs from the restored backup.');
                }
            }
        }
        if ($operation !== null) {
            if ($operation->group_id !== $group->id || $operation->kind !== $evidence['kind']
                || ($identity !== null && $operation->actor_user_id !== $identity['actor_user_id'])
                || ($identity !== null && $operation->kind === 'member_delete' && $operation->target_membership_id !== $identity['subjects'][0]['membership_id'])) {
                throw new RuntimeException('Existing receipt disagrees with recovery identity.');
            }

            return $operation;
        }
        $committed = CarbonImmutable::parse($evidence['committed_at']);
        $operation = new AccountLifecycleOperation;
        $operation->forceFill(['id' => $receipt, 'actor_user_id' => $identity['actor_user_id'], 'group_id' => $group->id,
            'kind' => $evidence['kind'], 'target_membership_id' => $evidence['kind'] === 'member_delete' ? $identity['subjects'][0]['membership_id'] : null,
            'state' => 'prepared', 'cleanup_state' => 'pending', 'prepared_expires_at' => $committed,
            'retention_until' => $committed->addDays(30), 'last_error_code' => 'restore_reapply_identity:'.$recordSha]);
        if ($apply) {
            $operation->save();
        }

        return $operation;
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return Collection<int,AccountLifecycleSubject>
     */
    public function subjects(AccountLifecycleOperation $operation, array $evidence, bool $apply): Collection
    {
        $rows = $operation->exists ? $operation->subjects()->orderBy('user_id')->lockForUpdate()->get() : new Collection;
        $identity = $evidence['recovery_identity'] ?? null;
        if ($identity === null) {
            return $rows;
        }
        $mapping = array_column($identity['subjects'], 'membership_id', 'user_id');
        foreach ($rows as $row) {
            if (($mapping[$row->user_id] ?? null) !== $row->membership_id) {
                throw new RuntimeException('Existing subject mapping must not be overwritten.');
            }
        }
        $existing = $rows->keyBy('user_id');
        foreach ($identity['subjects'] as $subject) {
            if (! $existing->has($subject['user_id'])) {
                $new = new AccountLifecycleSubject(['operation_id' => $operation->id, ...$subject]);
                if ($apply) {
                    $new->save();
                }
                $rows->push($new);
            }
        }

        return $rows->sortBy('user_id')->values();
    }
}
