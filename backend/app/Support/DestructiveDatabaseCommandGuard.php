<?php

namespace App\Support;

use Illuminate\Database\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

class DestructiveDatabaseCommandGuard
{
    /** @var list<string> */
    public const COMMANDS = ['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback'];

    /** @param array<string, mixed> $connection
     * @param  array<string, mixed>  $profile
     */
    public static function assertAllowed(?string $command, string $environment, array $connection, array $profile): void
    {
        if (! in_array($command, self::COMMANDS, true)) {
            return;
        }
        self::assertTestConnection($environment, $connection, $profile);
    }

    /** @param array<string, mixed> $connection
     * @param  array<string, mixed>  $profile
     */
    public static function assertTestConnection(string $environment, array $connection, array $profile): void
    {
        if ($environment !== 'testing' || $profile === []) {
            throw new RuntimeException('Database safety: an explicit isolated testing profile is required.');
        }
        try {
            $resolved = (new ConfigurationUrlParser)->parseConfiguration($connection);
        } catch (Throwable) {
            throw new RuntimeException('Database safety: invalid connection configuration.');
        }
        foreach (['read', 'write', 'direct', 'options', 'pooled', 'connect_via_database', 'connect_via_port'] as $key) {
            if (! empty($resolved[$key]) || (str_starts_with($key, 'connect_via_') && array_key_exists($key, $resolved))) {
                throw new RuntimeException('Database safety: alternate connection options are not permitted in tests.');
            }
        }
        if (($resolved['driver'] ?? null) !== ($profile['driver'] ?? null)) {
            throw new RuntimeException('Database safety: connection driver does not match the testing profile.');
        }
        if (($resolved['driver'] ?? null) === 'pgsql') {
            foreach (['charset', 'application_name', 'sslmode', 'sslcert', 'sslkey', 'sslrootcert', 'keepalives', 'keepalives_idle', 'keepalives_interval', 'keepalives_count'] as $key) {
                if (isset($resolved[$key]) && (! is_scalar($resolved[$key]) || preg_match('/[;\x00-\x1f\x27\\\\]/', (string) $resolved[$key]))) {
                    throw new RuntimeException('Database safety: unsafe PostgreSQL connection option.');
                }
            }
            foreach (['host', 'port', 'database', 'username'] as $key) {
                if (! is_scalar($resolved[$key] ?? null) || ! isset($profile[$key])
                    || (string) $resolved[$key] !== (string) $profile[$key]) {
                    throw new RuntimeException('Database safety: PostgreSQL target does not match the testing profile.');
                }
            }

            return;
        }
        if (($resolved['driver'] ?? null) === 'sqlite') {
            $database = $resolved['database'] ?? null;
            if ($database === ':memory:' && ($profile['database'] ?? null) === ':memory:') {
                return;
            }
            $root = $profile['root'] ?? null;
            if (is_string($database) && is_string($root) && self::isIsolatedFile($database, $root)) {
                return;
            }
        }

        throw new RuntimeException('Database safety: database is not an approved isolated test target.');
    }

    private static function isIsolatedFile(string $database, string $root): bool
    {
        if (! is_file($database) || ! is_dir($root)) {
            return false;
        }
        $resolvedFile = realpath($database);
        $resolvedRoot = realpath($root);
        if ($resolvedFile === false || $resolvedRoot === false
            || self::normalize($database) !== self::normalize($resolvedFile)
            || self::normalize($root) !== self::normalize($resolvedRoot)
            || ! str_starts_with(self::normalize($resolvedFile), self::normalize($resolvedRoot).'/')) {
            return false;
        }
        for ($path = $database; dirname($path) !== $path; $path = dirname($path)) {
            if (is_link($path)) {
                return false;
            }
        }

        return true;
    }

    private static function normalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
