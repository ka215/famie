<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

class InternalMonitor
{
    public const CHECKS = ['database', 'storage', 'capacity', 'backup', 'image-deletions', 'server_errors', 'authentication_failures', 'rate_limits', 'permission_denials', 'log_integrity', 'housekeeping'];

    public function __construct(private OperationState $state) {}

    /** @return array<string, string> */
    public function check(): array
    {
        $results = [];
        try {
            DB::select('select 1');
            $results['database'] = 'ok';
        } catch (Throwable) {
            $results['database'] = 'critical';
        }
        try {
            $path = $this->state->directory();
            $probe = tempnam($path, '.probe-');
            $written = file_put_contents($probe, 'probe');
            $deleted = unlink($probe);
            $results['storage'] = $written === 5 && $deleted && is_writable(storage_path('logs')) ? 'ok' : 'critical';
        } catch (Throwable) {
            $results['storage'] = 'critical';
        }
        foreach (['backup', 'image-deletions'] as $job) {
            try {
                $state = $this->state->read($job);
                $results[$job] = ($state['outcome'] ?? null) === 'success' && ($state['finished_at'] ?? 0) > now()->subHours(26)->timestamp ? 'ok' : 'warning';
            } catch (Throwable) {
                $results[$job] = 'critical';
            }
        }
        try {
            if (DB::table('activity_image_deletions')->where('created_at', '<', now()->subDay())->exists()) {
                $results['image-deletions'] = 'warning';
            }
        } catch (Throwable) {
            $results['image-deletions'] = 'critical';
        }
        $previous = $this->state->read('monitor');
        if (($previous['capacity_checked_at'] ?? 0) <= now()->subHour()->timestamp) {
            $results['capacity'] = $this->capacity();
        }

        return [...$results, ...$this->logCounts()];
    }

    private function capacity(): string
    {
        $quota = config('operations.quota_bytes');
        $roots = config('operations.quota_roots');
        if (! is_int($quota) || $quota <= 0 || ! is_array($roots) || $roots === []) {
            return 'warning';
        }
        try {
            $used = 0;
            $resolved = [];
            foreach ($roots as $root) {
                if (! is_string($root) || ! is_dir($root) || is_link($root)) {
                    return 'warning';
                }
                $path = realpath($root);
                foreach ($resolved as $previous) {
                    if ($path === $previous || str_starts_with($path, $previous.DIRECTORY_SEPARATOR) || str_starts_with($previous, $path.DIRECTORY_SEPARATOR)) {
                        return 'warning';
                    }
                }
                $resolved[] = $path;
                $result = Process::timeout(20)->run(['du', '-sk', '--', $path]);
                if (! $result->successful() || ! preg_match('/\A(\d+)\s/', $result->output(), $matches)) {
                    return 'warning';
                }
                $used += (int) $matches[1] * 1024;
            }
            $ratio = $used / $quota;

            return $ratio >= 0.9 ? 'critical' : ($ratio >= 0.8 ? 'warning' : 'ok');
        } catch (Throwable) {
            return 'warning';
        }
    }

    /** @return array<string, string> */
    private function logCounts(): array
    {
        $counts = ['server_errors' => 0, 'authentication_failures' => 0, 'rate_limits' => 0, 'permission_denials' => 0];
        $results = ['log_integrity' => 'ok'];
        $emergency = storage_path('logs/emergency-'.now()->utc()->format('Y-m-d').'.log');
        if (is_file($emergency) && filemtime($emergency) >= now()->subMinutes(5)->timestamp) {
            $results['log_integrity'] = 'warning';
        }
        $cutoff = now()->subMinutes(5)->timestamp;
        foreach (array_unique([now()->utc()->format('Y-m-d'), now()->utc()->subMinutes(5)->format('Y-m-d')]) as $date) {
            $path = storage_path('logs/operations-'.$date.'.log');
            if (! file_exists($path)) {
                continue;
            }
            if (is_link($path) || filesize($path) > 64 * 1024 * 1024) {
                $results['log_integrity'] = 'warning';

                continue;
            }
            $handle = fopen($path, 'r');
            if ($handle === false) {
                $results['log_integrity'] = 'critical';

                continue;
            }
            try {
                while (($line = fgets($handle, 16384)) !== false) {
                    $record = json_decode($line, true);
                    if (! is_array($record) || ! is_string($record['datetime'] ?? null)) {
                        $results['log_integrity'] = 'warning';

                        continue;
                    }
                    $time = strtotime($record['datetime']);
                    if ($time === false || $time < $cutoff || $time > now()->timestamp) {
                        continue;
                    }
                    $bucket = match ($record['message'] ?? null) {
                        'http.server_error', 'application.exception' => 'server_errors',
                        'auth.login_failed' => 'authentication_failures',
                        'security.rate_limited', 'security.login_locked' => 'rate_limits',
                        'security.permission_denied', 'security.resource_denied' => 'permission_denials',
                        default => null,
                    };
                    if ($bucket !== null) {
                        $counts[$bucket]++;
                    }
                }
            } finally {
                fclose($handle);
            }
        }
        foreach ($counts as $bucket => $count) {
            $results[$bucket] = $count >= ($bucket === 'server_errors' ? 5 : 20) ? 'warning' : 'ok';
        }

        return $results;
    }
}
