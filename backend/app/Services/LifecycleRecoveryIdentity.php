<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LifecycleRecoveryIdentity
{
    /** @param list<int> $subjectIds */
    public static function valid(mixed $identity, array $subjectIds, string $kind): bool
    {
        if (! in_array($kind, ['member_delete', 'group_withdraw'], true) || ! is_array($identity) || count($identity) !== 2 || array_diff(array_keys($identity), ['actor_user_id', 'subjects'])
            || ! is_int($identity['actor_user_id'] ?? null) || $identity['actor_user_id'] < 1
            || ! is_array($identity['subjects'] ?? null) || ! array_is_list($identity['subjects'])
            || $identity['subjects'] === [] || count($identity['subjects']) > 500) {
            return false;
        }
        $users = [];
        $memberships = [];
        foreach ($identity['subjects'] as $subject) {
            if (! is_array($subject) || count($subject) !== 2 || array_diff(array_keys($subject), ['user_id', 'membership_id'])
                || ! is_int($subject['user_id'] ?? null) || $subject['user_id'] < 1
                || ! is_int($subject['membership_id'] ?? null) || $subject['membership_id'] < 1
                || isset($users[$subject['user_id']]) || isset($memberships[$subject['membership_id']])) {
                return false;
            }
            $users[$subject['user_id']] = true;
            $memberships[$subject['membership_id']] = true;
        }
        $actual = array_keys($users);
        sort($actual);
        sort($subjectIds);

        return $actual === $subjectIds
            && ($kind === 'group_withdraw' ? isset($users[$identity['actor_user_id']]) : ! isset($users[$identity['actor_user_id']]));
    }

    /** @return array{actor_user_id:int,subjects:list<array{user_id:int,membership_id:int}>} */
    public static function capture(AccountLifecycleOperation $operation): array
    {
        $subjects = $operation->subjects()->orderBy('user_id')->get(['user_id', 'membership_id']);
        $members = DB::table('group_members')->whereIn('id', $subjects->pluck('membership_id'))->get()->keyBy('id');
        foreach ($subjects as $subject) {
            $member = $members->get($subject->membership_id);
            if ($member === null || $member->user_id !== $subject->user_id || $member->group_id !== $operation->group_id) {
                throw new RuntimeException('Source recovery mapping is unavailable.');
            }
        }

        return ['actor_user_id' => $operation->actor_user_id, 'subjects' => $subjects->map(fn ($subject) => [
            'user_id' => $subject->user_id, 'membership_id' => $subject->membership_id])->all()];
    }
}
