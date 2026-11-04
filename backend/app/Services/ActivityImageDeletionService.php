<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use App\Models\ActivityImage;
use App\Models\ActivityLog;
use App\Support\OperationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ActivityImageDeletionService
{
    public function __construct(private OperationState $state) {}

    public function retire(ActivityImage $image, ?string $operationId = null, bool $processAfterCommit = true): void
    {
        DB::transaction(function () use ($image, $operationId, $processAfterCommit): void {
            $image = ActivityImage::query()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            if ($image->retired_at !== null) {
                return;
            }
            $now = now();
            $image->forceFill(['retired_at' => $now, 'lifecycle_operation_id' => $operationId])->save();
            DB::table('activity_image_deletions')->insertOrIgnore([
                'group_id' => $image->group_id,
                'activity_image_id' => $image->id,
                'operation_id' => $operationId,
                'disk' => $image->disk,
                'path' => $image->path,
                'bytes' => $image->bytes,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($processAfterCommit) {
                DB::afterCommit(fn (): bool => $this->processSafely(10));
            }
        });
    }

    public function processSafely(int $limit = 100): bool
    {
        try {
            return $this->process($limit) === 0;
        } catch (Throwable) {
            OperationLog::write('image.deletion_deferred', 'started', [], 'info');

            return false;
        }
    }

    public function process(int $limit = 100): int
    {
        return $this->state->locked('image-deletions', fn (): int => $this->processBatch($limit));
    }

    private function processBatch(int $limit): int
    {
        $rows = DB::table('activity_image_deletions')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_operations')
                    ->whereColumn('account_lifecycle_operations.id', 'activity_image_deletions.operation_id')
                    ->where(fn ($query) => $query->where('state', '!=', AccountLifecycleOperation::STATE_COMMITTED)
                        ->orWhereIn('last_error_code', ['ledger_commit_pending', 'ledger_commit_failed']));
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_holds')
                    ->whereColumn('account_lifecycle_holds.operation_id', 'activity_image_deletions.operation_id')->whereNull('released_at');
            })
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get();
        $failed = 0;
        foreach ($rows as $row) {
            try {
                DB::transaction(function () use ($row): void {
                    if ($row->operation_id !== null) {
                        $operation = AccountLifecycleOperation::query()->whereKey($row->operation_id)->lockForUpdate()->firstOrFail();
                        if ($operation->hasActiveHold() || $operation->state !== AccountLifecycleOperation::STATE_COMMITTED
                            || in_array($operation->last_error_code, ['ledger_commit_pending', 'ledger_commit_failed'], true)) {
                            return;
                        }
                    }
                    if (! DB::table('activity_image_deletions')->where('id', $row->id)->lockForUpdate()->first()) {
                        return;
                    }
                    $deleted = ! Storage::disk($row->disk)->exists($row->path) || Storage::disk($row->disk)->delete($row->path);
                    if (! $deleted) {
                        throw new \RuntimeException('storage_delete_failed');
                    }
                    $activityLogId = ActivityImage::query()->whereKey($row->activity_image_id)->value('activity_log_id');
                    DB::table('activity_image_deletions')->where('id', $row->id)->delete();
                    ActivityImage::query()->whereKey($row->activity_image_id)->whereNotNull('retired_at')->delete();
                    if ($activityLogId !== null) {
                        $log = ActivityLog::withTrashed()->find($activityLogId);
                        if ($log?->deleted_at !== null && $log->lifecycle_operation_id === null
                            && ! ActivityImage::query()->where('activity_log_id', $activityLogId)->exists()) {
                            $log->forceDelete();
                        }
                    }
                });
            } catch (Throwable $exception) {
                $failed++;
                $attempts = (int) $row->attempts + 1;
                DB::table('activity_image_deletions')->where('id', $row->id)->update([
                    'attempts' => $attempts,
                    'next_attempt_at' => now()->addMinutes(min(60, 2 ** min(5, $attempts))),
                    'last_error_code' => $exception::class,
                    'updated_at' => now(),
                ]);
                OperationLog::write('image.deletion_failed', 'failure', ['deletion_id' => (int) $row->id, 'exception_type' => $exception::class], 'warning');
            }
        }

        return $failed;
    }
}
