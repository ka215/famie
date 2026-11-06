<?php

namespace Tests\Feature;

use App\Models\AccountLifecycleOperation;
use App\Models\ActivityImage;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\GroupImageUsage;
use App\Services\LifecycleHold;
use App\Services\LifecycleImageEvidence;
use App\Services\LifecycleOperatorFiles;
use App\Services\LifecycleOrphanImages;
use App\Services\LifecycleProcessContext;
use App\Services\LifecycleRestoreCheck;
use App\Services\LifecycleRestoreImages;
use App\Services\LifecycleRestoreReapply;
use App\Services\OperationState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;

class AccountLifecycleCleanupPostgresTest extends AccountLifecycleTest
{
    public function test_real_cleanup_children_share_only_the_explicit_schema_and_private_fixture(): void
    {
        [$root, $expired, $active, $schema] = $this->processFixture();
        $environment = app(LifecycleProcessContext::class)->environment();
        $this->assertSame($schema, $environment['DB_SCHEMA']);
        $this->assertSame('array', $environment['FAMIE_OPERATIONS_MAILER']);
        $this->assertSame('', $environment['MAIL_PASSWORD']);
        $probe = Process::path(base_path())->env($environment)->timeout(15)->run([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); try { $context = $app->make(App\\Services\\LifecycleProcessContext::class)->environment(); echo $context["DB_SCHEMA"]; } catch (Throwable $e) { echo str_starts_with($e->getMessage(), "Lifecycle child") ? $e->getMessage() : $e::class; }',
        ]);
        $this->assertSame($schema, trim($probe->output()));
        app(OperationState::class)->write('monitor', ['incidents' => ['lifecycle-cleanup' => ['open' => true, 'successes' => 1]]]);
        $publicBefore = DB::selectOne('select count(*) as total from public.account_lifecycle_operations')->total;
        $result = Process::path(base_path())->env($environment)->timeout(25)->run([
            PHP_BINARY, 'artisan', 'famie:run-lifecycle-cleanup', '--no-interaction', '--isolated-context',
        ]);
        // The worker succeeds; the deliberately non-SMTP notification phase fails safely.
        $this->assertFalse($result->successful());
        $this->assertSame('aborted', $expired->fresh()->state);
        $this->assertSame('prepared', $active->fresh()->state);
        $this->assertDatabaseMissing('account_lifecycle_credentials', ['operation_id' => $expired->id]);
        $this->assertDatabaseHas('account_lifecycle_credentials', ['operation_id' => $active->id]);
        $job = app(OperationState::class)->read('lifecycle-cleanup-job');
        $this->assertSame('success', $job['outcome']);
        $this->assertSame('failure', $job['notification_outcome']);
        $this->assertTrue(app(OperationState::class)->read('monitor')['incidents']['lifecycle-cleanup']['notification_failed']);
        $this->assertSame('success', app(OperationState::class)->read('lifecycle-cleanup')['outcome']);
        $this->assertFileExists($root.'/runtime/app/private/keep.txt');
        $this->assertSame('synthetic sentinel', file_get_contents($root.'/runtime/app/private/keep.txt'));
        $this->assertSame($publicBefore, DB::selectOne('select count(*) as total from public.account_lifecycle_operations')->total);
        $this->assertFileDoesNotExist($root.'/runtime/cache/config.php');
        $this->assertFileExists($root.'/runtime/cache/packages.php');
        $this->assertFileExists($root.'/runtime/cache/services.php');
    }

    #[TestWith(['schema'])]
    #[TestWith(['database'])]
    #[TestWith(['smtp'])]
    #[TestWith(['disabled'])]
    #[TestWith(['runtime'])]
    #[TestWith(['ledger'])]
    public function test_real_cleanup_child_rejects_an_unexpected_context_before_mutation(string $case): void
    {
        [$root, $expired] = $this->processFixture();
        $environment = app(LifecycleProcessContext::class)->environment();
        $override = match ($case) {
            'schema' => ['DB_SCHEMA' => 'public'],
            'database' => ['DB_DATABASE' => 'not_an_approved_database'],
            'smtp' => ['FAMIE_OPERATIONS_MAILER' => 'smtp'],
            'disabled' => ['FAMIE_LIFECYCLE_PROCESS_TEST' => 'false'],
            'runtime' => ['LARAVEL_STORAGE_PATH' => $root.'/runtime/control'],
            'ledger' => ['FAMIE_LIFECYCLE_LEDGER_ROOT' => $root.'/runtime/cache'],
        };
        $result = Process::path(base_path())->env(array_replace($environment, $override))->timeout(15)->run([
            PHP_BINARY, 'artisan', 'famie:cleanup-account-lifecycle', '--no-interaction', '--isolated-context',
        ]);
        $this->assertFalse($result->successful());
        $this->assertSame('prepared', $expired->fresh()->state);
        $this->assertDatabaseHas('account_lifecycle_credentials', ['operation_id' => $expired->id]);
        $this->assertFileDoesNotExist($root.'/runtime/app/operations/lifecycle-cleanup.json');
        $this->assertFileExists($root.'/runtime/app/private/keep.txt');
    }

    public function test_parent_refuses_an_uncommitted_fixture_without_launching_a_child(): void
    {
        [, $expired] = $this->processFixture();
        Process::preventStrayProcesses();
        Process::fake();
        DB::beginTransaction();
        try {
            $this->artisan('famie:run-lifecycle-cleanup')->assertFailed();
            $this->assertSame('prepared', $expired->fresh()->state);
            Process::assertNothingRan();
        } finally {
            DB::rollBack();
        }
    }

    public function test_parent_refuses_an_existing_child_config_cache_without_launching_a_child(): void
    {
        [$root, $expired] = $this->processFixture();
        Process::preventStrayProcesses();
        Process::fake();
        file_put_contents($root.'/runtime/cache/config.php', '<?php return [];');
        $this->artisan('famie:run-lifecycle-cleanup')->assertFailed();
        $this->assertSame('prepared', $expired->fresh()->state);
        $this->assertFileDoesNotExist($root.'/runtime/app/operations/lifecycle-cleanup-job.json');
        Process::assertNothingRan();
    }

    /** @return array{string,AccountLifecycleOperation,AccountLifecycleOperation,string} */
    private function processFixture(): array
    {
        $this->requireDedicatedRecoveryDatabase();
        $schema = DB::selectOne('select current_schema() as name')->name;
        config(['database.connections.pgsql.search_path' => $schema]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
        $root = sys_get_temp_dir().'/famie-f03-'.bin2hex(random_bytes(8));
        foreach (['vault', 'ledger', 'keys', 'audit', 'exports', 'runtime/app/private', 'runtime/cache', 'runtime/logs', 'runtime/framework/views'] as $directory) {
            File::makeDirectory($root.'/'.$directory, 0700, true);
        }
        $this->beforeApplicationDestroyed(function () use ($root): void {
            if (! is_link($root) && dirname(realpath($root)) === realpath(sys_get_temp_dir())
                && preg_match('/\Afamie-f03-[a-f0-9]{16}\z/', basename($root))) {
                File::deleteDirectory($root);
            }
        });
        $this->app->useStoragePath($root.'/runtime');
        config([
            'famie.lifecycle_process_test_enabled' => true, 'famie.lifecycle_monitor_enabled' => true,
            'famie.lifecycle_operator_enabled' => true, 'famie.lifecycle_vault_root' => $root.'/vault',
            'famie.lifecycle_ledger_root' => $root.'/ledger', 'famie.lifecycle_operator_root' => $root.'/keys',
            'famie.lifecycle_audit_root' => $root.'/audit', 'famie.lifecycle_export_root' => $root.'/exports',
            'operations.enabled' => true, 'operations.environment' => 'testing', 'operations.mailer' => 'array', 'mail.default' => 'array',
            'filesystems.disks.local.root' => $root.'/runtime/app/private', 'logging.channels.operations.path' => $root.'/runtime/logs/operations.log',
        ]);
        Storage::forgetDisk('local');
        file_put_contents($root.'/runtime/app/private/keep.txt', 'synthetic sentinel');
        $group = Group::factory()->create();
        $user = User::factory()->create();
        $expired = AccountLifecycleOperation::query()->create([
            'id' => (string) Str::uuid(), 'group_id' => $group->id, 'actor_user_id' => $user->id, 'kind' => 'group_withdraw',
            'state' => 'prepared', 'cleanup_state' => 'pending', 'prepared_expires_at' => now()->subMinute(),
        ]);
        $active = AccountLifecycleOperation::query()->create([
            'id' => (string) Str::uuid(), 'group_id' => $group->id, 'actor_user_id' => $user->id, 'kind' => 'group_withdraw',
            'state' => 'prepared', 'cleanup_state' => 'pending', 'prepared_expires_at' => now()->addHour(),
        ]);
        foreach ([$expired, $active] as $operation) {
            $operation->credential()->create(['code_hash' => hash('sha256', 'synthetic-code'), 'expires_at' => now()->addHour()]);
        }

        return [$root, $expired, $active, $schema];
    }

    public function test_paginated_source_evidence_locks_and_reads_all_images_in_the_isolated_postgresql_schema(): void
    {
        $root = sys_get_temp_dir().'/famie-restore-drill-'.bin2hex(random_bytes(8));
        foreach (['ledger', 'runtime/logs'] as $directory) {
            File::makeDirectory($root.'/'.$directory, 0700, true);
        }
        $this->beforeApplicationDestroyed(function () use ($root): void {
            if (! is_link($root) && dirname(realpath($root)) === realpath(sys_get_temp_dir())) {
                File::deleteDirectory($root);
            }
        });
        $this->app->useStoragePath($root.'/runtime');
        config(['famie.lifecycle_ledger_root' => $root.'/ledger', 'logging.channels.operations.path' => $root.'/runtime/logs/operations.log']);
        Storage::fake('local');
        $group = Group::factory()->create();
        $member = GroupMember::factory()->for($group)->for(User::factory())->create(['role' => 'admin']);
        $operation = AccountLifecycleOperation::query()->create(['id' => (string) Str::uuid(), 'group_id' => $group->id, 'actor_user_id' => $member->user_id,
            'kind' => 'group_withdraw', 'state' => 'prepared', 'cleanup_state' => 'pending', 'prepared_expires_at' => now()->addMinutes(15)]);
        $operation->subjects()->create(['user_id' => $member->user_id, 'membership_id' => $member->id]);
        $category = Category::query()->create(['group_id' => $group->id, 'name' => 'Synthetic page locks', 'color_code' => '#10B981', 'sort_order' => 1]);
        $logs = [];
        for ($index = 0; $index < 1001; $index++) {
            $logs[] = ['group_id' => $group->id, 'user_id' => $member->user_id, 'category_id' => $category->id,
                'activity_date' => now()->toDateString(), 'content' => 'Synthetic page content', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('activity_logs')->insert($logs);
        $images = [];
        foreach (DB::table('activity_logs')->orderBy('id')->pluck('id') as $index => $logId) {
            $path = 'activity-images/pg-page-'.$index.'.webp';
            Storage::disk('local')->put($path, 'test');
            $images[] = ['id' => ($index + 1) * 2, 'group_id' => $group->id, 'activity_log_id' => $logId, 'disk' => 'local', 'path' => $path,
                'bytes' => 4, 'width' => 1, 'height' => 1, 'mime' => 'image/webp', 'position' => 0, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('activity_images')->insert($images);
        $sha = DB::transaction(fn () => app(LifecycleImageEvidence::class)->capture($operation));
        $read = app(LifecycleImageEvidence::class)->read($operation->id, ['image_manifest_sha256' => $sha, 'group_id' => $group->id,
            'kind' => $operation->kind, 'subject_ids' => [$member->user_id]]);
        $this->assertCount(1001, $read);
        $this->assertSame(2, $read[0]['image_id']);
        $this->assertSame(2002, $read[1000]['image_id']);
        $this->assertSame(hash('sha256', 'test'), $read[1000]['sha256']);
        $this->assertSame('prepared', $operation->fresh()->state);
        $this->assertSame(1001, DB::table('activity_images')->whereNull('retired_at')->count());
        $this->assertSame($sha, app(LifecycleOperatorFiles::class)->readImageEvidence($operation->id)['sha256']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    #[TestWith([true, true])]
    #[TestWith([false, false, true])]
    #[TestWith([true, false, true])]
    public function test_synthetic_backup_restore_reapplies_erasure_without_reopening(bool $held, bool $higherSequence = false, bool $missingReceipt = false): void
    {
        $this->requireDedicatedRecoveryDatabase();
        $connection = DB::connection()->getConfig();
        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertSame('55432', (string) $connection['port']);
        $this->assertSame('famie_test', $connection['database']);
        $this->assertSame('famie_test_runner', $connection['username']);
        $identity = DB::selectOne('select current_database() as db, current_user as role, inet_server_port() as port, current_schema() as schema');
        $this->assertSame('famie_test', $identity->db);
        $this->assertSame('famie_test_runner', $identity->role);
        $this->assertSame(55432, $identity->port);
        $schema = $identity->schema;
        $this->assertMatchesRegularExpression('/\Afamie_lifecycle_regression_\d+_[a-f0-9]{8}_test\z/', $schema);
        $this->assertSame(0, DB::transactionLevel());
        $root = sys_get_temp_dir().'/famie-restore-drill-'.bin2hex(random_bytes(8));
        foreach (['vault', 'ledger', 'control', 'audit', 'runtime/logs'] as $directory) {
            File::makeDirectory($root.'/'.$directory, 0700, true);
        }
        $this->beforeApplicationDestroyed(function () use ($root): void {
            if (! is_link($root) && dirname(realpath($root)) === realpath(sys_get_temp_dir())) {
                File::deleteDirectory($root);
            }
        });
        $this->app->useStoragePath($root.'/runtime');
        $pair = sodium_crypto_box_keypair();
        config(['famie.ip_restriction_enabled' => false, 'famie.lifecycle_operator_enabled' => true, 'famie.lifecycle_restore_test_enabled' => true,
            'famie.lifecycle_operator_root' => $root.'/control', 'famie.lifecycle_audit_root' => $root.'/audit',
            'famie.lifecycle_vault_root' => $root.'/vault', 'famie.lifecycle_ledger_root' => $root.'/ledger',
            'famie.lifecycle_public_key' => base64_encode(sodium_crypto_box_publickey($pair)), 'famie.lifecycle_key_id' => 'synthetic-drill',
            'logging.channels.operations.path' => $root.'/runtime/logs/operations.log']);
        Storage::fake('local');
        $group = Group::factory()->create();
        $representative = GroupMember::factory()->for($group)->for(User::factory())->create(['role' => 'admin']);
        $member = GroupMember::factory()->for($group)->for(User::factory())->create(['status' => 'inactive']);
        $group->forceFill(['representative_user_id' => $representative->user_id])->save();
        $category = Category::query()->create(['group_id' => $group->id, 'name' => 'Synthetic category', 'color_code' => '#10B981', 'sort_order' => 1]);
        for ($index = 0; $index < 100; $index++) {
            $log = ActivityLog::query()->create(['group_id' => $group->id, 'user_id' => $member->user_id, 'category_id' => $category->id,
                'activity_date' => now()->toDateString(), 'content' => 'Synthetic restored content', 'note' => 'Synthetic note']);
        }
        $image = ActivityImage::query()->create(['group_id' => $group->id, 'activity_log_id' => $log->id, 'disk' => 'local',
            'path' => 'activity-images/synthetic-drill.webp', 'bytes' => 4, 'width' => 1, 'height' => 1, 'mime' => 'image/webp', 'position' => 0]);
        Storage::disk('local')->put($image->path, 'test');
        $member->user->createToken('synthetic-old-token');
        $originalUsername = $member->user->username;
        Sanctum::actingAs($representative->user);
        $arguments = ['--host=127.0.0.1', '--port=55432', '--username=famie_test_runner', '--dbname=famie_test'];
        $environment = ['PGPASSWORD' => $connection['password'], 'PGCLIENTENCODING' => 'UTF8'];
        $dump = $root.'/synthetic.dump';
        $started = hrtime(true);
        $createBackup = function () use ($environment, $arguments, $schema, $dump): void {
            $backup = Process::env($environment)->timeout(30)->run(['pg_dump', ...$arguments, '--schema='.$schema, '--no-owner', '--no-privileges', '--format=custom', '--file='.$dump]);
            $this->assertTrue($backup->successful(), 'Synthetic schema backup failed.');
        };
        if ($missingReceipt) {
            $createBackup();
        }
        $prepared = $this->postJson("/v1/groups/{$group->id}/lifecycle-operations", ['kind' => 'member_delete', 'membership_id' => $member->id])->assertCreated();
        $receipt = $prepared->json('data.receipt_id');
        if (! $missingReceipt) {
            $createBackup();
        }
        $hash = hash_file('sha256', $dump);
        $commitStarted = hrtime(true);
        $this->postJson("/v1/groups/{$group->id}/lifecycle-operations/{$receipt}/commit", ['current_password' => 'password',
            'confirmation_code' => $prepared->json('data.confirmation_code'), 'confirmed' => true])->assertStatus(202);
        $originalRetention = AccountLifecycleOperation::query()->findOrFail($receipt)->retention_until->toIso8601String();
        $this->assertLessThan(10000, (hrtime(true) - $commitStarted) / 1000000, 'Synthetic 100-row commit exceeded the initial 10-second budget.');
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => '', 'note' => null]);
        Storage::disk('local')->assertMissing($image->path);
        $ledgerSha = hash_file('sha256', $root.'/ledger/account-lifecycle.jsonl');
        if ($held) {
            DB::select("select setval(pg_get_serial_sequence('account_lifecycle_holds', 'id')::regclass, 99, true)");
            $hold = app(LifecycleHold::class)->change($receipt, 'synthetic-held', now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z'), 'drill-operator', $ledgerSha, apply: true);
            $this->assertSame(100, $hold['hold_id']);
            $ledgerSha = hash_file('sha256', $root.'/ledger/account-lifecycle.jsonl');
            $this->travel(31)->days();
        }
        $this->assertSame($hash, hash_file('sha256', $dump));
        $restore = Process::env($environment)->timeout(30)->run(['pg_restore', ...$arguments, '--schema='.$schema, '--no-owner', '--no-privileges', '--clean', '--if-exists', '--single-transaction', $dump]);
        $this->assertTrue($restore->successful(), 'Synthetic schema restore failed.');
        DB::select("select set_config('search_path', ?, false)", [$schema]);
        Storage::disk('local')->put($image->path, 'test');
        $this->assertDatabaseHas('users', ['id' => $member->user_id, 'username' => $originalUsername, 'deleted_at' => null]);
        if ($missingReceipt) {
            DB::table('activity_images')->where('id', $image->id)->delete();
        }
        Storage::disk('local')->put('activity-images/orphaned-drill.webp', 'orphan');
        $imageAssessment = app(LifecycleRestoreImages::class)->check($ledgerSha);
        $this->assertSame(['orphaned_image_file' => $missingReceipt ? 2 : 1], $imageAssessment['findings']);
        $this->assertSame($missingReceipt ? 0 : 4, $imageAssessment['recorded_bytes']);
        $this->assertSame(10, $imageAssessment['physical_bytes']);
        $this->assertSame($missingReceipt ? 10 : 6, $imageAssessment['unattributed_bytes']);
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => 'Synthetic restored content']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        Storage::disk('local')->assertExists($image->path);
        $this->assertSame($ledgerSha, hash_file('sha256', $root.'/ledger/account-lifecycle.jsonl'));
        $assessment = app(LifecycleRestoreCheck::class)->check($ledgerSha);
        $this->assertSame(1, $assessment['findings'][$missingReceipt ? 'missing_committed_operation' : 'operation_identity_or_deadline_mismatch']);
        $this->assertFalse($assessment['reopen_allowed']);
        $this->assertFalse($assessment['database_changed']);
        $restoreId = (string) Str::uuid();
        $this->assertTrue(File::copy($dump, $root.'/control/'.$restoreId.'.restore.dump'));
        $record = ['format_version' => 1, 'restore_id' => $restoreId, 'receipt_id' => $receipt, 'source_url' => config('app.url'),
            'environment' => 'testing', 'ledger_sha256' => $ledgerSha, 'backup_sha256' => $hash, 'restored_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'target' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55432, 'database' => 'famie_test', 'schema' => $schema]];
        $recordPath = $root.'/control/'.$restoreId.'.restore-receipt.json';
        File::put($recordPath, json_encode($record, JSON_THROW_ON_ERROR));
        $recordSha = hash_file('sha256', $recordPath);
        $service = app(LifecycleRestoreReapply::class);
        if ($held) {
            if ($higherSequence) {
                DB::select("select setval(pg_get_serial_sequence('account_lifecycle_holds', 'id')::regclass, 200, true)");
            }
            $holdResult = $service->reapply($restoreId, $receipt, $recordSha, $ledgerSha, config('app.url'), 'testing', 'drill-operator', true, true);
            $this->assertSame(1, $holdResult['missing_holds']);
            $this->assertTrue($holdResult['held']);
            $this->assertTrue(AccountLifecycleOperation::query()->findOrFail($receipt)->hasActiveHold());
        }
        $plan = $service->reapply($restoreId, $receipt, $recordSha, $ledgerSha, config('app.url'), 'testing', 'drill-operator');
        $this->assertFalse($plan['applied']);
        if ($missingReceipt && ! $held) {
            $this->assertDatabaseCount('account_lifecycle_operations', 0);
        }
        $this->assertDatabaseHas('users', ['id' => $member->user_id, 'username' => $originalUsername, 'deleted_at' => null]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = $service->reapply($restoreId, $receipt, $recordSha, $ledgerSha, config('app.url'), 'testing', 'drill-operator', true);
            if ($held || $result['cleanup_state'] === 'complete') {
                break;
            }
        }
        $orphanResult = app(LifecycleOrphanImages::class)->erase($restoreId, $receipt, $recordSha, $ledgerSha, config('app.url'), 'testing', 'drill-operator', true);
        $this->assertSame($missingReceipt && ! $held ? 1 : 0, $orphanResult['deleted']);
        $this->assertSame($held, $orphanResult['held']);
        $this->assertFalse($orphanResult['reopen_allowed']);
        $this->assertSame($held ? 'processing' : 'complete', $result['cleanup_state']);
        $this->assertSame($held, $result['held']);
        $this->assertSame($originalRetention, $result['retention_until']);
        $this->assertSoftDeleted('users', ['id' => $member->user_id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => $held ? 'Synthetic restored content' : '', 'note' => $held ? 'Synthetic note' : null]);
        if ($held) {
            Storage::disk('local')->assertExists($image->path);
            $this->assertFileExists($root.'/vault/'.$receipt.'.json');
            $this->assertDatabaseCount('account_lifecycle_credentials', $missingReceipt ? 0 : 1);
            $this->assertTrue(AccountLifecycleOperation::query()->findOrFail($receipt)->retention_until->isPast());
        } else {
            Storage::disk('local')->assertMissing($image->path);
        }
        $this->assertSame($ledgerSha, hash_file('sha256', $root.'/ledger/account-lifecycle.jsonl'));
        $after = app(LifecycleRestoreCheck::class)->check($ledgerSha);
        $this->assertSame([], $after['findings']);
        $this->assertFalse($after['reopen_allowed']);
        $imagesAfter = app(LifecycleRestoreImages::class)->check($ledgerSha);
        $this->assertSame(['orphaned_image_file' => $held && $missingReceipt ? 2 : 1], $imagesAfter['findings']);
        $this->assertSame($held && ! $missingReceipt ? 4 : 0, $imagesAfter['recorded_bytes']);
        $this->assertSame($held ? 10 : 6, $imagesAfter['physical_bytes']);
        $this->assertSame($held && ! $missingReceipt ? 4 : 0, app(GroupImageUsage::class)->bytes($group));
        $this->assertSame('orphan', Storage::disk('local')->get('activity-images/orphaned-drill.webp'));
        if ($held) {
            DB::beginTransaction();
            $nextId = DB::table('account_lifecycle_holds')->insertGetId(['operation_id' => $receipt, 'scope' => 'all', 'reference' => 'sequence-probe',
                'started_at' => now(), 'review_at' => now()->addDay()]);
            $this->assertGreaterThan($higherSequence ? 200 : 100, $nextId);
            DB::rollBack();
        }
        DB::select("select set_config('search_path', 'public', false)");
        try {
            app(LifecycleRestoreImages::class)->check($ledgerSha);
            $this->fail('Public schema accepted for image assessment.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('disposable PostgreSQL schema', $exception->getMessage());
        }
        try {
            $service->reapply($restoreId, $receipt, $recordSha, $ledgerSha, config('app.url'), 'testing', 'drill-operator', true);
            $this->fail('Public schema accepted for restore reapplication.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('disposable PostgreSQL schema', $exception->getMessage());
        } finally {
            DB::select("select set_config('search_path', ?, false)", [$schema]);
        }
        $this->assertLessThan(150, (hrtime(true) - $started) / 1000000000, 'Synthetic drill exceeded 150 seconds.');
    }

    private function requireDedicatedRecoveryDatabase(): void
    {
        if (config('database-safety.profile') === 'postgres-ci') {
            $this->markTestSkipped('Real recovery and child-process drills require the approved local PostgreSQL target on port 55432.');
        }
    }

    /** Replace RefreshDatabase's reset with a new schema on the isolated test instance. */
    public function refreshDatabase(): void
    {
        if (getenv('FAMIE_LIFECYCLE_PG_TEST') !== '1') {
            $this->markTestSkipped('Requires an explicitly enabled isolated PostgreSQL lifecycle schema.');
        }
        $this->assertSame('pgsql', DB::getDriverName());
        $schema = 'famie_lifecycle_regression_'.getmypid().'_'.bin2hex(random_bytes(4)).'_test';
        DB::statement('CREATE SCHEMA '.$schema);
        $this->beforeApplicationDestroyed(function () use ($schema): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::select("select set_config('search_path', 'public', false)");
            DB::statement('DROP SCHEMA '.$schema.' CASCADE');
        });
        DB::select("select set_config('search_path', ?, false)", [$schema]);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    }
}
