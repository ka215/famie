<?php

namespace App\Support;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class LifecycleStoragePath
{
    public static function root(string $configurationKey, string $messageKey): string
    {
        $configured = config($configurationKey);
        if (! is_string($configured) || $configured === ''
            || (! str_starts_with($configured, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) !== 1)) {
            throw new ServiceUnavailableHttpException(null, __($messageKey));
        }
        $root = realpath($configured);
        if ($root === false || ! is_dir($root) || ! is_writable($root) || self::normalize($configured) !== self::normalize($root)) {
            throw new ServiceUnavailableHttpException(null, __($messageKey));
        }
        for ($path = $configured; dirname($path) !== $path; $path = dirname($path)) {
            if (is_link($path)) {
                throw new ServiceUnavailableHttpException(null, __($messageKey));
            }
        }
        foreach ([base_path(), public_path(), storage_path(), base_path('../frontend'), base_path('../.release'), (string) config('filesystems.disks.'.config('famie.image_disk').'.root')] as $forbidden) {
            $resolved = realpath($forbidden);
            if ($resolved !== false && (self::normalize($root) === self::normalize($resolved)
                || str_starts_with(self::normalize($root).'/', self::normalize($resolved).'/'))) {
                throw new ServiceUnavailableHttpException(null, __($messageKey));
            }
        }

        return $root;
    }

    public static function assertRegularFile(string $path, string $messageKey): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            throw new ServiceUnavailableHttpException(null, __($messageKey));
        }
    }

    private static function normalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
