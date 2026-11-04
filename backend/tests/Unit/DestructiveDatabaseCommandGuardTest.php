<?php

namespace Tests\Unit;

use App\Support\DestructiveDatabaseCommandGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DestructiveDatabaseCommandGuardTest extends TestCase
{
    #[DataProvider('unsafeTargets')]
    public function test_destructive_commands_are_rejected_for_non_test_databases(string $environment, string $database): void
    {
        $this->expectException(RuntimeException::class);
        DestructiveDatabaseCommandGuard::assertAllowed('migrate:fresh', $environment, ['driver' => 'pgsql', 'database' => $database], self::postgresProfile());
    }

    /** @return array<string, array{string, string}> */
    public static function unsafeTargets(): array
    {
        return [
            'testing environment does not make a development database safe' => ['testing', 'famie_db'],
            'local environment cannot wipe even a test-named database' => ['local', 'famie_test'],
            'empty database is unsafe' => ['testing', ''],
        ];
    }

    #[DataProvider('safeTargets')]
    public function test_testing_environment_allows_only_dedicated_test_databases(array $connection, array $profile): void
    {
        DestructiveDatabaseCommandGuard::assertAllowed('migrate:fresh', 'testing', $connection, $profile);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function safeTargets(): array
    {
        return [
            'memory sqlite' => [['driver' => 'sqlite', 'database' => ':memory:'], ['driver' => 'sqlite', 'database' => ':memory:']],
            'explicit postgres target' => [self::postgresProfile(), self::postgresProfile()],
        ];
    }

    public function test_non_destructive_commands_are_not_restricted(): void
    {
        DestructiveDatabaseCommandGuard::assertAllowed('migrate', 'local', ['driver' => 'pgsql', 'database' => 'famie_db'], []);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('redirectedConnections')]
    public function test_redirected_connections_are_rejected_before_connecting(array $overrides): void
    {
        $this->expectException(RuntimeException::class);

        DestructiveDatabaseCommandGuard::assertTestConnection('testing', array_replace(self::postgresProfile(), $overrides), self::postgresProfile());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function redirectedConnections(): array
    {
        return [
            'real database with testing environment' => [['database' => 'famie_db']],
            'test suffix is insufficient' => [['database' => 'another_test']],
            'development port' => [['port' => '5432']],
            'other host' => [['host' => 'localhost']],
            'privileged username' => [['username' => 'postgres']],
            'host failover list' => [['host' => ['127.0.0.1', 'production']]],
            'url overrides database' => [['url' => 'postgres://famie_test_runner:secret@127.0.0.1:55432/famie_db']],
            'url query overrides database' => [['url' => 'postgres://famie_test_runner:secret@127.0.0.1:55432/famie_test?database=famie_db']],
            'read endpoint' => [['read' => ['database' => 'famie_db']]],
            'write endpoint' => [['write' => ['database' => 'famie_db']]],
            'direct endpoint' => [['direct' => ['database' => 'famie_db']]],
            'pooled database' => [['connect_via_database' => 'famie_db']],
            'pooled port' => [['connect_via_port' => '5432']],
            'empty pooled database falls back to libpq defaults' => [['connect_via_database' => '']],
            'empty pooled port falls back to libpq defaults' => [['connect_via_port' => '']],
            'pooled connection' => [['pooled' => true]],
            'pdo option' => [['options' => [1002 => 'DROP TABLE users']]],
            'ssl dsn injection' => [['sslmode' => 'prefer;dbname=famie_db']],
            'encoding dsn injection' => [['charset' => "utf8';dbname=famie_db"]],
            'invalid url' => [['url' => 'postgres://[invalid']],
        ];
    }

    #[DataProvider('destructiveCommands')]
    public function test_all_destructive_commands_require_isolation(string $command): void
    {
        $this->expectException(RuntimeException::class);

        DestructiveDatabaseCommandGuard::assertAllowed($command, 'local', self::postgresProfile(), self::postgresProfile());
    }

    /** @return array<string, array{string}> */
    public static function destructiveCommands(): array
    {
        return [
            'wipe' => ['db:wipe'], 'fresh' => ['migrate:fresh'], 'refresh' => ['migrate:refresh'],
            'reset' => ['migrate:reset'], 'rollback' => ['migrate:rollback'],
        ];
    }

    public function test_sqlite_file_outside_allowed_directory_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        DestructiveDatabaseCommandGuard::assertTestConnection('testing', [
            'driver' => 'sqlite', 'database' => __FILE__,
        ], ['driver' => 'sqlite', 'root' => __DIR__.'/../Feature']);
    }

    public function test_unknown_profile_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        DestructiveDatabaseCommandGuard::assertTestConnection('testing', self::postgresProfile(), []);
    }

    /** @return array<string, string> */
    private static function postgresProfile(): array
    {
        return ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432', 'database' => 'famie_test', 'username' => 'famie_test_runner'];
    }
}
