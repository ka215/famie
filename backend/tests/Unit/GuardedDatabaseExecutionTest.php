<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class GuardedDatabaseExecutionTest extends TestCase
{
    #[DataProvider('commands')]
    public function test_console_execution_rejects_selected_unsafe_connection_before_database_work(string $name): void
    {
        $app = $this->application();
        $migrator = $this->createMock(Migrator::class);
        $migrator->expects($this->never())->method('usingConnection');
        $command = match ($name) {
            'migrate:fresh' => new FreshCommand($migrator),
            'migrate:reset' => new ResetCommand($migrator),
            'migrate:rollback' => new RollbackCommand($migrator),
            'migrate:refresh' => new RefreshCommand,
            'db:wipe' => new WipeCommand,
            'migrate' => new MigrateCommand($migrator, new Dispatcher($app)),
        };
        $command->setLaravel($app);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database safety: PostgreSQL target does not match');

        $command->run(new ArrayInput(['--database' => 'unsafe', '--force' => true]), new BufferedOutput);
    }

    /** @return array<string, array{string}> */
    public static function commands(): array
    {
        return [
            'fresh' => ['migrate:fresh'], 'refresh' => ['migrate:refresh'], 'reset' => ['migrate:reset'],
            'rollback' => ['migrate:rollback'], 'wipe' => ['db:wipe'], 'forward migration in tests' => ['migrate'],
        ];
    }

    public function test_database_manager_rejects_url_redirect_before_pdo_creation(): void
    {
        $app = $this->application();
        $app['config']->set('database.connections.safe.url', 'postgres://famie_test_runner:secret@127.0.0.1:55432/famie_db');
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');
        $app->instance('db.connector.pgsql', $connector);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database safety: PostgreSQL target does not match');

        $app['db']->connection();
    }

    public function test_approved_connection_is_resolved_without_opening_pdo(): void
    {
        $app = $this->application();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');
        $app->instance('db.connector.pgsql', $connector);

        $connection = $app['db']->connection();

        $this->assertSame('famie_test', $connection->getDatabaseName());
    }

    public function test_cached_local_environment_is_rejected_during_a_test_run(): void
    {
        $app = $this->application();
        $app['config']->set('app.env', 'local');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database safety: an explicit isolated testing profile is required');

        $app['db']->connection();
    }

    public function test_approved_command_reaches_its_handler_without_database_work(): void
    {
        $app = $this->application();
        $command = $this->createMock(FreshCommand::class);
        $command->method('getName')->willReturn('migrate:fresh');
        $command->method('option')->with('database')->willReturn('safe');
        $command->expects($this->once())->method('handle')->willReturn(Command::SUCCESS);

        $result = $app->callMethodBinding(FreshCommand::class.'@handle', $command);

        $this->assertSame(Command::SUCCESS, $result);
    }

    private function application(): Application
    {
        $app = new Application(dirname(__DIR__, 2));
        $profile = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432', 'database' => 'famie_test', 'username' => 'famie_test_runner'];
        $app->instance('env', 'testing');
        $app->instance('config', new Repository([
            'app' => ['env' => 'testing'],
            'database' => ['default' => 'safe', 'connections' => [
                'safe' => $profile,
                'unsafe' => [...$profile, 'database' => 'famie_db'],
            ]],
            'database-safety' => ['test_run' => true, 'profile' => 'test', 'profiles' => ['test' => $profile]],
        ]));
        $app->register(DatabaseServiceProvider::class);
        $app->register(AppServiceProvider::class);

        return $app;
    }
}
