<?php

namespace App\Console\Commands;

use App\Services\DailyBackup;
use App\Services\InternalMonitor;
use App\Services\MonitorNotifier;
use App\Services\OperationState;
use App\Support\OperationLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

#[Signature('famie:monitor-internal {--test-notification : Send a test email without changing incident state}')]
#[Description('Check private application health without external uptime requests')]
class MonitorInternal extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OperationState $state, InternalMonitor $monitor, MonitorNotifier $notifier): int
    {
        if (! config('operations.enabled')) {
            $this->error('Internal monitoring is disabled.');

            return self::FAILURE;
        }
        $id = (string) Str::uuid();
        app()->instance('operation.run_id', $id);
        if ($this->option('test-notification')) {
            return $notifier->send('database', 'test', $id) ? self::SUCCESS : self::FAILURE;
        }
        try {
            return $state->locked('monitor', function () use ($state, $monitor, $notifier, $id): int {
                $previous = $state->read('monitor');
                OperationLog::write('operation.started', 'started');
                $results = $monitor->check();
                try {
                    $state->prune();
                    app(DailyBackup::class)->prune();
                    $results['housekeeping'] = 'ok';
                } catch (Throwable) {
                    $results['housekeeping'] = 'warning';
                }
                $incidents = $previous['incidents'] ?? [];
                foreach ($results as $check => $severity) {
                    $incidents[$check] = $notifier->update($check, $severity, $incidents[$check] ?? [], $id);
                }
                $state->write('monitor', [
                    'checked_at' => now()->timestamp, 'run_id' => $id, 'results' => $results, 'incidents' => $incidents,
                    'capacity_checked_at' => isset($results['capacity']) ? now()->timestamp : ($previous['capacity_checked_at'] ?? 0),
                ]);
                $state->recordHistory($results);
                $failed = count(array_filter($results, fn (string $severity): bool => $severity !== 'ok'));
                OperationLog::write($failed ? 'operation.failed' : 'operation.finished', $failed ? 'failure' : 'success', ['count' => $failed], $failed ? 'warning' : 'info');
                $this->info('Internal monitor finished. Run ID: '.$id);

                return $failed ? self::FAILURE : self::SUCCESS;
            });
        } catch (Throwable $exception) {
            OperationLog::write('operation.failed', 'failure', ['exception_type' => $exception::class], 'error');
            $this->error('Internal monitor failed. Inspect private operation logs.');

            return self::FAILURE;
        }
    }
}
