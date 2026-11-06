<?php

namespace App\Console\Commands;

use App\Services\InternalMonitor;
use App\Services\LifecycleProcessContext;
use App\Services\MonitorNotifier;
use App\Services\OperationState;
use App\Support\OperationLog;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

#[Signature('famie:run-lifecycle-cleanup {--notify-run-id= : Internal notification phase for an existing cleanup run} {--isolated-context : Require the isolated process testing context}')]
class RunLifecycleCleanup extends Command
{
    public function handle(OperationState $state, MonitorNotifier $notifier, InternalMonitor $monitor, LifecycleProcessContext $context): int
    {
        try {
            $environment = $context->environment((bool) $this->option('isolated-context'));
        } catch (Throwable) {
            $this->error('Lifecycle child context is not isolated.');

            return self::FAILURE;
        }
        if (! config('operations.enabled') || ! config('famie.lifecycle_monitor_enabled')) {
            $this->error('Lifecycle scheduled runner is disabled.');

            return self::FAILURE;
        }
        if ($this->option('notify-run-id') !== null) {
            $id = (string) $this->option('notify-run-id');
            if (! Str::isUuid($id)) {
                return self::FAILURE;
            }
            try {
                $record = $state->read('lifecycle-cleanup-job');
                if (($record['run_id'] ?? null) !== $id || ($record['notification_outcome'] ?? null) !== 'pending'
                    || ! in_array($record['outcome'] ?? null, ['success', 'failure'], true)) {
                    return self::FAILURE;
                }

                return $notifier->lifecycleRun($record['outcome'] === 'success' ? $monitor->lifecycleCleanup() : 'critical', $id, $state)
                    ? self::SUCCESS : self::FAILURE;
            } catch (Throwable) {
                return self::FAILURE;
            }
        }
        try {
            return $state->locked('lifecycle-cleanup-job', function () use ($state, $environment): int {
                $started = now()->timestamp;
                $monotonic = hrtime(true);
                $id = (string) Str::uuid();
                $state->write('lifecycle-cleanup-job', ['started_at' => $started, 'outcome' => 'started', 'run_id' => $id]);
                $success = false;
                try {
                    $success = Process::path(base_path())->env($environment)->timeout(120)->run([
                        PHP_BINARY, 'artisan', 'famie:cleanup-account-lifecycle', '--no-interaction',
                        ...($environment === [] ? [] : ['--isolated-context']),
                    ])->successful();
                } catch (Throwable) {
                    $success = false;
                }
                $record = ['started_at' => $started, 'finished_at' => now()->timestamp, 'run_id' => $id, 'outcome' => $success ? 'success' : 'failure',
                    'worker_duration_ms' => (int) ((hrtime(true) - $monotonic) / 1000000), 'notification_outcome' => 'pending'];
                $state->write('lifecycle-cleanup-job', $record);
                $notified = false;
                try {
                    $notified = Process::path(base_path())->env($environment)->timeout(15)->run([
                        PHP_BINARY, 'artisan', 'famie:run-lifecycle-cleanup', '--notify-run-id='.$id, '--no-interaction',
                        ...($environment === [] ? [] : ['--isolated-context']),
                    ])->successful();
                } catch (Throwable) {
                    $notified = false;
                }
                if (! $notified) {
                    OperationLog::write('monitor.notification_failed', 'failure', [], 'warning');
                }
                $record['notification_outcome'] = $notified ? 'success' : 'failure';
                $record['total_duration_ms'] = (int) ((hrtime(true) - $monotonic) / 1000000);
                $state->write('lifecycle-cleanup-job', $record);

                return $success && $notified ? self::SUCCESS : self::FAILURE;
            });
        } catch (Throwable) {
            $this->error('Lifecycle scheduled runner state unavailable or already locked.');

            return self::FAILURE;
        }
    }
}
