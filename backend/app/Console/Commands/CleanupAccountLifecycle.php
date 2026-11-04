<?php

namespace App\Console\Commands;

use App\Models\AccountLifecycleCredential;
use App\Models\AccountLifecycleOperation;
use App\Services\AccountLifecycleCleanup;
use App\Services\ActivityImageDeletionService;
use App\Services\LifecycleVault;
use App\Support\OperationLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('famie:cleanup-account-lifecycle')]
#[Description('Erase committed account lifecycle content and expire inquiry vault data')]
class CleanupAccountLifecycle extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AccountLifecycleCleanup $cleanup, ActivityImageDeletionService $images, LifecycleVault $vault): int
    {
        $failed = 0;
        AccountLifecycleOperation::query()->where('state', AccountLifecycleOperation::STATE_PREPARED)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_holds')
                    ->whereColumn('account_lifecycle_holds.operation_id', 'account_lifecycle_operations.id')->whereNull('released_at');
            })
            ->where('prepared_expires_at', '<=', now())->orderBy('id')->limit(100)->get()->each(function (AccountLifecycleOperation $operation): void {
                DB::transaction(function () use ($operation): void {
                    $locked = AccountLifecycleOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
                    if ($locked->state !== AccountLifecycleOperation::STATE_PREPARED || $locked->prepared_expires_at->isFuture() || $locked->hasActiveHold()) {
                        return;
                    }
                    $locked->forceFill(['state' => AccountLifecycleOperation::STATE_ABORTED, 'cleanup_state' => 'complete'])->save();
                    $locked->credential()->delete();
                });
            });
        AccountLifecycleCredential::query()->where('expires_at', '<=', now())
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_operations')
                    ->whereColumn('account_lifecycle_operations.id', 'account_lifecycle_credentials.operation_id')
                    ->whereIn('last_error_code', ['ledger_commit_pending', 'ledger_commit_failed']);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_holds')
                    ->whereColumn('account_lifecycle_holds.operation_id', 'account_lifecycle_credentials.operation_id')->whereNull('released_at');
            })->orderBy('operation_id')->limit(100)->get()
            ->each(function (AccountLifecycleCredential $credential) use ($vault, &$failed): void {
                try {
                    DB::transaction(function () use ($credential, $vault): void {
                        $operation = AccountLifecycleOperation::query()->whereKey($credential->operation_id)->lockForUpdate()->firstOrFail();
                        if ($operation->hasActiveHold() || in_array($operation->last_error_code, ['ledger_commit_pending', 'ledger_commit_failed'], true)) {
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
            });
        AccountLifecycleOperation::query()->where('state', AccountLifecycleOperation::STATE_COMMITTED)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('account_lifecycle_holds')
                    ->whereColumn('account_lifecycle_holds.operation_id', 'account_lifecycle_operations.id')->whereNull('released_at');
            })
            ->where(fn ($query) => $query->where('cleanup_state', '!=', 'complete')->orWhereIn('last_error_code', ['ledger_commit_pending', 'ledger_commit_failed']))
            ->orderBy('updated_at')->orderBy('id')->limit(20)->get()
            ->each(function (AccountLifecycleOperation $operation) use ($cleanup, &$failed): void {
                try {
                    $cleanup->process($operation);
                } catch (Throwable $exception) {
                    $failed++;
                    $operation->touch();
                    OperationLog::write('lifecycle.cleanup_deferred', 'failure', ['operation_id' => $operation->id], 'critical');
                    report($exception);
                }
            });
        if (! $images->processSafely()) {
            $failed++;
        }

        $this->info("後処理失敗・延期: {$failed} 件");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
