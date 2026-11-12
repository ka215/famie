<?php

namespace App\Console\Commands;

use App\Models\AccountLifecycleCredential;
use App\Models\AccountLifecycleOperation;
use App\Services\AccountLifecycleCleanup;
use App\Services\ActivityImageDeletionService;
use App\Services\LifecycleCleanupBudget;
use App\Services\LifecycleProcessContext;
use App\Services\LifecycleVault;
use App\Services\OperationState;
use App\Support\OperationLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

#[Signature('famie:cleanup-account-lifecycle {--isolated-context : Require the isolated process testing context}')]
#[Description('Erase committed account lifecycle content and expire inquiry vault data')]
class CleanupAccountLifecycle extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AccountLifecycleCleanup $cleanup, ActivityImageDeletionService $images, LifecycleVault $vault, LifecycleCleanupBudget $budget, OperationState $state, LifecycleProcessContext $context): int
    {
        try {
            $context->environment((bool) $this->option('isolated-context'));

            return $state->locked('lifecycle-cleanup', function () use ($cleanup, $images, $vault, $budget, $state): int {
                $budget->start();
                $started = now()->timestamp;
                $id = (string) Str::uuid();
                $state->write('lifecycle-cleanup', ['started_at' => $started, 'run_id' => $id, 'outcome' => 'started']);
                try {
                    $result = $this->executeCleanup($cleanup, $images, $vault, $budget);
                    $state->write('lifecycle-cleanup', ['started_at' => $started, 'finished_at' => now()->timestamp, 'run_id' => $id,
                        'outcome' => $result === self::SUCCESS ? 'success' : 'failure', 'budget_exhausted' => ! $budget->available()]);

                    return $result;
                } catch (Throwable) {
                    $state->write('lifecycle-cleanup', ['started_at' => $started, 'finished_at' => now()->timestamp, 'run_id' => $id, 'outcome' => 'failure']);

                    return self::FAILURE;
                }
            });
        } catch (Throwable) {
            $this->error('Lifecycle cleanup state unavailable or already locked.');

            return self::FAILURE;
        }
    }

    private function executeCleanup(AccountLifecycleCleanup $cleanup, ActivityImageDeletionService $images, LifecycleVault $vault, LifecycleCleanupBudget $budget): int
    {
        $failed = 0;
        AccountLifecycleOperation::query()->where('state', AccountLifecycleOperation::STATE_PREPARED)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_holds')
                    ->whereColumn('account_lifecycle_holds.operation_id', 'account_lifecycle_operations.id')->whereNull('released_at');
            })
            ->where('prepared_expires_at', '<=', now())->orderBy('id')->limit(100)->get()->each(function (AccountLifecycleOperation $operation) use ($budget): ?bool {
                if (! $budget->available()) {
                    return false;
                }
                DB::transaction(function () use ($operation): void {
                    $locked = AccountLifecycleOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
                    if ($locked->state !== AccountLifecycleOperation::STATE_PREPARED || $locked->prepared_expires_at->isFuture() || $locked->hasActiveHold()
                        || str_starts_with((string) $locked->last_error_code, 'restore_reapply_')) {
                        return;
                    }
                    $locked->forceFill(['state' => AccountLifecycleOperation::STATE_ABORTED, 'cleanup_state' => 'complete'])->save();
                    $locked->credential()->delete();
                });

                return null;
            });
        AccountLifecycleCredential::query()->where('expires_at', '<=', now())
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_operations')
                    ->whereColumn('account_lifecycle_operations.id', 'account_lifecycle_credentials.operation_id')
                    ->where(fn ($query) => $query->whereIn('last_error_code', ['ledger_commit_pending', 'ledger_commit_failed'])
                        ->orWhereRaw('substr(last_error_code, 1, 16) = ?', ['restore_reapply_']));
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_holds')
                    ->whereColumn('account_lifecycle_holds.operation_id', 'account_lifecycle_credentials.operation_id')->whereNull('released_at');
            })->orderBy('operation_id')->limit(100)->get()
            ->each(function (AccountLifecycleCredential $credential) use ($vault, $budget, &$failed): ?bool {
                if (! $budget->available()) {
                    return false;
                }
                try {
                    DB::transaction(function () use ($credential, $vault): void {
                        $operation = AccountLifecycleOperation::query()->whereKey($credential->operation_id)->lockForUpdate()->firstOrFail();
                        if ($operation->hasActiveHold() || str_starts_with((string) $operation->last_error_code, 'restore_reapply_')
                            || in_array($operation->last_error_code, ['ledger_commit_pending', 'ledger_commit_failed'], true)) {
                            return;
                        }
                        $locked = AccountLifecycleCredential::query()->whereKey($credential->operation_id)->lockForUpdate()->first();
                        if (! $locked || $locked->expires_at->isFuture()) {
                            return;
                        }
                        $vault->delete($locked->operation_id);
                        $locked->delete();
                    });
                } catch (Throwable $exception) {
                    $failed++;
                    OperationLog::write('lifecycle.vault_cleanup_failed', 'failure', ['operation_id' => $credential->operation_id], 'critical');
                    report($exception);
                }

                return null;
            });
        AccountLifecycleOperation::query()->where('state', AccountLifecycleOperation::STATE_COMMITTED)
            ->where(fn ($query) => $query->whereNull('last_error_code')->orWhereRaw('substr(last_error_code, 1, 16) <> ?', ['restore_reapply_']))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_holds')
                    ->whereColumn('account_lifecycle_holds.operation_id', 'account_lifecycle_operations.id')->whereNull('released_at');
            })
            ->where(fn ($query) => $query->where('cleanup_state', '!=', 'complete')->orWhereIn('last_error_code', ['ledger_commit_pending', 'ledger_commit_failed', 'ledger_cleanup_pending']))
            ->orderBy('updated_at')->orderBy('id')->limit(20)->get()
            ->each(function (AccountLifecycleOperation $operation) use ($cleanup, $budget, &$failed): ?bool {
                if (! $budget->available()) {
                    return false;
                }
                try {
                    $cleanup->process($operation, $budget);
                } catch (Throwable $exception) {
                    $failed++;
                    $operation->touch();
                    OperationLog::write('lifecycle.cleanup_deferred', 'failure', ['operation_id' => $operation->id], 'critical');
                    report($exception);
                }

                return null;
            });
        if ($budget->available() && ! $images->processSafely(100, $budget)) {
            $failed++;
        }

        if (! $budget->available()) {
            $this->warn('時間予算に達したため、残りは次回へ延期します。');
        }
        $this->info("後処理失敗・延期: {$failed} 件");

        return $failed === 0 && $budget->available() ? self::SUCCESS : self::FAILURE;
    }
}
