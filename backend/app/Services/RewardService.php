<?php

namespace App\Services;

use App\Models\ActivityLike;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Reward;
use App\Models\RewardAward;
use App\Models\RewardPeriod;
use App\Models\RewardVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RewardService
{
    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    public function boundaries(Reward $reward): array
    {
        $anchor = CarbonImmutable::parse($reward->start_month->format('Y-m-d'), $reward->timezone)->startOfDay();
        $clock = CarbonImmutable::now($reward->timezone);
        $months = max(0, ($clock->year - $anchor->year) * 12 + $clock->month - $anchor->month);
        $start = $anchor->addMonthsNoOverflow(intdiv($months, $reward->cycle_months) * $reward->cycle_months);

        return ['start' => $start, 'end' => $start->addMonthsNoOverflow($reward->cycle_months)];
    }

    public function currentVersion(Reward $reward): RewardVersion
    {
        return $reward->versions()->where('effective_from', '<=', $this->boundaries($reward)['start']->toDateString())->orderByDesc('effective_from')->with('targets')->firstOrFail();
    }

    /** @param array{start: CarbonImmutable, end: CarbonImmutable} $bounds */
    public function requiredCount(RewardVersion $version, array $bounds): int
    {
        return $version->count_mode === 'period_days'
            ? (int) CarbonImmutable::parse($bounds['start']->toDateString(), 'UTC')->diffInDays(CarbonImmutable::parse($bounds['end']->toDateString(), 'UTC'))
            : $version->required_count;
    }

    public function count(Group $group, RewardVersion $version, GroupMember $member, array $bounds): int
    {
        if ($member->status !== GroupMember::STATUS_ACTIVE) {
            return 0;
        }
        $logs = $group->activityLogs()->where('user_id', $member->user_id)
            ->where('activity_date', '>=', $bounds['start']->toDateString())->where('activity_date', '<', $bounds['end']->toDateString());
        if ($version->category_id) {
            $logs->where('category_id', $version->category_id);
        }
        if ($version->metric === 'activities') {
            return $logs->distinct()->count('activity_date');
        }

        return ActivityLike::query()->where('group_id', $group->id)->fromActiveMembers()->whereIn('activity_log_id', $logs->select('id'))->count();
    }

    /** Called inside the existing group write transaction, including membership restoration. */
    public function evaluate(Group $group, ?int $ownerUserId = null): void
    {
        $members = $group->memberships()->where('status', 'active')->with('user:id,display_name')->get()->keyBy('id');
        foreach (Reward::query()->where('group_id', $group->id)->where('status', 'active')->orderBy('id')->get() as $reward) {
            $bounds = $this->boundaries($reward);
            if (CarbonImmutable::now($reward->timezone)->lessThan($bounds['start'])) {
                continue;
            }
            $version = $this->currentVersion($reward);
            $requiredCount = $this->requiredCount($version, $bounds);
            foreach ($version->targets as $target) {
                $member = $members->get($target->group_member_id);
                if (! $member || ($ownerUserId !== null && $member->user_id !== $ownerUserId)) {
                    continue;
                }
                $count = $this->count($group, $version, $member, $bounds);
                DB::table('reward_progress')->updateOrInsert(['reward_id' => $reward->id, 'group_member_id' => $member->id, 'starts_on' => $bounds['start']->toDateString()], ['group_id' => $group->id, 'count' => $count, 'updated_at' => now()]);
                if ($count < $requiredCount) {
                    continue;
                }
                $period = RewardPeriod::query()->firstOrCreate(['reward_id' => $reward->id, 'starts_on' => $bounds['start']->toDateString()], [
                    'group_id' => $group->id, 'reward_version_id' => $version->id, 'ends_on' => $bounds['end']->subDay()->toDateString(), 'timezone' => $reward->timezone,
                ]);
                RewardAward::query()->firstOrCreate(['reward_period_id' => $period->id, 'group_member_id' => $member->id], [
                    'group_id' => $group->id, 'reward_id' => $reward->id, 'achieved_count' => $count, 'awarded_at' => now(),
                    'snapshot' => [...$version->only(['name', 'description', 'metric', 'color', 'count_mode', 'category_name']), 'required_count' => $requiredCount,
                        'starts_on' => $bounds['start']->toDateString(), 'ends_on' => $bounds['end']->subDay()->toDateString(), 'timezone' => $reward->timezone,
                        'reward_version_id' => $version->id, 'member_name' => $member->user->display_name],
                ]);
            }
        }
    }

    public function needsPreparation(Group $group): bool
    {
        foreach (Reward::query()->where('group_id', $group->id)->where('status', 'active')->get() as $reward) {
            $bounds = $this->boundaries($reward);
            if (CarbonImmutable::now($reward->timezone)->greaterThanOrEqualTo($bounds['start']) && $reward->prepared_period?->toDateString() !== $bounds['start']->toDateString()) {
                return true;
            }
        }

        return false;
    }

    /** Preparation is retryable; the database lock serializes it with immediate awards. */
    public function prepare(int $groupId, string $startedAt): void
    {
        try {
            DB::transaction(function () use ($groupId, $startedAt): void {
                $group = Group::query()->lockForUpdate()->findOrFail($groupId);
                if ($group->reward_sync_status !== 'processing' || (string) $group->reward_sync_started_at !== $startedAt) {
                    return;
                }
                $this->evaluate($group);
                foreach (Reward::query()->where('group_id', $groupId)->where('status', 'active')->get() as $reward) {
                    $bounds = $this->boundaries($reward);
                    if (CarbonImmutable::now($reward->timezone)->greaterThanOrEqualTo($bounds['start'])) {
                        $reward->update(['prepared_period' => $bounds['start']->toDateString()]);
                    }
                }
                $group->forceFill(['reward_sync_status' => 'complete', 'reward_sync_started_at' => null])->save();
            });
        } catch (Throwable $exception) {
            report($exception);
            Group::query()->whereKey($groupId)->where('reward_sync_started_at', $startedAt)->update(['reward_sync_status' => 'failed', 'reward_sync_started_at' => null]);
        }
    }

    /** @return array<string,mixed> */
    public function present(Group $group, Reward $reward, ?GroupMember $member = null): array
    {
        $bounds = $this->boundaries($reward);
        $version = $this->currentVersion($reward);
        $next = $reward->versions()->where('effective_from', '>', $bounds['start']->toDateString())->orderBy('effective_from')->with('targets')->first();
        $versionData = $version->toArray();
        $versionData['required_count'] = $this->requiredCount($version, $bounds);
        $versionData['target_ids'] = $version->targets->pluck('group_member_id')->all();
        unset($versionData['targets']);
        if ($member) {
            unset($versionData['target_ids']);
        }
        $nextData = $next?->toArray();
        if ($next) {
            $nextStart = CarbonImmutable::parse($next->effective_from->toDateString(), $reward->timezone);
            $nextData['required_count'] = $this->requiredCount($next, ['start' => $nextStart, 'end' => $nextStart->addMonthsNoOverflow($reward->cycle_months)]);
            $nextData['target_ids'] = $next->targets->pluck('group_member_id')->all();
            unset($nextData['targets']);
        }
        $award = $member ? $reward->awards()->where('group_member_id', $member->id)->whereHas('period', fn ($q) => $q->where('starts_on', $bounds['start']->toDateString()))->first() : null;
        $progress = null;
        if ($member && $reward->status !== 'draft') {
            $progress = $reward->status === 'stopped' || $group->reward_sync_status === 'processing'
                ? DB::table('reward_progress')->where('reward_id', $reward->id)->where('group_member_id', $member->id)->where('starts_on', $bounds['start']->toDateString())->value('count')
                : $this->count($group, $version, $member, $bounds);
            $progress = $progress === null ? null : (int) $progress;
        }

        return [...$reward->only(['id', 'status', 'revision', 'start_month', 'cycle_months', 'timezone']),
            'version' => $versionData, 'pending_version' => $member ? null : $nextData,
            'starts_on' => $bounds['start']->toDateString(), 'ends_on' => $bounds['end']->subDay()->toDateString(),
            'started' => CarbonImmutable::now($reward->timezone)->greaterThanOrEqualTo($bounds['start']),
            'progress' => $progress,
            'awarded' => $award !== null, 'achieved_count' => $award?->achieved_count];
    }

    public function categoryInUse(Group $group, int $categoryId): bool
    {
        foreach (Reward::query()->where('group_id', $group->id)->where('status', '!=', 'deleted')->get() as $reward) {
            $current = $this->currentVersion($reward);
            if ($current->category_id === $categoryId || $reward->versions()->where('effective_from', '>', $current->effective_from->toDateString())->where('category_id', $categoryId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
