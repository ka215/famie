<?php

namespace App\Services;

class AccessLogRetention
{
    public function check(?string $basePath, int $checkedAt): string
    {
        if ($basePath === null || $basePath === '' || str_contains($basePath, "\0")
            || ! str_ends_with($basePath, '.log')) {
            return 'warning';
        }
        $parent = dirname($basePath);
        $resolved = realpath($parent);
        if ($resolved === false || str_replace('\\', '/', $resolved) !== str_replace('\\', '/', $parent)) {
            return 'warning';
        }

        $severity = 'ok';
        foreach (['', '.1', '.2', '.3', '.4'] as $suffix) {
            $path = $basePath.$suffix;
            clearstatcache(true, $path);
            $metadata = @lstat($path);
            if ($metadata === false || ($metadata['mode'] & 0170000) !== 0100000
                || $metadata['nlink'] !== 1 || ! is_readable($path) || $metadata['mtime'] > $checkedAt) {
                $severity = $severity === 'critical' ? 'critical' : 'warning';

                continue;
            }
            $age = $checkedAt - $metadata['mtime'];
            if ($age > 30 * 86400) {
                $severity = 'critical';
            } elseif ($age > 25 * 86400 && $severity === 'ok') {
                $severity = 'warning';
            }
        }

        return $severity;
    }
}
