<?php

namespace App\Console\Commands;

use App\Services\DailyBackup;
use App\Services\OperationState;
use App\Support\OperationLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('famie:run-operation {job : backup or image-deletions}')]
#[Description('Run a bounded private daily operation and record its completion')]
class RunOperation extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OperationState $state, DailyBackup $backup): int
    {
        $job = $this->argument('job');
        if (! config('operations.enabled') || ! in_array($job, ['backup', 'image-deletions'], true)) {
            $this->error('Operation disabled or unknown.');

            return self::FAILURE;
        }
        $id = (string) Str::uuid();
        app()->instance('operation.run_id', $id);
        try {
            return $state->locked($job, function () use ($state, $backup, $job, $id): int {
                $started = now()->timestamp;
                $state->write($job, ['started_at' => $started, 'run_id' => $id, 'outcome' => 'started']);
                OperationLog::write('operation.started', 'started', ['job' => $job]);
                try {
                    $reference = null;
                    if ($job === 'backup') {
                        $reference = $backup->run();
                    } else {
                        $result = Process::path(base_path())->timeout(240)->run([PHP_BINARY, 'artisan', 'famie:retry-image-deletions', '--no-interaction']);
                        if (! $result->successful()) {
                            throw new RuntimeException('Image deletion retry failed.');
                        }
                    }
                    $state->write($job, ['started_at' => $started, 'finished_at' => now()->timestamp, 'outcome' => 'success', 'run_id' => $id, 'backup_id' => $reference]);
                    OperationLog::write('operation.finished', 'success', ['job' => $job, 'duration_ms' => (now()->timestamp - $started) * 1000]);
                    $this->info('Operation completed. Run ID: '.$id);

                    return self::SUCCESS;
                } catch (Throwable $exception) {
                    $state->write($job, ['started_at' => $started, 'finished_at' => now()->timestamp, 'outcome' => 'failure', 'run_id' => $id]);
                    OperationLog::write('operation.failed', 'failure', ['job' => $job, 'exception_type' => $exception::class], 'error');
                    $this->error('Operation failed. Run ID: '.$id);

                    return self::FAILURE;
                }
            });
        } catch (Throwable) {
            $this->error('Operation state unavailable or already locked.');

            return self::FAILURE;
        }
    }
}
