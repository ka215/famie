<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\ConfigureSafeLogging;
use App\Support\OperationLog;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\IsolatesOperationsStorage;
use Tests\TestCase;

class OperationLoggingTest extends TestCase
{
    use IsolatesOperationsStorage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['famie.ip_restriction_enabled' => false, 'app.debug' => false]);
    }

    /** @return resource */
    private function captureLogs(string $channel = 'operations')
    {
        $stream = fopen('php://memory', 'w+');
        config(["logging.channels.{$channel}" => [
            'driver' => 'monolog', 'handler' => StreamHandler::class,
            'handler_with' => ['stream' => $stream], 'tap' => [ConfigureSafeLogging::class],
        ]]);
        Log::forgetChannel($channel);

        return $stream;
    }

    /** @param resource $stream @return list<array<string, mixed>> */
    private function records($stream): array
    {
        rewind($stream);
        $lines = array_filter(explode("\n", stream_get_contents($stream)));

        return array_values(array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines));
    }

    public function test_request_id_is_new_on_every_response_and_is_shared_with_rejection_logs(): void
    {
        $stream = $this->captureLogs();
        $ok = $this->withHeader('X-Request-ID', 'attacker-controlled')->getJson('/v1/status')->assertOk();
        $denied = $this->getJson('/v1/auth/me')->assertUnauthorized();
        $this->assertTrue(Str::isUuid($ok->headers->get('X-Request-ID')));
        $this->assertTrue(Str::isUuid($denied->headers->get('X-Request-ID')));
        $this->assertNotSame($ok->headers->get('X-Request-ID'), $denied->headers->get('X-Request-ID'));
        $records = $this->records($stream);
        $this->assertCount(1, $records);
        $this->assertSame('security.unauthenticated', $records[0]['message']);
        $this->assertSame($denied->headers->get('X-Request-ID'), $records[0]['context']['request_id']);
    }

    public function test_validation_not_found_ip_denial_throttle_and_cors_keep_request_id(): void
    {
        $this->captureLogs();
        $this->postJson('/v1/auth/login', [])->assertUnprocessable()->assertHeader('X-Request-ID');
        $this->getJson('/v1/not-a-route')->assertNotFound()->assertHeader('X-Request-ID');
        config(['famie.ip_restriction_enabled' => true, 'famie.allowed_ips' => []]);
        $this->getJson('/v1/status')->assertForbidden()->assertHeader('X-Request-ID');
        config(['famie.ip_restriction_enabled' => false]);
        $response = $this->withHeader('Origin', 'http://localhost:3000')->getJson('/v1/status')->assertOk();
        $this->assertStringContainsString('X-Request-ID', $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_login_failure_limit_is_logged_without_credentials_or_account_identifiers(): void
    {
        $stream = $this->captureLogs();
        config(['famie.login_max_attempts' => 1]);
        $input = ['login' => 'private-user@example.test', 'password' => 'private-password'];
        $this->postJson('/v1/auth/login', $input)->assertUnprocessable();
        $this->postJson('/v1/auth/login', $input)->assertTooManyRequests()->assertHeader('X-Request-ID');
        $records = $this->records($stream);
        $this->assertSame(['security.login_locked', 'auth.login_failed', 'security.rate_limited'], array_column($records, 'message'));
        $encoded = json_encode($records);
        $this->assertStringNotContainsString('private-', $encoded);
        $this->assertStringNotContainsString('127.0.0.1', $encoded);
        $this->assertArrayNotHasKey('actor_id', $records[1]['context']);
    }

    public function test_management_success_and_cross_family_denial_keep_authorization_and_redact_payload(): void
    {
        $stream = $this->captureLogs();
        $group = Group::query()->create(['name' => 'Private family', 'type' => 'family']);
        $other = Group::query()->create(['name' => 'Other family', 'type' => 'family']);
        $user = User::factory()->create();
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($user);
        $this->patchJson("/v1/groups/{$group->id}", ['name' => "Private updated\nname"])->assertOk();
        $this->patchJson("/v1/groups/{$other->id}", ['name' => 'Must not save'])->assertNotFound();
        $this->assertDatabaseHas('groups', ['id' => $other->id, 'name' => 'Other family']);
        $records = $this->records($stream);
        $this->assertSame(['admin.group_updated', 'security.resource_denied'], array_column($records, 'message'));
        $this->assertSame('success', $records[0]['context']['outcome']);
        $this->assertSame($group->id, $records[0]['context']['group_id']);
        $this->assertArrayNotHasKey('group_id', $records[1]['context']);
        $this->assertStringNotContainsString('Private', json_encode($records));
    }

    public function test_sql_exception_is_reported_once_without_bindings_trace_or_response_details(): void
    {
        $stream = $this->captureLogs();
        Route::get('/v1/test-sql-failure', function (): never {
            throw new QueryException('secret-connection', 'select secret-column where token = ?', ['private-token'], new \PDOException('private-password'));
        });
        $response = $this->getJson('/v1/test-sql-failure?token=private-query')->assertStatus(500)->assertHeader('X-Request-ID');
        $records = $this->records($stream);
        $this->assertCount(1, $records);
        $this->assertSame('application.exception', $records[0]['message']);
        $this->assertSame(QueryException::class, $records[0]['context']['exception_type']);
        $this->assertSame($response->headers->get('X-Request-ID'), $records[0]['context']['request_id']);
        $this->assertStringNotContainsString('private-', json_encode($records).$response->getContent());
        $this->assertStringNotContainsString('secret-', json_encode($records).$response->getContent());
    }

    public function test_unstructured_logs_and_unknown_context_are_sanitized(): void
    {
        $stream = $this->captureLogs('single');
        Log::channel('single')->error("private-token\nforged", [
            'exception' => new \RuntimeException('private-password'),
            'password' => 'private-password', 'headers' => ['Authorization' => 'private-token'],
            'actor_id' => 'private-user', 'exception_type' => "forged\nentry",
        ]);
        $records = $this->records($stream);
        $this->assertCount(1, $records);
        $this->assertSame('application.exception', $records[0]['message']);
        $this->assertSame(\RuntimeException::class, $records[0]['context']['exception_type']);
        $this->assertStringNotContainsString('private-', json_encode($records));
        $this->assertSame([], $records[0]['extra']);
    }

    public function test_logging_failure_does_not_undo_a_committed_update(): void
    {
        $group = Group::query()->create(['name' => 'Before', 'type' => 'family']);
        $user = User::factory()->create();
        GroupMember::query()->create(['group_id' => $group->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($user);
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->once()->andThrow(new \RuntimeException('private-path'));
        Log::shouldReceive('channel')->with('operations')->andReturn($logger);
        $this->patchJson("/v1/groups/{$group->id}", ['name' => 'After'])->assertOk();
        $this->assertDatabaseHas('groups', ['id' => $group->id, 'name' => 'After']);
    }

    public function test_failed_transaction_never_emits_a_success_event(): void
    {
        $stream = $this->captureLogs();
        Route::post('/v1/test-rollback', function (): never {
            DB::transaction(function (): void {
                Group::query()->create(['name' => 'Rolled back', 'type' => 'family']);
                throw new \RuntimeException('private-value');
            });
        });
        $this->postJson('/v1/test-rollback')->assertStatus(500);
        $this->assertDatabaseMissing('groups', ['name' => 'Rolled back']);
        $this->assertSame(['failure'], array_column(array_column($this->records($stream), 'context'), 'outcome'));
    }

    public function test_production_console_exception_does_not_reveal_secrets(): void
    {
        $this->app->instance('env', 'production');
        $output = new BufferedOutput;
        app(ExceptionHandler::class)->renderForConsole($output, new \RuntimeException('private-smtp-password'));
        $text = $output->fetch();
        $this->assertStringContainsString('Famie command failed.', $text);
        $this->assertStringNotContainsString('private-smtp-password', $text);
    }

    public function test_emergency_fallback_never_writes_raw_messages_or_exception_details(): void
    {
        $path = $this->isolateOperationsStorage();
        Log::channel('not-configured')->error('private-token', ['exception' => new \RuntimeException('private-password')]);
        $text = file_get_contents($path.'/logs/emergency-'.now()->utc()->format('Y-m-d').'.log');
        $this->assertStringContainsString('application.exception', $text);
        $this->assertStringNotContainsString('private-', $text);
    }

    public function test_management_transaction_rollback_is_not_logged_as_a_success(): void
    {
        $stream = $this->captureLogs();
        $group = Group::factory()->create();
        $member = GroupMember::factory()->create(['group_id' => $group->id, 'role' => 'admin']);
        Sanctum::actingAs($member->user);
        Group::updated(function (): never {
            throw new \RuntimeException('private-update');
        });
        $this->patchJson("/v1/groups/{$group->id}", ['name' => 'Rolled back'])->assertStatus(500);
        $this->assertDatabaseHas('groups', ['id' => $group->id, 'name' => $group->name]);
        $this->assertSame(['application.exception'], array_column($this->records($stream), 'message'));
    }

    public function test_arbitrary_operation_context_cannot_inject_log_fields(): void
    {
        $stream = $this->captureLogs();
        OperationLog::write("private-event\nforged", 'private-outcome', ['status' => "200\nforged", 'token' => 'private-token']);
        $records = $this->records($stream);
        $this->assertSame('application.message', $records[0]['message']);
        $this->assertArrayNotHasKey('outcome', $records[0]['context']);
        $this->assertStringNotContainsString('private-', json_encode($records));
    }
}
