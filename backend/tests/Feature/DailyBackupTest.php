<?php

namespace Tests\Feature;

use App\Services\DailyBackup;
use App\Services\OperationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Support\IsolatesOperationsStorage;
use Tests\TestCase;

class DailyBackupTest extends TestCase
{
    use IsolatesOperationsStorage, RefreshDatabase;

    public function test_daily_jobs_reject_disabled_or_unknown_operations(): void
    {
        $this->isolateOperationsStorage();
        config(['operations.enabled' => false]);
        $this->artisan('famie:run-operation backup')->assertFailed();
        $this->assertSame([], app(OperationState::class)->read('backup'));
        config(['operations.enabled' => true]);
        $this->artisan('famie:run-operation arbitrary')->assertFailed();
    }

    public function test_failed_backup_records_failure_instead_of_an_old_success(): void
    {
        $this->isolateOperationsStorage();
        config(['operations.enabled' => true]);
        app(OperationState::class)->write('backup', ['outcome' => 'success', 'finished_at' => now()->timestamp]);
        $this->artisan('famie:run-operation backup')->assertFailed();
        $state = app(OperationState::class)->read('backup');
        $this->assertSame('failure', $state['outcome']);
        $this->assertArrayHasKey('finished_at', $state);
    }

    public function test_image_retry_is_bounded_and_records_process_failure_without_output_secrets(): void
    {
        $this->isolateOperationsStorage();
        config(['operations.enabled' => true]);
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result(output: 'private-image-path', errorOutput: 'private-password', exitCode: 1)]);
        $this->artisan('famie:run-operation image-deletions')->assertFailed();
        $state = app(OperationState::class)->read('image-deletions');
        $this->assertSame('failure', $state['outcome']);
        $this->assertStringNotContainsString('private-', json_encode($state));
        Process::assertRan(fn ($process): bool => $process->timeout === 240 && $process->command === [PHP_BINARY, 'artisan', 'famie:retry-image-deletions', '--no-interaction']);
    }

    public function test_successful_image_retry_records_a_complete_result(): void
    {
        $this->isolateOperationsStorage();
        config(['operations.enabled' => true]);
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result(exitCode: 0)]);
        $this->artisan('famie:run-operation image-deletions')->assertSuccessful();
        $this->assertSame('success', app(OperationState::class)->read('image-deletions')['outcome']);
        Process::assertRan(fn ($process): bool => $process->command === [PHP_BINARY, 'artisan', 'famie:retry-image-deletions', '--no-interaction']);
    }

    public function test_backup_service_rejects_unsupported_database_without_creating_a_snapshot(): void
    {
        $this->isolateOperationsStorage();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Daily backup requires PostgreSQL.');
        app(DailyBackup::class)->run();
    }

    public function test_backup_retention_deletes_only_expired_daily_snapshots_and_partial_runs(): void
    {
        $this->isolateOperationsStorage();
        $this->freezeTime();
        $root = app(OperationState::class)->directory().'/backups';
        mkdir($root, 0700);
        $old = $root.'/'.now()->subDays(8)->format('Ymd').'-030000-21aa889b-61db-4f96-96c2-c74680c208d9';
        $recent = $root.'/'.now()->format('Ymd').'-030000-21aa889b-61db-4f96-96c2-c74680c208d9';
        $partial = $root.'/.partial-'.now()->subDays(8)->format('Ymd').'-030000-21aa889b-61db-4f96-96c2-c74680c208d9';
        foreach ([$old, $recent, $partial] as $directory) {
            mkdir($directory, 0700);
            file_put_contents($directory.'/manifest.json', '{}');
        }
        mkdir($root.'/release-backup-keep', 0700);
        app(DailyBackup::class)->prune();
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryDoesNotExist($partial);
        $this->assertDirectoryExists($recent);
        $this->assertDirectoryExists($root.'/release-backup-keep');
    }
}
