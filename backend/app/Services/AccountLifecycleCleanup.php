<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use App\Models\ActivityImage;
use App\Models\ActivityLog;
use App\Models\RewardAward;
use Illuminate\Support\Facades\DB;

class AccountLifecycleCleanup
{
    public function __construct(private ActivityImageDeletionService $imageDeletions, private LifecycleLedger $ledger) {}

    public function process(AccountLifecycleOperation $operation): void
    {
        $processed = DB::transaction(function () use ($operation): bool {
            $locked = AccountLifecycleOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== AccountLifecycleOperation::STATE_COMMITTED) {
                return false;
            }
            if (in_array($locked->last_error_code, ['ledger_commit_pending', 'ledger_commit_failed'], true)) {
                $this->ledger->append($locked, 'committed');
                $locked->forceFill(['last_error_code' => null])->save();
            }
            if ($locked->hasActiveHold()) {
                return false;
            }
            $this->processBatch($locked);

            return true;
        });
        if ($processed) {
            $this->imageDeletions->processSafely(config('famie.lifecycle_cleanup_batch'));
        }
    }

    private function processBatch(AccountLifecycleOperation $operation): void
    {
        if ($operation->state !== AccountLifecycleOperation::STATE_COMMITTED) {
            return;
        }
        $userIds = $operation->subjects()->pluck('user_id');
        $membershipIds = $operation->subjects()->pluck('membership_id');
        ActivityImage::query()->whereNull('retired_at')->whereIn('activity_log_id', function ($query) use ($operation, $userIds): void {
            $query->select('id')->from('activity_logs')->where('group_id', $operation->group_id)->whereIn('user_id', $userIds);
        })->orderBy('id')->limit(config('famie.lifecycle_cleanup_batch'))->get()->each(
            fn (ActivityImage $image) => $this->imageDeletions->retire($image, $operation->id, false),
        );

        $logs = ActivityLog::withTrashed()->where('group_id', $operation->group_id)->whereIn('user_id', $userIds)
            ->where('id', '>', $operation->content_cursor)->orderBy('id')->limit(config('famie.lifecycle_cleanup_batch'))->get();
        if ($logs->isNotEmpty()) {
            DB::transaction(function () use ($logs, $operation): void {
                ActivityLog::withTrashed()->whereIn('id', $logs->pluck('id'))->update([
                    'content' => '',
                    'note' => null,
                    'deleted_at' => $operation->committed_at,
                    'content_erased_at' => now(),
                    'lifecycle_operation_id' => $operation->id,
                    'updated_at' => now(),
                ]);
                $operation->forceFill(['content_cursor' => $logs->last()->id])->save();
            });
        }

        $awards = RewardAward::query()->where('group_id', $operation->group_id)->whereIn('group_member_id', $membershipIds)
            ->where(function ($query) use ($operation): void {
                $query->whereNull('snapshot->member_name')->orWhere('snapshot->member_name', '!=', 'Deleted member');
                if ($operation->kind === AccountLifecycleOperation::KIND_GROUP_WITHDRAW) {
                    $query->orWhereNull('snapshot->name')->orWhere('snapshot->name', '!=', 'Deleted reward')
                        ->orWhereNull('snapshot->description')->orWhere('snapshot->description', '!=', '')->orWhereNotNull('snapshot->category_name');
                }
            });
        (clone $awards)
            ->orderBy('id')->limit(config('famie.lifecycle_cleanup_batch'))->get()->each(function (RewardAward $award) use ($operation): void {
                $snapshot = $award->snapshot;
                $snapshot['member_name'] = 'Deleted member';
                if ($operation->kind === AccountLifecycleOperation::KIND_GROUP_WITHDRAW) {
                    $snapshot = [...$snapshot, 'name' => 'Deleted reward', 'description' => '', 'category_name' => null];
                }
                $award->forceFill(['snapshot' => $snapshot])->save();
            });
        $notifications = DB::table('notifications')->where('type', 'App\\Notifications\\RepeatedLoginLockout')
            ->where('data->group_id', $operation->group_id)->whereIn('data->membership_id', $membershipIds)
            ->where(fn ($query) => $query->whereNotNull('data->username')->orWhereNull('data->display_name')->orWhere('data->display_name', '!=', 'Deleted member'));
        (clone $notifications)
            ->orderBy('id')->limit(config('famie.lifecycle_cleanup_batch'))->get()->each(function (object $notification) use ($operation, $membershipIds): void {
                $data = json_decode($notification->data, true);
                if (! is_array($data) || (int) ($data['group_id'] ?? 0) !== $operation->group_id
                    || ! $membershipIds->contains((int) ($data['membership_id'] ?? 0))) {
                    return;
                }
                unset($data['username']);
                $data['display_name'] = 'Deleted member';
                DB::table('notifications')->where('id', $notification->id)->update(['data' => json_encode($data, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            });
        $categories = DB::table('categories')->where('group_id', $operation->group_id)
            ->whereRaw('name <> ? || id', ['Deleted category ']);
        $versions = DB::table('reward_versions')->where('group_id', $operation->group_id)
            ->where(fn ($query) => $query->where('name', '!=', 'Deleted reward')->orWhere('description', '!=', '')->orWhereNotNull('category_name'));
        if ($operation->kind === AccountLifecycleOperation::KIND_GROUP_WITHDRAW) {
            (clone $categories)->orderBy('id')->limit(config('famie.lifecycle_cleanup_batch'))->get()
                ->each(fn (object $category) => DB::table('categories')->where('id', $category->id)->update(['name' => 'Deleted category '.$category->id, 'updated_at' => now()]));
            $versionIds = (clone $versions)->orderBy('id')->limit(config('famie.lifecycle_cleanup_batch'))->pluck('id');
            DB::table('reward_versions')->whereIn('id', $versionIds)->update([
                'name' => 'Deleted reward', 'description' => '', 'category_name' => null,
            ]);
        }

        $remainingLogs = ActivityLog::withTrashed()->where('group_id', $operation->group_id)->whereIn('user_id', $userIds)->whereNull('content_erased_at')->exists();
        $remainingImages = ActivityImage::query()->whereNull('retired_at')->whereIn('activity_log_id', function ($query) use ($operation, $userIds): void {
            $query->select('id')->from('activity_logs')->where('group_id', $operation->group_id)->whereIn('user_id', $userIds);
        })->exists();
        $pendingImageFiles = DB::table('activity_image_deletions')->where('operation_id', $operation->id)->exists();
        $unmaskedFamily = $operation->kind === AccountLifecycleOperation::KIND_GROUP_WITHDRAW
            && ($categories->exists() || $versions->exists());
        $state = $remainingLogs || $remainingImages || $pendingImageFiles || $awards->exists() || $notifications->exists() || $unmaskedFamily ? 'processing' : 'complete';
        $operation->forceFill(['cleanup_state' => $state])->save();
    }
}
