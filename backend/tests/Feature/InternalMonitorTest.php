<?php

namespace Tests\Feature;

use App\Services\InternalMonitor;
use App\Services\OperationState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Tests\Support\IsolatesOperationsStorage;
use Tests\TestCase;

class InternalMonitorTest extends TestCase
{
    use IsolatesOperationsStorage, RefreshDatabase;

    public function test_missing_job_results_are_not_healthy_and_recent_results_are_healthy(): void
    {
        $this->freezeTime();
        $this->isolateOperationsStorage();
        $state = app(OperationState::class);
        $results = app(InternalMonitor::class)->check();
        $this->assertSame('ok', $results['database']);
        $this->assertSame('ok', $results['storage']);
        $this->assertSame('warning', $results['backup']);
        foreach (['backup', 'image-deletions'] as $job) {
            $state->write($job, ['outcome' => 'success', 'finished_at' => now()->timestamp]);
        }
        $this->assertSame('ok', app(InternalMonitor::class)->check()['backup']);
        $this->travel(27)->hours();
        $this->assertSame('warning', app(InternalMonitor::class)->check()['backup']);
    }

    public function test_error_counts_ignore_old_events_and_detect_current_thresholds(): void
    {
        $this->freezeTime();
        $path = $this->isolateOperationsStorage();
        $log = $path.'/logs/operations-'.now()->utc()->format('Y-m-d').'.log';
        $old = json_encode(['datetime' => now()->subMinutes(61)->toIso8601String(), 'message' => 'security.rate_limited'])."\n";
        $recent = json_encode(['datetime' => now()->subMinutes(6)->toIso8601String(), 'message' => 'application.exception'])."\n";
        file_put_contents($log, str_repeat($old, 25).str_repeat($recent, 5));
        $results = app(InternalMonitor::class)->check();
        $this->assertSame('warning', $results['server_errors']);
        $this->assertSame('ok', $results['rate_limits']);
        $this->assertSame('ok', $results['log_integrity']);
        file_put_contents($log, "not-json\n", FILE_APPEND);
        $this->assertSame('warning', app(InternalMonitor::class)->check()['log_integrity']);
    }

    public function test_capacity_is_checked_against_the_famie_budget_and_process_failures_are_not_healthy(): void
    {
        $this->isolateOperationsStorage();
        config(['operations.quota_bytes' => 100 * 1024, 'operations.quota_roots' => [base_path()]]);
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result(output: "90\tprivate-path\n")]);
        $this->assertSame('critical', app(InternalMonitor::class)->check()['capacity']);
        Process::assertRan(fn ($process): bool => $process->command === ['du', '-sk', '--', realpath(base_path())]);
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $this->assertSame('warning', app(InternalMonitor::class)->check()['capacity']);
        Process::assertRan(fn ($process): bool => $process->command === ['du', '-sk', '--', realpath(base_path())]);
    }

    public function test_monitor_is_disabled_by_default_and_records_enabled_runs_privately(): void
    {
        $this->freezeTime();
        $this->isolateOperationsStorage();
        Mail::fake();
        config(['operations.enabled' => false]);
        $this->artisan('famie:monitor-internal')->assertFailed();
        $this->assertSame([], app(OperationState::class)->read('monitor'));
        config(['operations.enabled' => true, 'operations.recipient' => 'operator@example.test']);
        $this->artisan('famie:monitor-internal')->assertFailed();
        $record = app(OperationState::class)->read('monitor');
        $this->assertSame(now()->timestamp, $record['checked_at']);
        $this->assertSame('warning', $record['results']['backup']);
        Mail::assertSentCount(3);
    }

    public function test_partial_daily_runs_remain_failed_and_overdue_image_deletions_are_reported(): void
    {
        $this->isolateOperationsStorage();
        $this->freezeTime();
        app(OperationState::class)->write('backup', ['outcome' => 'started', 'started_at' => now()->timestamp]);
        DB::table('activity_image_deletions')->insert(['disk' => 'local', 'path' => 'private-image', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $results = app(InternalMonitor::class)->check();
        $this->assertSame('warning', $results['backup']);
        $this->assertSame('warning', $results['image-deletions']);
    }

    public function test_state_lock_and_corrupt_state_fail_closed(): void
    {
        $path = $this->isolateOperationsStorage();
        $state = app(OperationState::class);
        $state->locked('monitor', function () use ($state): void {
            try {
                $state->locked('monitor', fn () => $this->fail('A second job entered the lock.'));
                $this->fail('Expected lock rejection.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Operation already running.', $exception->getMessage());
            }
        });
        file_put_contents($path.'/app/operations/monitor.json', 'invalid');
        $this->expectException(\JsonException::class);
        $state->read('monitor');
    }

    public function test_retention_prunes_only_expired_operation_files(): void
    {
        $path = $this->isolateOperationsStorage();
        $this->freezeTime();
        $state = app(OperationState::class);
        $oldHistory = $state->directory().'/monitor-'.now()->subDays(31)->format('Y-m-d').'.jsonl';
        $oldLog = $path.'/logs/operations-'.now()->subDays(15)->format('Y-m-d').'.log';
        file_put_contents($oldHistory, 'expired');
        file_put_contents($oldLog, 'expired');
        file_put_contents($path.'/logs/laravel.log', 'keep-existing');
        $state->recordHistory(['database' => 'ok', 'private-token' => 'private-value']);
        $state->prune();
        $this->assertFileDoesNotExist($oldHistory);
        $this->assertFileDoesNotExist($oldLog);
        $this->assertFileExists($path.'/logs/laravel.log');
        $history = file_get_contents($state->directory().'/monitor-'.now()->format('Y-m-d').'.jsonl');
        $this->assertStringNotContainsString('private-', $history);
    }

    public function test_capacity_uses_one_bounded_scan_and_rejects_partial_results(): void
    {
        $path = $this->isolateOperationsStorage();
        mkdir($path.'/root-a');
        mkdir($path.'/root-b');
        config(['operations.quota_bytes' => 100 * 1024, 'operations.quota_roots' => [$path.'/root-a', $path.'/root-b']]);
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result(output: "87\troot-a\n5\troot-b\n")]);

        $this->assertSame('critical', app(InternalMonitor::class)->check()['capacity']);
        Process::assertRan(fn ($process): bool => $process->command === ['du', '-sk', '--', realpath($path.'/root-a'), realpath($path.'/root-b')] && $process->timeout === 30);

        Process::fake(['*' => Process::result(output: "1\troot-a\n")]);
        $this->assertSame('warning', app(InternalMonitor::class)->check()['capacity']);
    }

    public function test_hourly_log_window_includes_the_previous_utc_day(): void
    {
        $path = $this->isolateOperationsStorage();
        $this->travelTo(CarbonImmutable::parse('2026-10-30T00:30:00Z'));
        $line = json_encode(['datetime' => '2026-10-29T23:50:00Z', 'message' => 'application.exception'])."\n";
        file_put_contents($path.'/logs/operations-2026-10-29.log', str_repeat($line, 5));

        $this->assertSame('warning', app(InternalMonitor::class)->check()['server_errors']);
    }

    public function test_hourly_schedule_preserves_daily_jobs_and_does_not_run_at_five_minute_slots(): void
    {
        config(['operations.enabled' => true, 'operations.backup_time' => '03:00']);
        require base_path('routes/console.php');
        $schedule = app(Schedule::class);

        foreach ([
            '2026-10-30T12:00:00Z' => ['famie:monitor-internal'],
            '2026-10-30T12:05:00Z' => [],
            '2026-10-30T18:00:00Z' => ['famie:monitor-internal', 'famie:run-operation backup'],
            '2026-10-30T19:00:00Z' => ['famie:monitor-internal', 'famie:run-operation image-deletions'],
        ] as $at => $expected) {
            $this->travelTo(CarbonImmutable::parse($at));
            $actual = [];
            foreach ($schedule->events() as $event) {
                foreach (['famie:monitor-internal', 'famie:run-operation backup', 'famie:run-operation image-deletions'] as $command) {
                    if (str_contains($event->command ?? '', $command) && $event->isDue(app())) {
                        $actual[] = $command;
                    }
                }
            }
            $this->assertSame($expected, $actual);
        }
    }
}
