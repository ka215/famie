<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Str;
use RuntimeException;

class OperationState
{
    public function directory(): string
    {
        $path = storage_path('app/operations');
        if (is_link($path) || (! is_dir($path) && ! mkdir($path, 0700, true))) {
            throw new RuntimeException('Operation state unavailable.');
        }

        return $path;
    }

    /** @return array<string, mixed> */
    public function read(string $name): array
    {
        $path = $this->path($name);
        if (! file_exists($path)) {
            return [];
        }
        if (is_link($path) || filesize($path) > 1048576) {
            throw new RuntimeException('Invalid operation state.');
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid operation state.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function write(string $name, array $data): void
    {
        $path = $this->path($name);
        if (is_link($path)) {
            throw new RuntimeException('Invalid operation state.');
        }
        $temporary = tempnam($this->directory(), '.state-');
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('Operation state write failed.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function locked(string $name, Closure $callback): mixed
    {
        $path = $this->path($name).'.lock';
        if (is_link($path)) {
            throw new RuntimeException('Invalid operation lock.');
        }
        $handle = fopen($path, 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Operation already running.');
        }
        chmod($path, 0600);
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $name): string
    {
        if (! in_array($name, ['monitor', 'backup', 'image-deletions'], true)) {
            throw new RuntimeException('Unknown operation state.');
        }

        return $this->directory().'/'.$name.'.json';
    }

    /** @param array<string, string> $results */
    public function recordHistory(array $results): void
    {
        $safe = [];
        foreach ($results as $check => $severity) {
            if (in_array($check, InternalMonitor::CHECKS, true) && in_array($severity, ['ok', 'warning', 'critical'], true)) {
                $safe[$check] = $severity;
            }
        }
        $path = $this->directory().'/monitor-'.now()->utc()->format('Y-m-d').'.jsonl';
        $id = app()->bound('operation.run_id') ? app('operation.run_id') : (string) Str::uuid();
        if (is_link($path) || (is_file($path) && filesize($path) > 1048576)
            || file_put_contents($path, json_encode(['at' => now()->utc()->toIso8601String(), 'run_id' => $id, 'results' => $safe], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Monitor history unavailable.');
        }
        chmod($path, 0600);
    }

    public function prune(): void
    {
        foreach (glob($this->directory().'/monitor-*.jsonl') ?: [] as $path) {
            if (! is_link($path) && preg_match('/\Amonitor-(\d{4}-\d{2}-\d{2})\.jsonl\z/D', basename($path), $match)
                && $match[1] < now()->utc()->subDays(30)->format('Y-m-d')) {
                unlink($path);
            }
        }
        foreach ([...(glob(storage_path('logs/operations-*.log')) ?: []), ...(glob(storage_path('logs/laravel-*.log')) ?: []), ...(glob(storage_path('logs/emergency-*.log')) ?: [])] as $path) {
            if (! is_link($path) && preg_match('/\A(?:operations|laravel|emergency)-(\d{4}-\d{2}-\d{2})\.log\z/D', basename($path), $match)
                && $match[1] < now()->utc()->subDays(14)->format('Y-m-d')) {
                unlink($path);
            }
        }
    }
}
