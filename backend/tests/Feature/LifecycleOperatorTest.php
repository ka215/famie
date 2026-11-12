<?php

namespace Tests\Feature;

use App\Models\AccountLifecycleCredential;
use App\Models\AccountLifecycleOperation;
use App\Models\ActivityImage;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Services\AccountLifecycleCleanup;
use App\Services\ActivityImageDeletionService;
use App\Services\GroupImageUsage;
use App\Services\InternalMonitor;
use App\Services\LifecycleAudit;
use App\Services\LifecycleBackupAssessment;
use App\Services\LifecycleBackupCoverage;
use App\Services\LifecycleCleanupBudget;
use App\Services\LifecycleCopyExpiry;
use App\Services\LifecycleHold;
use App\Services\LifecycleIdentityRecorder;
use App\Services\LifecycleImageEvidence;
use App\Services\LifecycleInquiry;
use App\Services\LifecycleInquiryDecrypt;
use App\Services\LifecycleInquiryExport;
use App\Services\LifecycleLedger;
use App\Services\LifecycleLedgerInspector;
use App\Services\LifecycleOperatorFiles;
use App\Services\LifecycleOperatorKey;
use App\Services\LifecycleOrphanImages;
use App\Services\LifecycleRestoreCheck;
use App\Services\LifecycleRestoreImages;
use App\Services\LifecycleRestoreReapply;
use App\Services\LifecycleVault;
use App\Services\MonitorNotifier;
use App\Services\OperationState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class LifecycleOperatorTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $passphrase = 'test-only operator passphrase';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famie-f03-'.bin2hex(random_bytes(8));
        foreach (['keys', 'audit', 'ledger', 'vault', 'exports'] as $directory) {
            File::makeDirectory($this->root.'/'.$directory, 0700, true);
        }
        File::makeDirectory($this->root.'/runtime/logs', 0700, true);
        $this->app->useStoragePath($this->root.'/runtime');
        config(['logging.channels.operations.path' => $this->root.'/runtime/logs/operations.log']);
        config(['famie.lifecycle_operator_enabled' => true, 'famie.lifecycle_operator_root' => $this->root.'/keys',
            'famie.lifecycle_audit_root' => $this->root.'/audit', 'famie.lifecycle_ledger_root' => $this->root.'/ledger',
            'famie.lifecycle_vault_root' => $this->root.'/vault', 'famie.lifecycle_export_root' => $this->root.'/exports']);
        $this->beforeApplicationDestroyed(function (): void {
            if (! is_link($this->root) && dirname(realpath($this->root)) === realpath(sys_get_temp_dir())) {
                File::deleteDirectory($this->root);
            }
        });
    }

    public function test_operator_tools_are_disabled_by_default_and_key_creation_is_a_dry_run(): void
    {
        config(['famie.lifecycle_operator_enabled' => false]);
        $this->artisan('famie:lifecycle-operator key-create --operator=ops-01 --key-id=test-key')->assertFailed();
        config(['famie.lifecycle_operator_enabled' => true]);
        $this->artisan('famie:lifecycle-operator key-create --operator=ops-01 --key-id=test-key')
            ->expectsOutputToContain('No files changed')->assertSuccessful();
        $this->assertSame([], File::files($this->root.'/keys'));
        $this->assertSame([], File::files($this->root.'/audit'));
    }

    public function test_encrypted_key_creation_and_verification_use_hidden_prompts_and_public_only_export(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->artisan('famie:lifecycle-operator key-create --operator=ops-01 --key-id=test-key --apply')
            ->expectsQuestion('Key passphrase', $this->passphrase)
            ->expectsQuestion('Confirm key passphrase', $this->passphrase)->assertSuccessful();
        $this->artisan('famie:lifecycle-operator key-verify --operator=ops-01 --key-id=test-key')
            ->expectsQuestion('Key passphrase', $this->passphrase)->assertSuccessful();
        $envelope = app(LifecycleOperatorFiles::class)->read('test-key');
        $public = json_decode(File::get($this->root.'/keys/test-key.public.json'), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame(['key_id' => 'test-key', 'public_key' => $envelope['public_key']], $public);
        $this->assertSame(32, strlen(base64_decode($public['public_key'], true)));
        $this->assertStringNotContainsString($this->passphrase, File::get($this->root.'/keys/test-key.key.json'));
        $audit = File::get($this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl');
        $this->assertStringContainsString('key.create', $audit);
        $this->assertStringContainsString('key.verify', $audit);
        $this->assertStringNotContainsString($this->passphrase, $audit);
        $this->assertSame([], $queries);
    }

    public function test_wrong_passphrase_and_modified_key_identity_are_rejected(): void
    {
        $keys = app(LifecycleOperatorKey::class);
        $envelope = $keys->create('test-key', $this->passphrase);
        foreach (['different passphrase for tests', $this->passphrase] as $index => $passphrase) {
            try {
                $keys->verify($envelope, $index === 1 ? 'another-key' : 'test-key', $passphrase);
                $this->fail('Invalid key credentials accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString($passphrase, $exception->getMessage());
            }
        }
    }

    public function test_untrusted_key_parameters_cannot_request_unbounded_kdf_resources(): void
    {
        $envelope = app(LifecycleOperatorKey::class)->create('test-key', $this->passphrase);
        $envelope['kdf']['memlimit'] = PHP_INT_MAX;
        $this->expectException(RuntimeException::class);
        app(LifecycleOperatorKey::class)->verify($envelope, 'test-key', $this->passphrase);
    }

    public function test_key_files_are_never_overwritten(): void
    {
        $files = app(LifecycleOperatorFiles::class);
        $files->create('test-key', ['preserve' => 'existing encrypted evidence']);
        try {
            $files->create('test-key', ['replacement' => true]);
            $this->fail('Existing key overwritten.');
        } catch (RuntimeException) {
            $this->assertSame(['preserve' => 'existing encrypted evidence'], $files->read('test-key'));
        }
    }

    public function test_hard_linked_keys_and_public_application_roots_are_refused(): void
    {
        $files = app(LifecycleOperatorFiles::class);
        $files->create('test-key', ['format_version' => 1]);
        $this->assertTrue(link($files->path('test-key'), $this->root.'/linked-key'));
        try {
            $files->read('test-key');
            $this->fail('Hard-linked key accepted.');
        } catch (RuntimeException) {
            $this->assertFileExists($this->root.'/linked-key');
        }
        config(['famie.lifecycle_operator_root' => public_path()]);
        $this->expectException(RuntimeException::class);
        $files->path('test-key');
    }

    public function test_key_operations_cannot_run_in_a_server_environment(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('famie:lifecycle-operator key-create --operator=ops-01 --key-id=test-key --apply')->assertFailed();
        $this->assertSame([], File::files($this->root.'/keys'));
    }

    public function test_key_identifiers_cannot_escape_the_root_or_address_windows_devices(): void
    {
        foreach (['../outside', 'NUL', 'con.extra', 'COM1'] as $identifier) {
            try {
                app(LifecycleOperatorFiles::class)->path($identifier);
                $this->fail('Unsafe key filename accepted.');
            } catch (RuntimeException) {
                $this->assertSame([], File::files($this->root.'/keys'));
            }
        }
    }

    public function test_audit_retention_uses_event_times_and_dry_run_preserves_boundary_records(): void
    {
        $this->freezeTime();
        $now = now()->utc()->startOfDay()->addHours(12)->toImmutable();
        $cutoff = $now->subDays(90);
        $audit = app(LifecycleAudit::class);
        $this->travelTo($cutoff->subMinute());
        $audit->record('inquiry', 'failure', 'expired-op');
        $this->travelTo($cutoff->addMinute());
        $audit->record('inquiry', 'success', 'retained-op');
        $this->travelTo($now);
        $path = $this->root.'/audit/audit-'.$cutoff->utc()->format('Y-m-d').'.jsonl';
        $before = File::get($path);
        $this->assertSame(['expired' => 1, 'files' => 1], $audit->prune());
        $this->assertSame($before, File::get($path));
        $this->assertSame(['expired' => 1, 'files' => 1], $audit->prune(true));
        $this->assertStringNotContainsString('expired-op', File::get($path));
        $this->assertStringContainsString('retained-op', File::get($path));
    }

    public function test_fully_expired_audit_files_are_removed_without_removing_current_records(): void
    {
        $this->freezeTime();
        $now = now()->toImmutable();
        $this->travelTo($now->subDays(91));
        $audit = app(LifecycleAudit::class);
        $audit->record('inquiry', 'failure', 'ops-01');
        $oldPath = $this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl';
        $this->travelTo($now);
        $audit->record('inquiry', 'success', 'ops-01');
        $this->assertSame(['expired' => 1, 'files' => 1], $audit->prune(true));
        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileExists($this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl');
    }

    public function test_incomplete_audit_is_preserved_and_blocks_further_operations(): void
    {
        $audit = app(LifecycleAudit::class);
        $audit->record('inquiry', 'started', 'ops-01');
        $path = $this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl';
        File::append($path, 'partial');
        $before = File::get($path);
        try {
            $audit->prune(true);
            $this->fail('Truncated audit accepted.');
        } catch (RuntimeException) {
            $this->assertSame($before, File::get($path));
        }
    }

    public function test_inquiry_returns_only_metadata_without_changing_application_rows_or_logging_the_code(): void
    {
        [$operation, $code] = $this->operation();
        $before = DB::table('account_lifecycle_operations')->get()->toJson();
        $credential = DB::table('account_lifecycle_credentials')->get()->toJson();
        $metadata = app(LifecycleInquiry::class)->lookup($operation->id, $code, 'ops-01');
        $this->assertSame($operation->id, $metadata['receipt_id']);
        $this->assertArrayNotHasKey('user_id', $metadata);
        $this->assertArrayNotHasKey('email', $metadata);
        $this->assertSame($before, DB::table('account_lifecycle_operations')->get()->toJson());
        $this->assertSame($credential, DB::table('account_lifecycle_credentials')->get()->toJson());
        $audit = File::get($this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl');
        $this->assertStringNotContainsString($code, $audit);
        $this->assertStringNotContainsString('private-person@example.test', $audit);
        $this->assertStringContainsString($operation->id, $audit);
    }

    public function test_inquiry_rejects_expired_receipts_and_limits_code_attempts(): void
    {
        $this->freezeTime();
        [$operation, $code] = $this->operation();
        $inquiry = app(LifecycleInquiry::class);
        for ($attempt = 0; $attempt < 6; $attempt++) {
            try {
                $inquiry->lookup($operation->id, $attempt === 5 ? $code : str_repeat('B', 43), 'ops-01');
                $this->fail('Invalid or limited inquiry accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Inquiry unavailable.', $exception->getMessage());
            }
        }
        $this->travel(901)->seconds();
        $this->assertSame($operation->id, $inquiry->lookup($operation->id, $code, 'ops-01')['receipt_id']);
        $operation->forceFill(['retention_until' => now()])->save();
        $this->expectException(RuntimeException::class);
        $inquiry->lookup($operation->id, $code, 'ops-01');
    }

    public function test_inquiry_command_accepts_the_code_only_through_its_hidden_prompt(): void
    {
        [$operation, $code] = $this->operation();
        $this->artisan('famie:lifecycle-operator', ['action' => 'inquiry', '--operator' => 'ops-01', '--receipt' => $operation->id])
            ->expectsQuestion('Inquiry confirmation code', $code)
            ->expectsOutputToContain($operation->id)->assertSuccessful();
    }

    public function test_audit_unavailability_prevents_inquiry_and_key_creation(): void
    {
        [$operation, $code] = $this->operation();
        config(['famie.lifecycle_audit_root' => null]);
        $this->artisan('famie:lifecycle-operator key-create --operator=ops-01 --key-id=test-key --apply')->assertFailed();
        $this->assertSame([], File::files($this->root.'/keys'));
        $this->expectException(RuntimeException::class);
        app(LifecycleInquiry::class)->lookup($operation->id, $code, 'ops-01');
    }

    public function test_ledger_verification_checks_a_trusted_hash_and_reports_unresolved_intents(): void
    {
        [$operation] = $this->operation();
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => str_repeat('a', 64), 'subject_ids' => [1]]);
        $path = $this->root.'/ledger/account-lifecycle.jsonl';
        $inspector = app(LifecycleLedgerInspector::class);
        $this->assertSame(1, $inspector->inspect(hash_file('sha256', $path))['unresolved']);
        $this->artisan('famie:lifecycle-operator', ['action' => 'ledger-verify', '--operator' => 'ops-01', '--ledger-sha256' => hash_file('sha256', $path)])->assertFailed();
        $ledger->append($operation, 'committed');
        $result = $inspector->inspect(hash_file('sha256', $path));
        $this->assertSame(1, $result['committed']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(2, $result['records']);
        $this->expectException(RuntimeException::class);
        $inspector->inspect(str_repeat('0', 64));
    }

    public function test_truncated_ledgers_are_not_treated_as_verified(): void
    {
        [$operation] = $this->operation();
        app(LifecycleLedger::class)->append($operation, 'intent', ['vault_sha256' => str_repeat('a', 64), 'subject_ids' => [1]]);
        $path = $this->root.'/ledger/account-lifecycle.jsonl';
        File::append($path, 'partial');
        $this->expectException(RuntimeException::class);
        app(LifecycleLedgerInspector::class)->inspect(hash_file('sha256', $path));
    }

    public function test_a_ledger_cannot_change_the_family_attached_to_a_receipt(): void
    {
        [$operation] = $this->operation();
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => str_repeat('a', 64), 'subject_ids' => [1]]);
        $operation->group_id++;
        $ledger->append($operation, 'committed');
        $path = $this->root.'/ledger/account-lifecycle.jsonl';
        $this->expectException(RuntimeException::class);
        app(LifecycleLedgerInspector::class)->inspect(hash_file('sha256', $path));
    }

    public function test_inquiry_export_is_encrypted_and_local_decryption_never_queries_the_database(): void
    {
        [$context, $code, $payload] = $this->decryptionFixture();
        $files = app(LifecycleOperatorFiles::class);
        $bytes = File::get($files->packagePath($context['package_id']));
        $this->assertStringNotContainsString($code, $bytes);
        $this->assertStringNotContainsString('private-person@example.test', $bytes);
        $this->assertStringNotContainsString('Private family name', $bytes);
        $before = DB::table('account_lifecycle_operations')->get()->toJson();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $decoded = null;
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', function ($value) use (&$decoded): void {
            $decoded = $value;
        });
        $this->assertSame([], $queries);
        $this->assertSame($payload, $decoded);
        $this->assertSame($before, DB::table('account_lifecycle_operations')->get()->toJson());
        $audit = File::get($this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl');
        $this->assertStringNotContainsString($code, $audit);
        $this->assertStringNotContainsString($this->passphrase, $audit);
        $this->assertStringNotContainsString('private-person@example.test', $audit);
        $this->assertStringContainsString('decrypt', $audit);
    }

    public function test_decryption_cli_checks_secrets_without_displaying_personal_data_by_default(): void
    {
        [$context, $code] = $this->decryptionFixture();
        $this->artisan('famie:lifecycle-operator', ['action' => 'decrypt', '--operator' => 'ops-01', '--receipt' => $context['receipt_id'],
            '--package' => $context['package_id'], '--package-sha256' => $context['package_sha256'], '--key-id' => 'test-key',
            '--source-url' => $context['source_url'], '--source-environment' => $context['environment'], '--ledger-sha256' => $context['ledger_sha256']])
            ->expectsQuestion('Inquiry confirmation code', $code)->expectsQuestion('Key passphrase', $this->passphrase)
            ->expectsOutputToContain('No personal data displayed')->doesntExpectOutputToContain('private-person@example.test')
            ->doesntExpectOutputToContain($code)->doesntExpectOutputToContain($this->passphrase)->assertSuccessful();
    }

    public function test_member_deletion_decryption_is_limited_to_one_subject_without_a_family_name(): void
    {
        [$context, $code] = $this->decryptionFixture([], true, 'member_delete');
        $received = null;
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', function ($payload) use (&$received): void {
            $received = $payload;
        });
        $this->assertNull($received['group_name']);
        $this->assertCount(1, $received['subjects']);
    }

    public function test_member_deletion_cannot_release_an_unrelated_family_name(): void
    {
        [$context, $code] = $this->decryptionFixture(['group_name' => 'Unrelated private family'], true, 'member_delete');
        $this->expectException(RuntimeException::class);
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', static function (): void {});
    }

    public function test_a_production_environment_package_can_only_be_opened_on_the_local_workstation(): void
    {
        $this->app['env'] = 'production';
        [$context, $code] = $this->decryptionFixture();
        $this->app['env'] = 'testing';
        $this->assertSame('production', $context['environment']);
        $called = false;
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', function () use (&$called): void {
            $called = true;
        });
        $this->assertTrue($called);
    }

    public function test_export_requires_explicit_apply_and_never_overwrites_an_existing_package(): void
    {
        $this->artisan('famie:lifecycle-operator inquiry-export --operator=ops-01')
            ->expectsOutputToContain('No export created')->assertSuccessful();
        $this->assertSame([], File::files($this->root.'/exports'));
        [$context] = $this->decryptionFixture();
        $files = app(LifecycleOperatorFiles::class);
        $before = File::get($files->packagePath($context['package_id']));
        try {
            $files->createPackage($context['package_id'], ['replacement' => true]);
            $this->fail('Existing inquiry package overwritten.');
        } catch (RuntimeException) {
            $this->assertSame($before, File::get($files->packagePath($context['package_id'])));
        }
    }

    public function test_export_cli_prompts_for_the_code_and_outputs_only_non_plaintext_transfer_metadata(): void
    {
        [$context, $code] = $this->decryptionFixture();
        $this->artisan('famie:lifecycle-operator', ['action' => 'inquiry-export', '--operator' => 'ops-01', '--receipt' => $context['receipt_id'],
            '--ledger-sha256' => $context['ledger_sha256'], '--apply' => true])
            ->expectsQuestion('Inquiry confirmation code', $code)->expectsOutputToContain('package_id')
            ->doesntExpectOutputToContain($code)->doesntExpectOutputToContain('private-person@example.test')->assertSuccessful();
        $this->assertCount(2, File::files($this->root.'/exports'));
    }

    public function test_private_display_is_refused_without_an_interactive_terminal(): void
    {
        if (stream_isatty(STDOUT)) {
            $this->markTestSkipped('This boundary test requires redirected stdout.');
        }
        $this->artisan('famie:lifecycle-operator', ['action' => 'decrypt', '--operator' => 'ops-01', '--reveal' => true])
            ->doesntExpectOutputToContain('private-person@example.test')->assertFailed();
        $this->assertSame([], File::files($this->root.'/audit'));
    }

    #[TestWith(['receipt_id'])]
    #[TestWith(['package_id'])]
    #[TestWith(['key_id'])]
    #[TestWith(['source_url'])]
    #[TestWith(['environment'])]
    #[TestWith(['package_sha256'])]
    #[TestWith(['ledger_sha256'])]
    public function test_decryption_rejects_mismatched_identity_and_trusted_hash_references(string $field): void
    {
        [$context, $code] = $this->decryptionFixture();
        $context[$field] = match ($field) {
            'receipt_id', 'package_id' => (string) Str::uuid(),
            'key_id' => 'wrong-key',
            'source_url' => 'https://other.example.test',
            'environment' => 'production',
            default => str_repeat('0', 64),
        };
        $called = false;
        try {
            app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', function () use (&$called): void {
                $called = true;
            });
            $this->fail('Mismatched inquiry accepted.');
        } catch (RuntimeException) {
            $this->assertFalse($called);
        }
    }

    #[TestWith(['code'])]
    #[TestWith(['passphrase'])]
    public function test_wrong_inquiry_code_or_key_passphrase_never_releases_plaintext(string $secret): void
    {
        [$context, $code] = $this->decryptionFixture();
        $called = false;
        try {
            app(LifecycleInquiryDecrypt::class)->decrypt($context, $secret === 'code' ? str_repeat('B', 43) : $code,
                $secret === 'passphrase' ? 'different test passphrase' : $this->passphrase, 'ops-01', function () use (&$called): void {
                    $called = true;
                });
            $this->fail('Incorrect secret accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Inquiry decryption unavailable.', $exception->getMessage());
            $this->assertFalse($called);
        }
    }

    public function test_different_key_material_with_the_same_identifier_cannot_open_the_vault(): void
    {
        [$context, $code] = $this->decryptionFixture();
        $replacement = app(LifecycleOperatorKey::class)->create('test-key', $this->passphrase);
        File::put(app(LifecycleOperatorFiles::class)->path('test-key'), json_encode($replacement, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', static function (): void {});
    }

    public function test_expiry_is_measured_from_commit_and_cannot_be_extended_by_exporting_or_copying(): void
    {
        [$context, $code] = $this->decryptionFixture();
        $this->travel(30)->days();
        $called = false;
        try {
            app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', function () use (&$called): void {
                $called = true;
            });
            $this->fail('Expired inquiry accepted.');
        } catch (RuntimeException) {
            $this->assertFalse($called);
        }
        $before = count(File::files($this->root.'/exports'));
        try {
            app(LifecycleInquiryExport::class)->create($context['receipt_id'], $code, 'ops-01', $context['ledger_sha256']);
            $this->fail('Expired inquiry exported.');
        } catch (RuntimeException) {
            $this->assertSame($before, count(File::files($this->root.'/exports')));
        }
    }

    public function test_package_byte_tampering_is_rejected_before_key_use(): void
    {
        [$context, $code] = $this->decryptionFixture();
        File::append(app(LifecycleOperatorFiles::class)->packagePath($context['package_id'], true), ' ');
        $this->expectException(RuntimeException::class);
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', static function (): void {});
    }

    public function test_aead_rejects_ciphertext_tampering_even_if_all_public_hashes_are_recomputed(): void
    {
        [$context, $code] = $this->decryptionFixture();
        $files = app(LifecycleOperatorFiles::class);
        $package = $files->readPackage($context['package_id'])['data'];
        $vault = json_decode(base64_decode($package['vault'], true), true, 16, JSON_THROW_ON_ERROR);
        $ciphertext = base64_decode($vault['ciphertext'], true);
        $ciphertext[0] = chr(ord($ciphertext[0]) ^ 1);
        $vault['ciphertext'] = base64_encode($ciphertext);
        $bytes = json_encode($vault, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $package['vault'] = base64_encode($bytes);
        $package['vault_sha256'] = hash('sha256', $bytes);
        $ledgerPath = $this->root.'/ledger/account-lifecycle.jsonl';
        $lines = file($ledgerPath);
        $intent = json_decode($lines[0], true, 16, JSON_THROW_ON_ERROR);
        $intent['vault_sha256'] = $package['vault_sha256'];
        $lines[0] = json_encode($intent, JSON_THROW_ON_ERROR)."\n";
        File::put($ledgerPath, implode('', $lines));
        $package['ledger_sha256'] = $context['ledger_sha256'] = hash_file('sha256', $ledgerPath);
        File::put($files->packagePath($context['package_id'], true), json_encode($package, JSON_THROW_ON_ERROR));
        $context['package_sha256'] = hash_file('sha256', $files->packagePath($context['package_id'], true));
        $this->expectException(RuntimeException::class);
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', static function (): void {});
    }

    #[TestWith(['operation_id'])]
    #[TestWith(['group_id'])]
    #[TestWith(['kind'])]
    #[TestWith(['subjects'])]
    public function test_decrypted_body_must_match_the_receipt_family_kind_and_ledger_subjects(string $field): void
    {
        $override = match ($field) {
            'operation_id' => (string) Str::uuid(), 'group_id' => 99999, 'kind' => 'member_delete',
            'subjects' => [['user_id' => 99999, 'membership_id' => 1, 'username' => 'other', 'display_name' => 'Other', 'email' => null]],
        };
        [$context, $code] = $this->decryptionFixture([$field => $override]);
        $this->expectException(RuntimeException::class);
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', static function (): void {});
    }

    public function test_unresolved_source_ledger_prevents_export(): void
    {
        $this->expectException(RuntimeException::class);
        $this->decryptionFixture([], false);
    }

    public function test_audit_failure_and_server_environment_prevent_local_decryption(): void
    {
        [$context, $code] = $this->decryptionFixture();
        config(['famie.lifecycle_audit_root' => null]);
        $called = false;
        try {
            app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', function () use (&$called): void {
                $called = true;
            });
            $this->fail('Unaudited decryption accepted.');
        } catch (RuntimeException) {
            $this->assertFalse($called);
        }
        config(['famie.lifecycle_audit_root' => $this->root.'/audit']);
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', static function (): void {});
    }

    public function test_offline_attempt_limits_survive_service_recreation_without_database_counters(): void
    {
        [$context, $code] = $this->decryptionFixture();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                app(LifecycleInquiryDecrypt::class)->decrypt($context, str_repeat('B', 43), $this->passphrase, 'ops-01', static function (): void {});
                $this->fail('Invalid inquiry accepted.');
            } catch (RuntimeException) {
                $this->assertFileExists(app(LifecycleOperatorFiles::class)->packagePath($context['package_id'], true));
            }
        }
        $called = false;
        try {
            app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'another-operator', function () use (&$called): void {
                $called = true;
            });
            $this->fail('Operator change bypassed the key limit.');
        } catch (RuntimeException) {
            $this->assertFalse($called);
        }
        $this->travel(901)->seconds();
        app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'another-operator', function () use (&$called): void {
            $called = true;
        });
        $this->assertTrue($called);
    }

    public function test_plaintext_is_not_released_when_the_final_success_audit_cannot_be_saved(): void
    {
        [$context, $code] = $this->decryptionFixture();
        $realAudit = app(LifecycleAudit::class);
        $mock = Mockery::mock(LifecycleAudit::class);
        foreach (['started', 'failure'] as $outcome) {
            $mock->shouldReceive('record')->with('decrypt', $outcome, 'ops-01', $context['receipt_id'], 'test-key')
                ->andReturnUsing(function () use ($realAudit, $outcome, $context): void {
                    $realAudit->record('decrypt', $outcome, 'ops-01', $context['receipt_id'], 'test-key');
                });
        }
        $mock->shouldReceive('record')->with('decrypt', 'success', 'ops-01', $context['receipt_id'], 'test-key')
            ->once()->andThrow(new RuntimeException('Audit write failed'));
        $this->app->instance(LifecycleAudit::class, $mock);
        $called = false;
        try {
            app(LifecycleInquiryDecrypt::class)->decrypt($context, $code, $this->passphrase, 'ops-01', function () use (&$called): void {
                $called = true;
            });
            $this->fail('Unaudited plaintext released.');
        } catch (RuntimeException) {
            $this->assertFalse($called);
        }
    }

    /** @return array{array<string,string>, string, array<string,mixed>} */
    private function decryptionFixture(array $override = [], bool $commitLedger = true, string $kind = 'group_withdraw'): array
    {
        $this->freezeTime();
        config(['app.url' => 'https://staging.example.test']);
        [$operation, $code] = $this->operation();
        $operation->forceFill(['kind' => $kind])->save();
        $key = app(LifecycleOperatorKey::class)->create('test-key', $this->passphrase);
        $files = app(LifecycleOperatorFiles::class);
        $files->create('test-key', $key);
        config(['famie.lifecycle_public_key' => $key['public_key'], 'famie.lifecycle_key_id' => 'test-key']);
        $payload = array_replace(['operation_id' => $operation->id, 'kind' => $operation->kind, 'group_id' => $operation->group_id,
            'group_name' => $kind === 'group_withdraw' ? 'Private family name' : null, 'committed_at' => $operation->committed_at->utc()->toIso8601String(),
            'subjects' => [['user_id' => $operation->actor_user_id, 'membership_id' => 1, 'username' => 'private-person',
                'display_name' => 'Private display name', 'email' => 'private-person@example.test']]], $override);
        $vault = app(LifecycleVault::class)->store($operation, $payload);
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => $vault['sha256'], 'subject_ids' => [$operation->actor_user_id]]);
        if ($commitLedger) {
            $ledger->append($operation, 'committed');
        }
        $ledgerSha = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $exported = app(LifecycleInquiryExport::class)->create($operation->id, $code, 'ops-01', $ledgerSha);
        $this->assertTrue(File::copy($files->packagePath($exported['package_id']), $files->packagePath($exported['package_id'], true)));

        return [['receipt_id' => $operation->id, 'package_id' => $exported['package_id'], 'key_id' => 'test-key',
            'source_url' => config('app.url'), 'environment' => app()->environment(), 'package_sha256' => $exported['sha256'], 'ledger_sha256' => $ledgerSha], $code, $payload];
    }

    public function test_hold_dry_run_apply_and_release_preserve_original_expiry(): void
    {
        [$operation] = $this->operation();
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => str_repeat('a', 64), 'subject_ids' => [$operation->actor_user_id]]);
        $ledger->append($operation, 'committed');
        $checksum = fn () => hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $review = now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z');
        $expiry = $operation->retention_until->toIso8601String();
        $service = app(LifecycleHold::class);
        $plan = $service->change($operation->id, 'case-01', $review, 'ops-01', $checksum());
        $this->assertFalse($plan['applied']);
        $this->assertDatabaseCount('account_lifecycle_holds', 0);
        $this->assertSame([], File::files($this->root.'/audit'));
        $result = $service->change($operation->id, 'case-01', $review, 'ops-01', $checksum(), apply: true);
        $this->assertTrue($operation->hasActiveHold());
        $this->assertSame('all', $result['scope']);
        $verified = app(LifecycleLedgerInspector::class)->inspect($checksum(), $operation->id);
        $this->assertFalse($verified['holds'][$result['hold_id']]['released']);
        $this->travel(8)->days();
        $this->assertTrue($operation->hasActiveHold());
        $operation->forceFill(['cleanup_state' => 'pending'])->save();
        app(AccountLifecycleCleanup::class)->process($operation);
        $this->assertSame('pending', $operation->fresh()->cleanup_state);
        $service->change($operation->id, 'case-01', $review, 'ops-02', $checksum(), release: true, apply: true);
        $this->assertFalse($operation->hasActiveHold());
        $this->assertSame($expiry, $operation->fresh()->retention_until->toIso8601String());
        $this->assertTrue(app(LifecycleLedgerInspector::class)->inspect($checksum(), $operation->id)['holds'][$result['hold_id']]['released']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_failed_hold_ledger_write_keeps_protection_and_prevents_blind_retry(bool $release): void
    {
        [$operation] = $this->operation();
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => str_repeat('a', 64), 'subject_ids' => [$operation->actor_user_id]]);
        $ledger->append($operation, 'committed');
        $review = now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z');
        if ($release) {
            app(LifecycleHold::class)->change($operation->id, 'case-01', $review, 'ops-01', hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'), apply: true);
        }
        $broken = Mockery::mock(LifecycleLedger::class);
        $broken->shouldReceive('append')->once()->andThrow(new RuntimeException('disk unavailable'));
        $service = new LifecycleHold(app(LifecycleLedgerInspector::class), $broken, app(LifecycleAudit::class));
        try {
            $service->change($operation->id, 'case-01', $review, 'ops-01', hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'), release: $release, apply: true);
            $this->fail('Failed evidence accepted.');
        } catch (RuntimeException) {
            $this->assertTrue($operation->hasActiveHold());
        }
        if (! $release) {
            $this->expectException(RuntimeException::class);
            app(LifecycleHold::class)->change($operation->id, 'case-01', $review, 'ops-01', hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'), release: true, apply: true);
        }
    }

    public function test_restored_database_missing_a_hold_cannot_add_or_release_without_reconciliation(): void
    {
        [$operation] = $this->operation();
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => str_repeat('a', 64), 'subject_ids' => [$operation->actor_user_id]]);
        $ledger->append($operation, 'committed');
        $review = now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z');
        app(LifecycleHold::class)->change($operation->id, 'case-01', $review, 'ops-01', hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'), apply: true);
        DB::table('account_lifecycle_holds')->delete();
        $this->expectException(RuntimeException::class);
        app(LifecycleHold::class)->change($operation->id, 'case-02', $review, 'ops-01', hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'), apply: true);
    }

    #[TestWith(['2026-02-30T00:00:00Z'])]
    #[TestWith(['2099-01-01T00:00:00Z'])]
    #[TestWith(['yesterday'])]
    public function test_invalid_hold_review_dates_never_create_protection_rows(string $review): void
    {
        [$operation] = $this->operation();
        try {
            app(LifecycleHold::class)->change($operation->id, 'case-01', $review, 'ops-01', str_repeat('a', 64), apply: true);
            $this->fail('Invalid review date accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('account_lifecycle_holds', 0);
        }
    }

    public function test_release_audit_failure_leaves_hold_active_and_requires_reconciliation(): void
    {
        [$operation] = $this->operation();
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => str_repeat('a', 64), 'subject_ids' => [$operation->actor_user_id]]);
        $ledger->append($operation, 'committed');
        $review = now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z');
        $path = $this->root.'/ledger/account-lifecycle.jsonl';
        app(LifecycleHold::class)->change($operation->id, 'case-01', $review, 'ops-01', hash_file('sha256', $path), apply: true);
        $audit = Mockery::mock(LifecycleAudit::class);
        $audit->shouldReceive('record')->with('hold.released', 'started', 'ops-01', $operation->id)->once();
        $audit->shouldReceive('record')->with('hold.released', 'success', 'ops-01', $operation->id)->once()->andThrow(new RuntimeException('audit unavailable'));
        $audit->shouldReceive('record')->with('hold.released', 'failure', 'ops-01', $operation->id)->once();
        try {
            (new LifecycleHold(app(LifecycleLedgerInspector::class), $ledger, $audit))->change($operation->id, 'case-01', $review, 'ops-01', hash_file('sha256', $path), release: true, apply: true);
            $this->fail('Release succeeded despite audit failure.');
        } catch (RuntimeException) {
            $this->assertTrue($operation->hasActiveHold());
        }
        $this->expectException(RuntimeException::class);
        app(LifecycleHold::class)->change($operation->id, 'case-01', $review, 'ops-01', hash_file('sha256', $path), release: true, apply: true);
    }

    public function test_operator_copy_expiry_is_dry_run_then_removes_only_the_selected_expired_copy_without_sql(): void
    {
        [$context] = $this->decryptionFixture();
        $expected = array_intersect_key($context, array_flip(['package_id', 'package_sha256', 'ledger_sha256', 'source_url', 'environment']));
        $copies = app(LifecycleCopyExpiry::class);
        $this->assertFalse($copies->expire($expected, 'ops-01', true)['expired']);
        $this->travel(30)->days();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $plan = $copies->expire($expected, 'ops-01', true);
        $this->assertTrue($plan['expired']);
        $this->assertFalse($plan['deleted']);
        $path = app(LifecycleOperatorFiles::class)->packagePath($context['package_id'], true);
        $this->assertFileExists($path);
        $this->assertTrue($copies->expire($expected, 'ops-01', true, true)['deleted']);
        $this->assertFileDoesNotExist($path);
        $this->assertFileExists(app(LifecycleOperatorFiles::class)->packagePath($context['package_id']));
        $this->assertFileExists(app(LifecycleOperatorFiles::class)->path('test-key'));
        $this->assertFileExists($this->root.'/ledger/account-lifecycle.jsonl');
        $this->assertSame([], $queries);
        $this->assertSame(0, app(LifecycleAudit::class)->prune()['expired']);
    }

    public function test_latest_hold_evidence_blocks_expired_copies_until_explicit_release(): void
    {
        [$context] = $this->decryptionFixture();
        $review = now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z');
        app(LifecycleHold::class)->change($context['receipt_id'], 'case-01', $review, 'ops-01', $context['ledger_sha256'], apply: true);
        $context['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $expected = array_intersect_key($context, array_flip(['package_id', 'package_sha256', 'ledger_sha256', 'source_url', 'environment']));
        $this->travel(31)->days();
        $this->assertTrue(app(LifecycleCopyExpiry::class)->expire($expected, 'ops-01', true)['held']);
        try {
            app(LifecycleCopyExpiry::class)->expire($expected, 'ops-01', true, true);
            $this->fail('Held copy removed.');
        } catch (RuntimeException) {
            $this->assertFileExists(app(LifecycleOperatorFiles::class)->packagePath($context['package_id'], true));
        }
        app(LifecycleHold::class)->change($context['receipt_id'], 'case-01', $review, 'ops-01', $context['ledger_sha256'], release: true, apply: true);
        $expected['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $this->assertTrue(app(LifecycleCopyExpiry::class)->expire($expected, 'ops-01', true, true)['deleted']);
    }

    #[TestWith(['checksum'])]
    #[TestWith(['environment'])]
    #[TestWith(['not-expired'])]
    #[TestWith(['linked'])]
    #[TestWith(['audit'])]
    public function test_unsafe_or_unconfirmed_copy_expiry_preserves_files(string $failure): void
    {
        [$context] = $this->decryptionFixture();
        $expected = array_intersect_key($context, array_flip(['package_id', 'package_sha256', 'ledger_sha256', 'source_url', 'environment']));
        $path = app(LifecycleOperatorFiles::class)->packagePath($context['package_id'], true);
        if ($failure !== 'not-expired') {
            $this->travel(31)->days();
        }
        if ($failure === 'checksum') {
            $expected['package_sha256'] = str_repeat('a', 64);
        } elseif ($failure === 'environment') {
            $expected['environment'] = 'another-source';
        } elseif ($failure === 'linked') {
            $this->assertTrue(link($path, $this->root.'/linked-package'));
        } elseif ($failure === 'audit') {
            config(['famie.lifecycle_audit_root' => base_path('public')]);
        }
        try {
            app(LifecycleCopyExpiry::class)->expire($expected, 'ops-01', true, true);
            $this->fail('Unsafe expiry accepted.');
        } catch (RuntimeException|ServiceUnavailableHttpException) {
            $this->assertFileExists($path);
        }
    }

    public function test_export_copy_expiry_respects_a_protective_database_hold_even_without_ledger_evidence(): void
    {
        [$context] = $this->decryptionFixture();
        DB::table('account_lifecycle_holds')->insert(['operation_id' => $context['receipt_id'], 'scope' => 'all', 'reference' => 'case-01',
            'started_at' => now(), 'review_at' => now()->addDays(7), 'released_at' => null]);
        $this->travel(31)->days();
        $expected = array_intersect_key($context, array_flip(['package_id', 'package_sha256', 'ledger_sha256', 'source_url', 'environment']));
        try {
            app(LifecycleCopyExpiry::class)->expire($expected, 'ops-01', false, true);
            $this->fail('Source protection bypassed.');
        } catch (RuntimeException) {
            $this->assertFileExists(app(LifecycleOperatorFiles::class)->packagePath($context['package_id']));
        }
    }

    public function test_restore_assessment_reports_resurrected_accounts_tokens_and_content_without_writes(): void
    {
        [$context] = $this->decryptionFixture();
        $operation = AccountLifecycleOperation::query()->findOrFail($context['receipt_id']);
        $member = GroupMember::factory()->create(['group_id' => $operation->group_id, 'user_id' => $operation->actor_user_id]);
        $operation->subjects()->create(['user_id' => $operation->actor_user_id, 'membership_id' => $member->id]);
        User::query()->findOrFail($operation->actor_user_id)->createToken('restored-test-token');
        $category = Category::query()->create(['group_id' => $operation->group_id, 'name' => 'Test category', 'color_code' => '#10B981', 'sort_order' => 1]);
        ActivityLog::query()->create(['user_id' => $operation->actor_user_id, 'group_id' => $operation->group_id,
            'category_id' => $category->id, 'activity_date' => now()->toDateString(), 'content' => 'Restored private content', 'note' => 'Private note']);
        $this->travel(31)->days();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $result = app(LifecycleRestoreCheck::class)->check($context['ledger_sha256']);
        $this->assertFalse($result['database_changed']);
        $this->assertFalse($result['reopen_allowed']);
        foreach (['account_mask_required', 'membership_retirement_required', 'family_retirement_required', 'token_revocation_required', 'content_erasure_required', 'expired_credential_present'] as $finding) {
            $this->assertSame(1, $result['findings'][$finding]);
        }
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query));
        }
        $output = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Restored private content', $output);
        $this->assertStringNotContainsString('private-person@example.test', $output);
    }

    public function test_restore_assessment_detects_commits_and_holds_missing_from_the_restored_database(): void
    {
        [$context] = $this->decryptionFixture();
        $review = now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z');
        app(LifecycleHold::class)->change($context['receipt_id'], 'case-01', $review, 'ops-01', $context['ledger_sha256'], apply: true);
        DB::table('account_lifecycle_holds')->delete();
        $checksum = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $this->assertSame(1, app(LifecycleRestoreCheck::class)->check($checksum)['findings']['hold_evidence_mismatch']);
        AccountLifecycleOperation::query()->whereKey($context['receipt_id'])->delete();
        $this->assertSame(1, app(LifecycleRestoreCheck::class)->check($checksum)['findings']['missing_committed_operation']);
    }

    public function test_restore_assessment_has_no_apply_mode_and_refuses_server_environment(): void
    {
        $this->artisan('famie:lifecycle-operator restore-check --operator=ops-01 --apply')->assertFailed();
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        app(LifecycleRestoreCheck::class)->check(str_repeat('a', 64));
    }

    public function test_a_consistent_restore_assessment_still_does_not_authorize_reopening(): void
    {
        [$context] = $this->decryptionFixture();
        $operation = AccountLifecycleOperation::query()->findOrFail($context['receipt_id']);
        $user = User::query()->findOrFail($operation->actor_user_id);
        $member = GroupMember::factory()->create(['group_id' => $operation->group_id, 'user_id' => $user->id]);
        $operation->subjects()->create(['user_id' => $user->id, 'membership_id' => $member->id]);
        $user->forceFill(['username' => 'deleted:'.$user->id.':'.substr(str_replace('-', '', $operation->id), 0, 8),
            'display_name' => 'Deleted member', 'email' => null, 'email_verified_at' => null, 'remember_token' => null,
            'pending_email' => null, 'email_verification_token' => null, 'email_verification_expires_at' => null, 'lifecycle_operation_id' => $operation->id])->save();
        $user->delete();
        $member->forceFill(['status' => 'inactive', 'lifecycle_operation_id' => $operation->id])->save();
        $member->delete();
        Group::query()->whereKey($operation->group_id)->update(['name' => 'Withdrawn family', 'withdrawn_at' => $operation->committed_at, 'lifecycle_operation_id' => $operation->id]);
        $result = app(LifecycleRestoreCheck::class)->check($context['ledger_sha256']);
        $this->assertSame([], $result['findings']);
        $this->assertTrue($result['assessment_only']);
        $this->assertFalse($result['reopen_allowed']);
    }

    public function test_ledger_snapshot_stays_locked_until_the_consumer_finishes(): void
    {
        [$context] = $this->decryptionFixture();
        $result = app(LifecycleLedgerInspector::class)->inspect($context['ledger_sha256'], consume: function (array $snapshot): array {
            $writer = fopen($this->root.'/ledger/account-lifecycle.jsonl', 'c+b');
            try {
                $this->assertFalse(flock($writer, LOCK_EX | LOCK_NB));
            } finally {
                fclose($writer);
            }

            return ['verified' => $snapshot['committed']];
        });
        $this->assertSame(1, $result['verified']);
    }

    public function test_copy_changed_after_inspection_is_preserved_instead_of_deleted(): void
    {
        [$context] = $this->decryptionFixture();
        $this->travel(31)->days();
        $expected = array_intersect_key($context, array_flip(['package_id', 'package_sha256', 'ledger_sha256', 'source_url', 'environment']));
        $files = app(LifecycleOperatorFiles::class);
        $path = $files->packagePath($context['package_id'], true);
        $audit = Mockery::mock(LifecycleAudit::class);
        $auditContext = ['package_id' => $context['package_id'], 'copy_location' => 'operator'];
        $audit->shouldReceive('record')->with('copy.expire', 'started', 'ops-01', $context['receipt_id'], null, $auditContext)->once()
            ->andReturnUsing(function () use ($path): void {
                File::append($path, ' ');
            });
        $audit->shouldReceive('record')->with('copy.expire', 'failure', 'ops-01', $context['receipt_id'], null, $auditContext)->once();
        try {
            (new LifecycleCopyExpiry($files, app(LifecycleLedgerInspector::class), $audit))->expire($expected, 'ops-01', true, true);
            $this->fail('Changed package removed.');
        } catch (RuntimeException) {
            $this->assertFileExists($path);
        }
    }

    public function test_copy_expiry_command_requires_an_explicit_location_and_defaults_to_dry_run(): void
    {
        [$context] = $this->decryptionFixture();
        $this->travel(31)->days();
        $options = ['action' => 'copy-expire', '--operator' => 'ops-01', '--package' => $context['package_id'],
            '--package-sha256' => $context['package_sha256'], '--ledger-sha256' => $context['ledger_sha256'],
            '--source-url' => $context['source_url'], '--source-environment' => $context['environment']];
        $this->artisan('famie:lifecycle-operator', $options)->assertFailed();
        $this->artisan('famie:lifecycle-operator', [...$options, '--copy-location' => 'operator'])
            ->expectsOutputToContain('"deleted":false')->assertSuccessful();
        $this->assertFileExists(app(LifecycleOperatorFiles::class)->packagePath($context['package_id'], true));
    }

    public function test_backup_coverage_matches_all_catalogued_generations_and_copied_image_evidence_without_writes(): void
    {
        [$coverage, $sha, $catalogue, $inventory, $sync, $ledgerSha] = $this->backupCoverageFixture();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $result = app(LifecycleBackupCoverage::class)->check($coverage['coverage_id'], $sha, $coverage['catalogue_sha256'], $ledgerSha, $coverage['source_url'], $coverage['environment']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(22, $result['catalogued_generations']);
        $this->assertSame(22, $result['inventoried_generations']);
        $this->assertSame(0, $result['missing_generations']);
        $this->assertTrue($result['catalogued_generations_verified']);
        $this->assertSame(1, $result['verified_evidence_bundles']);
        $this->assertTrue($result['separate_medium_recorded']);
        $this->assertSame(now()->utc()->subDay()->format('Y-m-d\TH:i:s\Z'), $result['minimum_ledger_retention_until']);
        $this->assertFalse($result['physical_medium_verified']);
        $this->assertFalse($result['remote_current_state_verified']);
        $this->assertFalse($result['ledger_expiry_authorized']);
        $this->assertFalse($result['reopen_allowed']);
        $this->assertSame([], $queries);
        $this->assertSame([], File::files($this->root.'/audit'));
        $this->assertSame($ledgerSha, hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        $this->assertSame($sha, hash_file('sha256', $this->root.'/keys/'.$coverage['coverage_id'].'.backup-coverage.json'));
    }

    #[TestWith(['missing'])]
    #[TestWith(['unexpected'])]
    #[TestWith(['metadata'])]
    #[TestWith(['catalogue-incomplete'])]
    #[TestWith(['nested-contents'])]
    #[TestWith(['stale'])]
    #[TestWith(['older-inventory'])]
    #[TestWith(['expired-parent'])]
    #[TestWith(['provider-limit'])]
    public function test_backup_coverage_does_not_shorten_retention_when_generations_are_unreconciled(string $case): void
    {
        [$coverage, , $catalogue, $inventory, , $ledgerSha] = $this->backupCoverageFixture();
        if ($case === 'missing') {
            array_pop($inventory['copies']);
            $blocker = 'catalogued_generations_missing';
        } elseif ($case === 'unexpected') {
            $copy = $inventory['copies'][0];
            $copy['copy_id'] = (string) Str::uuid();
            $inventory['copies'][] = $copy;
            $blocker = 'uncatalogued_generations_present';
        } elseif ($case === 'metadata') {
            $inventory['copies'][21]['verification_ref'] = 'different-verification';
            $blocker = 'generation_metadata_mismatch';
        } elseif ($case === 'catalogue-incomplete') {
            $catalogue['coverage_complete'] = false;
            $blocker = 'generation_catalogue_incomplete';
        } elseif ($case === 'nested-contents') {
            $inventory['copies'][21]['contents'] = ['vault'];
            $catalogue['copies'][21]['contents'] = ['vault'];
            $blocker = 'nested_copy_contents_omitted';
        } elseif ($case === 'older-inventory') {
            $inventory['inventoried_at'] = now()->utc()->subHour()->format('Y-m-d\TH:i:s\Z');
            $blocker = 'inventory_precedes_catalogue';
        } elseif ($case === 'expired-parent') {
            $inventory['copies'][21]['created_at'] = now()->utc()->subDays(43)->format('Y-m-d\TH:i:s\Z');
            $catalogue['copies'][21]['created_at'] = $inventory['copies'][21]['created_at'];
            $blocker = 'expired_generation_reused_as_parent';
        } elseif ($case === 'provider-limit') {
            $copy = $inventory['copies'][21];
            $copy['copy_id'] = (string) Str::uuid();
            $inventory['copies'][] = $copy;
            $catalogue['copies'][] = $copy;
            $blocker = 'provider_generation_limit_exceeded';
        } else {
            $this->travel(25)->hours();
            $blocker = 'coverage_records_stale';
        }
        $coverage['inventory_sha256'] = $this->controlRecord($inventory, 'backup-inventory');
        $coverage['catalogue_sha256'] = $this->controlRecord($catalogue, 'backup-catalogue');
        $sha = $this->controlRecord($coverage, 'backup-coverage');
        $result = app(LifecycleBackupCoverage::class)->check($coverage['coverage_id'], $sha, $coverage['catalogue_sha256'], $ledgerSha, $coverage['source_url'], $coverage['environment']);
        $this->assertContains($blocker, $result['blockers']);
        $this->assertFalse($result['catalogued_generations_verified']);
        $this->assertNull($result['minimum_ledger_retention_until']);
        $this->assertFalse($result['ledger_expiry_authorized']);
    }

    public function test_backup_coverage_does_not_ignore_an_unlisted_usb_copy_with_an_unknown_deadline(): void
    {
        [$coverage, , $catalogue, , , $ledgerSha] = $this->backupCoverageFixture();
        $copy = $catalogue['copies'][0];
        $copy = [...$copy, 'copy_id' => (string) Str::uuid(), 'medium_id' => 'investigation-usb', 'kind' => 'usb',
            'restorable_until' => null, 'expired_verified_at' => null, 'verification_ref' => null];
        $catalogue['media'][] = ['medium_id' => 'investigation-usb', 'kind' => 'usb'];
        $catalogue['copies'][] = $copy;
        $coverage['catalogue_sha256'] = $this->controlRecord($catalogue, 'backup-catalogue');
        $sha = $this->controlRecord($coverage, 'backup-coverage');
        $result = app(LifecycleBackupCoverage::class)->check($coverage['coverage_id'], $sha, $coverage['catalogue_sha256'], $ledgerSha, $coverage['source_url'], $coverage['environment']);
        $this->assertSame(1, $result['missing_generations']);
        $this->assertContains('catalogued_generations_missing', $result['blockers']);
        $this->assertNull($result['minimum_ledger_retention_until']);
    }

    public function test_copied_evidence_is_validated_independently_of_the_original_image_manifest(): void
    {
        [$coverage, , , , $sync, $ledgerSha] = $this->backupCoverageFixture();
        $receipt = $sync['image_manifests'][0]['receipt_id'];
        File::delete(app(LifecycleOperatorFiles::class)->imageEvidencePath($receipt));
        $sha = $this->controlRecord($sync, 'ledger-sync');
        $result = app(LifecycleBackupAssessment::class)->synchronization($sync['sync_id'], $sha, $ledgerSha, $coverage['source_url'], 'testing');
        $this->assertTrue($result['bundle_verified']);
        $this->assertSame(1, $result['verified_image_manifests']);
        $this->assertSame([], $result['blockers']);
        $this->assertFalse($result['physical_medium_verified']);
    }

    #[TestWith(['ledger'])]
    #[TestWith(['missing-manifest'])]
    #[TestWith(['tampered-manifest'])]
    #[TestWith(['omitted-list'])]
    #[TestWith(['duplicate-list'])]
    #[TestWith(['extra-file'])]
    #[TestWith(['hardlink'])]
    public function test_copied_evidence_sync_rejects_missing_changed_or_unlisted_files(string $case): void
    {
        [$coverage, , , , $sync, $ledgerSha] = $this->backupCoverageFixture();
        $directory = app(LifecycleOperatorFiles::class)->copiedEvidenceDirectory($sync['sync_id']);
        $manifest = $directory.'/'.$sync['image_manifests'][0]['receipt_id'].'.images.json';
        if ($case === 'ledger') {
            File::append($directory.'/account-lifecycle.jsonl', 'tampered');
        } elseif ($case === 'missing-manifest') {
            File::delete($manifest);
        } elseif ($case === 'tampered-manifest') {
            $changed = json_decode(File::get($manifest), true, 16, JSON_THROW_ON_ERROR);
            $changed['images'][0]['bytes'] = 5;
            File::put($manifest, json_encode($changed, JSON_THROW_ON_ERROR));
        } elseif ($case === 'omitted-list') {
            $sync['image_manifests'] = [];
        } elseif ($case === 'duplicate-list') {
            $sync['image_manifests'][] = $sync['image_manifests'][0];
        } elseif ($case === 'extra-file') {
            File::put($directory.'/unlisted-private-copy', 'synthetic');
        } else {
            $this->assertTrue(link($manifest, $this->root.'/linked-copied-evidence'));
        }
        $sha = $this->controlRecord($sync, 'ledger-sync');
        $this->expectException(RuntimeException::class);
        app(LifecycleBackupAssessment::class)->synchronization($sync['sync_id'], $sha, $ledgerSha, $coverage['source_url'], 'testing');
    }

    public function test_old_sync_receipt_does_not_verify_new_image_evidence(): void
    {
        [$coverage, , , , $sync, $ledgerSha] = $this->backupCoverageFixture();
        $sync['format_version'] = 1;
        unset($sync['destination_medium_id'], $sync['image_manifests']);
        $sha = $this->controlRecord($sync, 'ledger-sync');
        $result = app(LifecycleBackupAssessment::class)->synchronization($sync['sync_id'], $sha, $ledgerSha, $coverage['source_url'], 'testing');
        $this->assertFalse($result['bundle_verified']);
        $this->assertContains('image_evidence_not_verified', $result['blockers']);
    }

    public function test_backup_coverage_rejects_a_wrong_independently_trusted_catalogue_hash(): void
    {
        [$coverage, $sha, , , , $ledgerSha] = $this->backupCoverageFixture();
        $this->expectException(RuntimeException::class);
        app(LifecycleBackupCoverage::class)->check($coverage['coverage_id'], $sha, str_repeat('a', 64), $ledgerSha, $coverage['source_url'], 'testing');
    }

    public function test_backup_coverage_requires_a_fresh_bundle_recorded_outside_server_backup_media(): void
    {
        [$coverage, , , , $sync, $ledgerSha] = $this->backupCoverageFixture();
        $sync['destination_medium_id'] = 'server-01';
        $coverage['syncs'][0]['sync_sha256'] = $this->controlRecord($sync, 'ledger-sync');
        $sha = $this->controlRecord($coverage, 'backup-coverage');
        $result = app(LifecycleBackupCoverage::class)->check($coverage['coverage_id'], $sha, $coverage['catalogue_sha256'], $ledgerSha, $coverage['source_url'], 'testing');
        $this->assertSame(1, $result['verified_evidence_bundles']);
        $this->assertFalse($result['separate_medium_recorded']);
        $this->assertContains('separate_evidence_medium_not_recorded', $result['blockers']);
    }

    #[TestWith(['none'])]
    #[TestWith(['stale'])]
    #[TestWith(['legacy'])]
    public function test_backup_coverage_requires_actual_and_fresh_evidence_bundle_verification(string $case): void
    {
        [$coverage, , , , $sync, $ledgerSha] = $this->backupCoverageFixture();
        if ($case === 'none') {
            $coverage['syncs'] = [];
        } elseif ($case === 'legacy') {
            $sync['format_version'] = 1;
            unset($sync['destination_medium_id'], $sync['image_manifests']);
            $coverage['syncs'][0]['sync_sha256'] = $this->controlRecord($sync, 'ledger-sync');
        } else {
            $this->travel(16)->minutes();
        }
        $sha = $this->controlRecord($coverage, 'backup-coverage');
        $result = app(LifecycleBackupCoverage::class)->check($coverage['coverage_id'], $sha, $coverage['catalogue_sha256'], $ledgerSha, $coverage['source_url'], 'testing');
        $this->assertSame(0, $result['verified_evidence_bundles']);
        $this->assertContains('evidence_bundle_not_verified', $result['blockers']);
        $this->assertFalse($result['physical_medium_verified']);
        $this->assertFalse($result['archive_contents_verified']);
    }

    #[TestWith(['duplicate-copy'])]
    #[TestWith(['duplicate-medium'])]
    #[TestWith(['unknown-medium'])]
    #[TestWith(['wrong-source'])]
    #[TestWith(['unlisted-field'])]
    public function test_backup_coverage_rejects_contradictory_or_unexpected_catalogue_metadata(string $case): void
    {
        [$coverage, , $catalogue, , , $ledgerSha] = $this->backupCoverageFixture();
        if ($case === 'duplicate-copy') {
            $catalogue['copies'][] = $catalogue['copies'][0];
        } elseif ($case === 'duplicate-medium') {
            $catalogue['media'][] = $catalogue['media'][0];
        } elseif ($case === 'unknown-medium') {
            $catalogue['copies'][0]['medium_id'] = 'unknown-location';
        } elseif ($case === 'wrong-source') {
            $catalogue['source_url'] = 'https://other.example.test';
        } else {
            $catalogue['operator_email'] = 'synthetic@example.test';
        }
        $coverage['catalogue_sha256'] = $this->controlRecord($catalogue, 'backup-catalogue');
        $sha = $this->controlRecord($coverage, 'backup-coverage');
        $this->expectException(RuntimeException::class);
        app(LifecycleBackupCoverage::class)->check($coverage['coverage_id'], $sha, $coverage['catalogue_sha256'], $ledgerSha, $coverage['source_url'], 'testing');
    }

    public function test_backup_coverage_cli_records_a_private_audit_but_cannot_apply_or_authorize_expiry(): void
    {
        [$coverage, $sha, , , , $ledgerSha] = $this->backupCoverageFixture();
        $options = ['action' => 'backup-coverage-check', '--operator' => 'ops-01', '--coverage' => $coverage['coverage_id'], '--coverage-sha256' => $sha,
            '--catalogue-sha256' => $coverage['catalogue_sha256'], '--ledger-sha256' => $ledgerSha, '--source-url' => $coverage['source_url'], '--source-environment' => 'testing'];
        $this->artisan('famie:lifecycle-operator', $options)->expectsOutputToContain('"physical_medium_verified":false')->assertSuccessful();
        $this->artisan('famie:lifecycle-operator', [...$options, '--apply' => true])->assertFailed();
        $this->assertSame($ledgerSha, hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        $this->assertStringContainsString('backup.coverage', File::get($this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl'));
        $this->assertSame(0, app(LifecycleAudit::class)->prune()['expired']);
    }

    /** @return array{array<string,mixed>,string,array<string,mixed>,array<string,mixed>,array<string,mixed>,string} */
    private function backupCoverageFixture(): array
    {
        $this->freezeTime();
        [$inventory] = $this->backupInventoryFixture();
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        $files = app(LifecycleOperatorFiles::class);
        $image = ['format_version' => 1, 'receipt_id' => $operation->id, 'group_id' => $operation->group_id, 'kind' => $operation->kind,
            'subject_ids' => [$operation->actor_user_id], 'images' => [['image_id' => 1, 'user_id' => $operation->actor_user_id, 'disk' => 'local',
                'path' => 'activity-images/synthetic-coverage.webp', 'bytes' => 4, 'sha256' => hash('sha256', 'test')]]];
        $files->createImageEvidence($operation->id, $image);
        $imageSha = $files->readImageEvidence($operation->id)['sha256'];
        File::delete($this->root.'/ledger/account-lifecycle.jsonl');
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => str_repeat('a', 64), 'subject_ids' => [$operation->actor_user_id], 'image_manifest_sha256' => $imageSha]);
        $ledger->append($operation, 'committed');
        $ledger->append($operation, 'cleanup.progress', ['cleanup_state' => 'complete', 'content_cursor' => 0]);
        $ledgerSha = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $base = $inventory['copies'][0];
        $inventory['copies'] = [];
        for ($index = 0; $index < 7; $index++) {
            $until = now()->utc()->subDays(50 - $index)->format('Y-m-d\TH:i:s\Z');
            $inventory['copies'][] = [...$base, 'copy_id' => (string) Str::uuid(), 'created_at' => now()->utc()->subDays(57 - $index)->format('Y-m-d\TH:i:s\Z'),
                'restorable_until' => $until, 'expired_verified_at' => $until, 'verification_ref' => 'expiry-verified'];
        }
        $applications = $inventory['copies'];
        for ($index = 0; $index < 15; $index++) {
            $created = now()->utc()->subDays(60 - $index)->format('Y-m-d\TH:i:s\Z');
            $until = now()->utc()->subDays(45 - $index)->format('Y-m-d\TH:i:s\Z');
            $parents = array_column(array_values(array_filter($applications, fn ($copy) => $copy['created_at'] <= $created && $copy['restorable_until'] > $created)), 'copy_id');
            $inventory['copies'][] = [...$base, 'copy_id' => (string) Str::uuid(), 'medium_id' => 'provider-01', 'kind' => 'provider',
                'contents' => ['database', 'images', 'vault'], 'created_at' => $created, 'restorable_until' => $until, 'parent_ids' => $parents,
                'expired_verified_at' => $until, 'verification_ref' => 'expiry-verified'];
        }
        $catalogue = ['format_version' => 1, 'catalogue_id' => (string) Str::uuid(), 'source_url' => $inventory['source_url'], 'environment' => 'testing',
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'coverage_complete' => true,
            'media' => [['medium_id' => 'server-01', 'kind' => 'application'], ['medium_id' => 'provider-01', 'kind' => 'provider']], 'copies' => $inventory['copies']];
        $sync = ['format_version' => 2, 'sync_id' => (string) Str::uuid(), 'source_url' => $inventory['source_url'], 'environment' => 'testing',
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'received_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'ledger_sha256' => $ledgerSha,
            'destination_medium_id' => 'offline-usb-01', 'image_manifests' => [['receipt_id' => $operation->id, 'sha256' => $imageSha]]];
        $directory = $this->root.'/keys/'.$sync['sync_id'].'.evidence';
        File::makeDirectory($directory, 0700);
        File::copy($this->root.'/ledger/account-lifecycle.jsonl', $directory.'/account-lifecycle.jsonl');
        File::copy($files->imageEvidencePath($operation->id), $directory.'/'.$operation->id.'.images.json');
        $coverage = ['format_version' => 1, 'coverage_id' => (string) Str::uuid(), 'source_url' => $inventory['source_url'], 'environment' => 'testing',
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'inventory_id' => $inventory['inventory_id'], 'inventory_sha256' => $this->controlRecord($inventory, 'backup-inventory'),
            'catalogue_id' => $catalogue['catalogue_id'], 'catalogue_sha256' => $this->controlRecord($catalogue, 'backup-catalogue'),
            'syncs' => [['sync_id' => $sync['sync_id'], 'sync_sha256' => $this->controlRecord($sync, 'ledger-sync')]]];

        return [$coverage, $this->controlRecord($coverage, 'backup-coverage'), $catalogue, $inventory, $sync, $ledgerSha];
    }

    public function test_backup_inventory_uses_the_latest_nested_copy_deadline_without_authorizing_deletion(): void
    {
        $this->freezeTime();
        [$record, $ledgerSha] = $this->backupInventoryFixture();
        $copy = $record['copies'][0];
        $copy['copy_id'] = (string) Str::uuid();
        $copy['kind'] = 'provider';
        $copy['created_at'] = now()->utc()->subDay()->format('Y-m-d\TH:i:s\Z');
        $copy['restorable_until'] = now()->utc()->addDays(22)->format('Y-m-d\TH:i:s\Z');
        $copy['parent_ids'] = [$record['copies'][0]['copy_id']];
        $record['copies'][] = $copy;
        $sha = $this->controlRecord($record, 'backup-inventory');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $result = app(LifecycleBackupAssessment::class)->inventory($record['inventory_id'], $sha, $ledgerSha, $record['source_url'], $record['environment']);
        $this->assertSame($copy['restorable_until'], $result['last_restorable_until']);
        $this->assertSame(now()->utc()->addDays(52)->format('Y-m-d\TH:i:s\Z'), $result['minimum_ledger_retention_until']);
        $this->assertFalse($result['ledger_expiry_authorized']);
        $this->assertContains('cleanup_completion_not_verified', $result['blockers']);
        $this->assertSame([], $queries);
    }

    #[TestWith(['unknown'])]
    #[TestWith(['incomplete'])]
    #[TestWith(['stale'])]
    #[TestWith(['empty'])]
    public function test_unknown_or_incomplete_backup_inventory_never_shortens_retention(string $case): void
    {
        $this->freezeTime();
        [$record, $ledgerSha] = $this->backupInventoryFixture();
        if ($case === 'unknown') {
            $record['copies'][0]['restorable_until'] = null;
        } elseif ($case === 'incomplete') {
            $record['coverage_complete'] = false;
        } elseif ($case === 'empty') {
            $record['copies'] = [];
        }
        $sha = $this->controlRecord($record, 'backup-inventory');
        if ($case === 'stale') {
            $this->travel(25)->hours();
        }
        $result = app(LifecycleBackupAssessment::class)->inventory($record['inventory_id'], $sha, $ledgerSha, $record['source_url'], $record['environment']);
        $this->assertNull($result['minimum_ledger_retention_until']);
        $this->assertFalse($result['ledger_expiry_authorized']);
    }

    #[TestWith(['missing-parent'])]
    #[TestWith(['cycle'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['future'])]
    #[TestWith(['premature-verification'])]
    #[TestWith(['wrong-environment'])]
    public function test_ambiguous_backup_inventory_is_rejected(string $case): void
    {
        $this->freezeTime();
        [$record, $ledgerSha] = $this->backupInventoryFixture();
        if ($case === 'missing-parent') {
            $record['copies'][0]['parent_ids'] = [(string) Str::uuid()];
        } elseif ($case === 'cycle') {
            $copy = $record['copies'][0];
            $copy['copy_id'] = (string) Str::uuid();
            $copy['parent_ids'] = [$record['copies'][0]['copy_id']];
            $record['copies'][0]['parent_ids'] = [$copy['copy_id']];
            $record['copies'][] = $copy;
        } elseif ($case === 'duplicate') {
            $record['copies'][] = $record['copies'][0];
        } elseif ($case === 'future') {
            $record['copies'][0]['created_at'] = now()->utc()->addMinute()->format('Y-m-d\TH:i:s\Z');
        } elseif ($case === 'premature-verification') {
            $record['copies'][0]['expired_verified_at'] = $record['inventoried_at'];
            $record['copies'][0]['verification_ref'] = 'evidence-01';
        } else {
            $record['environment'] = 'another-source';
        }
        $sha = $this->controlRecord($record, 'backup-inventory');
        $this->expectException(RuntimeException::class);
        app(LifecycleBackupAssessment::class)->inventory($record['inventory_id'], $sha, $ledgerSha, $record['source_url'], 'testing');
    }

    public function test_verified_expired_copies_still_require_independent_cleanup_evidence(): void
    {
        $this->freezeTime();
        [$record, $ledgerSha] = $this->backupInventoryFixture();
        $record['copies'][0]['created_at'] = now()->utc()->subDays(100)->format('Y-m-d\TH:i:s\Z');
        $record['copies'][0]['restorable_until'] = now()->utc()->subDays(40)->format('Y-m-d\TH:i:s\Z');
        $record['copies'][0]['expired_verified_at'] = now()->utc()->subDays(39)->format('Y-m-d\TH:i:s\Z');
        $record['copies'][0]['verification_ref'] = 'disposal-01';
        $sha = $this->controlRecord($record, 'backup-inventory');
        $result = app(LifecycleBackupAssessment::class)->inventory($record['inventory_id'], $sha, $ledgerSha, $record['source_url'], $record['environment']);
        $this->assertSame(['cleanup_completion_not_verified'], $result['blockers']);
        $this->assertSame(now()->utc()->subDays(9)->format('Y-m-d\TH:i:s\Z'), $result['minimum_ledger_retention_until']);
        $this->assertFalse($result['ledger_expiry_authorized']);
    }

    public function test_sync_receipt_checks_snapshot_age_and_never_claims_live_remote_verification(): void
    {
        $this->freezeTime();
        [$inventory, $ledgerSha] = $this->backupInventoryFixture();
        $record = ['format_version' => 1, 'sync_id' => (string) Str::uuid(), 'source_url' => $inventory['source_url'],
            'environment' => 'testing', 'captured_at' => now()->utc()->subSeconds(10)->format('Y-m-d\TH:i:s\Z'),
            'received_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'ledger_sha256' => $ledgerSha];
        $sha = $this->controlRecord($record, 'ledger-sync');
        $service = app(LifecycleBackupAssessment::class);
        $result = $service->synchronization($record['sync_id'], $sha, $ledgerSha, $record['source_url'], $record['environment']);
        $this->assertTrue($result['snapshot_matches']);
        $this->assertTrue($result['fresh']);
        $this->assertFalse($result['remote_current_state_verified']);
        $this->travel(890)->seconds();
        $result = $service->synchronization($record['sync_id'], $sha, $ledgerSha, $record['source_url'], $record['environment']);
        $this->assertFalse($result['fresh']);
        $this->assertContains('sync_stale', $result['blockers']);
    }

    #[TestWith(['checksum'])]
    #[TestWith(['ledger'])]
    #[TestWith(['future'])]
    #[TestWith(['clock-order'])]
    public function test_sync_confirmation_rejects_mismatched_or_impossible_receipts(string $case): void
    {
        $this->freezeTime();
        [$inventory, $ledgerSha] = $this->backupInventoryFixture();
        $record = ['format_version' => 1, 'sync_id' => (string) Str::uuid(), 'source_url' => $inventory['source_url'],
            'environment' => 'testing', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'received_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'ledger_sha256' => $ledgerSha];
        if ($case === 'ledger') {
            $record['ledger_sha256'] = str_repeat('a', 64);
        } elseif ($case === 'future') {
            $record['received_at'] = now()->utc()->addMinute()->format('Y-m-d\TH:i:s\Z');
        } elseif ($case === 'clock-order') {
            $record['received_at'] = now()->utc()->subMinute()->format('Y-m-d\TH:i:s\Z');
        }
        $sha = $this->controlRecord($record, 'ledger-sync');
        $this->expectException(RuntimeException::class);
        app(LifecycleBackupAssessment::class)->synchronization($record['sync_id'], $case === 'checksum' ? str_repeat('b', 64) : $sha, $ledgerSha, $record['source_url'], 'testing');
    }

    public function test_backup_assessment_commands_refuse_apply_and_preserve_source_records(): void
    {
        [$record, $ledgerSha] = $this->backupInventoryFixture();
        $sha = $this->controlRecord($record, 'backup-inventory');
        $options = ['action' => 'backup-assess', '--operator' => 'ops-01', '--inventory' => $record['inventory_id'],
            '--inventory-sha256' => $sha, '--ledger-sha256' => $ledgerSha, '--source-url' => $record['source_url'], '--source-environment' => $record['environment']];
        $this->artisan('famie:lifecycle-operator', [...$options, '--apply' => true])->assertFailed();
        $this->artisan('famie:lifecycle-operator', $options)->expectsOutputToContain('"ledger_expiry_authorized":false')->assertFailed();
        $this->assertSame($sha, hash_file('sha256', $this->root.'/keys/'.$record['inventory_id'].'.backup-inventory.json'));
        $this->assertSame(0, app(LifecycleAudit::class)->prune()['expired']);
    }

    public function test_backup_inventory_reports_holds_and_unresolved_intents_as_retention_blockers(): void
    {
        $this->freezeTime();
        [$record, $ledgerSha] = $this->backupInventoryFixture();
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        app(LifecycleHold::class)->change($operation->id, 'case-01', now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z'), 'ops-01', $ledgerSha, apply: true);
        $another = $operation->replicate();
        $another->id = (string) Str::uuid();
        $another->save();
        app(LifecycleLedger::class)->append($another, 'intent', ['vault_sha256' => str_repeat('b', 64), 'subject_ids' => [$another->actor_user_id]]);
        $ledgerSha = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $sha = $this->controlRecord($record, 'backup-inventory');
        $result = app(LifecycleBackupAssessment::class)->inventory($record['inventory_id'], $sha, $ledgerSha, $record['source_url'], $record['environment']);
        $this->assertContains('active_hold', $result['blockers']);
        $this->assertContains('unresolved_intent', $result['blockers']);
        $this->assertFalse($result['ledger_expiry_authorized']);
    }

    public function test_sync_check_command_only_confirms_the_captured_snapshot_and_audits_the_receipt_id(): void
    {
        $this->freezeTime();
        [$inventory, $ledgerSha] = $this->backupInventoryFixture();
        $record = ['format_version' => 1, 'sync_id' => (string) Str::uuid(), 'source_url' => $inventory['source_url'],
            'environment' => 'testing', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'received_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'ledger_sha256' => $ledgerSha];
        $sha = $this->controlRecord($record, 'ledger-sync');
        $options = ['action' => 'ledger-sync-check', '--operator' => 'ops-01', '--sync' => $record['sync_id'], '--sync-sha256' => $sha,
            '--ledger-sha256' => $ledgerSha, '--source-url' => $record['source_url'], '--source-environment' => 'testing'];
        $this->artisan('famie:lifecycle-operator', $options)->expectsOutputToContain('"remote_current_state_verified":false')->assertSuccessful();
        $audit = File::get($this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl');
        $this->assertStringContainsString($record['sync_id'], $audit);
        $this->assertSame(0, app(LifecycleAudit::class)->prune()['expired']);
        $this->artisan('famie:lifecycle-operator', [...$options, '--apply' => true])->assertFailed();
    }

    public function test_cleanup_progress_is_recorded_after_content_commit_and_retried_after_ledger_failure(): void
    {
        [$inventory, $ledgerSha] = $this->backupInventoryFixture();
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        $realLedger = app(LifecycleLedger::class);
        $brokenLedger = Mockery::mock(LifecycleLedger::class);
        $brokenLedger->shouldReceive('append')->withArgs(fn ($op, $event, $context) => $op->id === $operation->id && $event === 'cleanup.progress' && $context['cleanup_state'] === 'complete')
            ->once()->andThrow(new RuntimeException('Ledger unavailable'));
        $cleanup = new AccountLifecycleCleanup(app(ActivityImageDeletionService::class), $brokenLedger);
        try {
            $cleanup->process($operation);
            $this->fail('Unrecorded progress accepted.');
        } catch (RuntimeException) {
            $this->assertSame('complete', $operation->fresh()->cleanup_state);
            $this->assertSame('ledger_cleanup_pending', $operation->fresh()->last_error_code);
        }
        $this->assertNull(app(LifecycleLedgerInspector::class)->inspect($ledgerSha, $operation->id)['cleanup']);
        $this->artisan('famie:cleanup-account-lifecycle')->assertSuccessful();
        $sha = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $this->assertSame('complete', app(LifecycleLedgerInspector::class)->inspect($sha, $operation->id)['cleanup']['state']);
        $this->assertNull($operation->fresh()->last_error_code);
        $inventorySha = $this->controlRecord($inventory, 'backup-inventory');
        $assessment = app(LifecycleBackupAssessment::class)->inventory($inventory['inventory_id'], $inventorySha, $sha, $inventory['source_url'], $inventory['environment']);
        $this->assertTrue($assessment['cleanup_verified']);
        $this->assertFalse($assessment['ledger_expiry_authorized']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_cleanup_ledger_rejects_regressing_cursors_or_completion(bool $completion): void
    {
        $this->backupInventoryFixture();
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        $operation->forceFill(['cleanup_state' => $completion ? 'complete' : 'processing', 'content_cursor' => 10]);
        app(LifecycleLedger::class)->append($operation, 'cleanup.progress', ['cleanup_state' => $operation->cleanup_state, 'content_cursor' => 10]);
        $operation->forceFill(['cleanup_state' => 'processing', 'content_cursor' => $completion ? 10 : 9]);
        app(LifecycleLedger::class)->append($operation, 'cleanup.progress', ['cleanup_state' => 'processing', 'content_cursor' => $operation->content_cursor]);
        $this->expectException(RuntimeException::class);
        app(LifecycleLedgerInspector::class)->inspect(hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
    }

    public function test_exhausted_cleanup_budget_preserves_pending_operations_and_reports_deferral(): void
    {
        [$operation] = $this->operation();
        $operation->forceFill(['state' => 'prepared', 'prepared_expires_at' => now()->subMinute()])->save();
        $budget = Mockery::mock(LifecycleCleanupBudget::class);
        $budget->shouldReceive('start')->once();
        $budget->shouldReceive('available')->andReturn(false);
        $this->app->instance(LifecycleCleanupBudget::class, $budget);
        $this->artisan('famie:cleanup-account-lifecycle')->assertFailed();
        $this->assertSame('prepared', $operation->fresh()->state);
        $this->assertDatabaseHas('account_lifecycle_credentials', ['operation_id' => $operation->id]);
        $state = app(OperationState::class)->read('lifecycle-cleanup');
        $this->assertTrue($state['budget_exhausted']);
        $this->assertSame('failure', $state['outcome']);
    }

    public function test_image_retry_does_not_delete_files_after_the_budget_is_exhausted(): void
    {
        Storage::fake('local');
        [$operation] = $this->operation();
        GroupMember::factory()->create(['group_id' => $operation->group_id, 'user_id' => $operation->actor_user_id]);
        $category = Category::query()->create(['group_id' => $operation->group_id, 'name' => 'Test category', 'color_code' => '#10B981', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $operation->group_id, 'user_id' => $operation->actor_user_id,
            'category_id' => $category->id, 'activity_date' => now()->toDateString(), 'content' => 'Test']);
        $image = ActivityImage::query()->create(['group_id' => $operation->group_id, 'activity_log_id' => $log->id,
            'disk' => 'local', 'path' => 'activity-images/budget.webp', 'bytes' => 4, 'width' => 1, 'height' => 1, 'mime' => 'image/webp', 'position' => 0]);
        Storage::disk('local')->put($image->path, 'test');
        $images = app(ActivityImageDeletionService::class);
        $images->retire($image, $operation->id, false);
        $budget = Mockery::mock(LifecycleCleanupBudget::class);
        $budget->shouldReceive('available')->andReturn(false);
        $images->processSafely(100, $budget);
        Storage::disk('local')->assertExists($image->path);
        $this->assertDatabaseHas('activity_image_deletions', ['activity_image_id' => $image->id]);
    }

    public function test_scheduled_cleanup_reserves_time_for_notification_and_records_failures(): void
    {
        Mail::fake();
        config(['operations.enabled' => true, 'famie.lifecycle_monitor_enabled' => true]);
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $this->artisan('famie:run-lifecycle-cleanup')->assertFailed();
        Process::assertRan(fn ($process) => $process->timeout === 120 && $process->command === [PHP_BINARY, 'artisan', 'famie:cleanup-account-lifecycle', '--no-interaction']);
        Process::assertRan(fn ($process) => $process->timeout === 15 && $process->command[2] === 'famie:run-lifecycle-cleanup');
        $this->assertSame('failure', app(OperationState::class)->read('lifecycle-cleanup-job')['outcome']);
        $this->assertSame('critical', app(InternalMonitor::class)->check()['lifecycle-cleanup']);
    }

    public function test_cleanup_monitor_reports_missing_success_staleness_and_overdue_hold_reviews(): void
    {
        $this->freezeTime();
        config(['famie.lifecycle_monitor_enabled' => true]);
        $monitor = app(InternalMonitor::class);
        $this->assertSame('warning', $monitor->check()['lifecycle-cleanup']);
        foreach (['lifecycle-cleanup', 'lifecycle-cleanup-job'] as $name) {
            app(OperationState::class)->write($name, ['outcome' => 'success', 'finished_at' => now()->timestamp]);
        }
        $this->assertSame('ok', $monitor->check()['lifecycle-cleanup']);
        [$operation] = $this->operation();
        DB::table('account_lifecycle_holds')->insert(['operation_id' => $operation->id, 'scope' => 'all', 'reference' => 'case-01', 'started_at' => now()->subDay(), 'review_at' => now()->subMinute(), 'released_at' => null]);
        $this->assertSame('warning', $monitor->check()['lifecycle-cleanup']);
        $this->assertTrue($operation->hasActiveHold());
        DB::table('account_lifecycle_holds')->delete();
        $this->travel(16)->minutes();
        $this->assertSame('warning', $monitor->check()['lifecycle-cleanup']);
    }

    public function test_immediate_cleanup_notifications_share_incident_state_and_suppress_duplicates(): void
    {
        $this->freezeTime();
        Mail::fake();
        config(['operations.enabled' => true, 'famie.lifecycle_monitor_enabled' => true, 'operations.recipient' => 'operator@example.test', 'operations.mailer' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        Process::preventStrayProcesses();
        $this->fakeLifecycleWorker(1);
        $this->artisan('famie:run-lifecycle-cleanup')->assertFailed();
        $this->artisan('famie:run-lifecycle-cleanup')->assertFailed();
        Mail::assertSentCount(1);
        $monitor = app(OperationState::class)->read('monitor');
        $this->assertTrue($monitor['incidents']['lifecycle-cleanup']['open']);
        $this->assertArrayNotHasKey('checked_at', $monitor);
        app(OperationState::class)->write('lifecycle-cleanup', ['outcome' => 'success', 'finished_at' => now()->timestamp]);
        $this->fakeLifecycleWorker(0);
        $this->artisan('famie:run-lifecycle-cleanup')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->artisan('famie:run-lifecycle-cleanup')->assertSuccessful();
        Mail::assertSentCount(2);
        $this->assertFalse(app(OperationState::class)->read('monitor')['incidents']['lifecycle-cleanup']['open']);
        $record = app(OperationState::class)->read('lifecycle-cleanup-job');
        $this->assertGreaterThanOrEqual(0, $record['worker_duration_ms']);
        $this->assertGreaterThanOrEqual($record['worker_duration_ms'], $record['total_duration_ms']);
    }

    public function test_busy_monitor_lock_does_not_lose_cleanup_failure_or_send_an_uncoordinated_alert(): void
    {
        Mail::fake();
        config(['operations.enabled' => true, 'famie.lifecycle_monitor_enabled' => true, 'operations.recipient' => 'operator@example.test', 'operations.mailer' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        Process::preventStrayProcesses();
        $this->fakeLifecycleWorker(1);
        $state = app(OperationState::class);
        $state->locked('monitor', function (): void {
            $this->artisan('famie:run-lifecycle-cleanup')->assertFailed()->run();
        });
        Mail::assertNothingSent();
        $this->assertSame('failure', $state->read('lifecycle-cleanup-job')['outcome']);
        app(MonitorNotifier::class)->lifecycleRun('critical', (string) Str::uuid(), $state);
        Mail::assertSentCount(1);
    }

    private function fakeLifecycleWorker(int $exitCode, bool $workerThrows = false): void
    {
        Process::fake(function ($process) use ($exitCode, $workerThrows) {
            if ($process->command[2] === 'famie:cleanup-account-lifecycle') {
                if ($workerThrows) {
                    throw new RuntimeException('Synthetic cleanup process timeout.');
                }

                return Process::result(exitCode: $exitCode);
            }
            $id = substr($process->command[3], strlen('--notify-run-id='));

            return Process::result(exitCode: Artisan::call('famie:run-lifecycle-cleanup', ['--notify-run-id' => $id]));
        });
    }

    public function test_worker_exception_still_attempts_the_bounded_failure_notification(): void
    {
        Mail::fake();
        config(['operations.enabled' => true, 'famie.lifecycle_monitor_enabled' => true, 'operations.recipient' => 'operator@example.test', 'operations.mailer' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        Process::preventStrayProcesses();
        $this->fakeLifecycleWorker(1, workerThrows: true);
        $this->artisan('famie:run-lifecycle-cleanup')->assertFailed();
        $record = app(OperationState::class)->read('lifecycle-cleanup-job');
        $this->assertSame('failure', $record['outcome']);
        $this->assertSame('success', $record['notification_outcome']);
        Process::assertRan(fn ($process) => $process->timeout === 15 && $process->command[2] === 'famie:run-lifecycle-cleanup');
        Mail::assertSentCount(1);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_cleanup_notification_failure_is_recorded_without_repeating_the_worker(bool $timeout): void
    {
        Mail::fake();
        config(['operations.enabled' => true, 'famie.lifecycle_monitor_enabled' => true]);
        Process::preventStrayProcesses();
        Process::fake(function ($process) use ($timeout) {
            if ($process->command[2] === 'famie:cleanup-account-lifecycle') {
                return Process::result(exitCode: 0);
            }
            if ($timeout) {
                throw new RuntimeException('Synthetic notification process timeout.');
            }

            return Process::result(exitCode: 1);
        });
        $this->artisan('famie:run-lifecycle-cleanup')->assertFailed();
        $record = app(OperationState::class)->read('lifecycle-cleanup-job');
        $this->assertSame('success', $record['outcome']);
        $this->assertSame('failure', $record['notification_outcome']);
        $this->assertArrayHasKey('total_duration_ms', $record);
        $this->assertSame('critical', app(InternalMonitor::class)->lifecycleCleanup());
        Process::assertRanTimes(fn ($process) => $process->command[2] === 'famie:cleanup-account-lifecycle', 1);
        Mail::assertNothingSent();
    }

    public function test_notification_phase_rejects_an_unrelated_run_without_starting_a_worker(): void
    {
        Mail::fake();
        config(['operations.enabled' => true, 'famie.lifecycle_monitor_enabled' => true]);
        Process::preventStrayProcesses();
        Process::fake();
        $this->artisan('famie:run-lifecycle-cleanup', ['--notify-run-id' => (string) Str::uuid()])->assertFailed();
        Process::assertNothingRan();
        Mail::assertNothingSent();
    }

    public function test_unfinished_notification_is_detected_after_a_minute_without_changing_the_record(): void
    {
        $this->freezeTime();
        $state = app(OperationState::class);
        $record = ['run_id' => (string) Str::uuid(), 'outcome' => 'success', 'finished_at' => now()->timestamp, 'notification_outcome' => 'pending'];
        $state->write('lifecycle-cleanup', ['outcome' => 'success', 'finished_at' => now()->timestamp]);
        $state->write('lifecycle-cleanup-job', $record);
        $this->assertSame('ok', app(InternalMonitor::class)->lifecycleCleanup());
        $this->travel(61)->seconds();
        $this->assertSame('critical', app(InternalMonitor::class)->lifecycleCleanup());
        $this->assertSame($record, $state->read('lifecycle-cleanup-job'));
    }

    public function test_notification_transport_failure_is_not_reported_as_a_successful_phase(): void
    {
        Mail::fake();
        config(['operations.enabled' => true, 'famie.lifecycle_monitor_enabled' => true, 'operations.recipient' => 'invalid']);
        $id = (string) Str::uuid();
        $state = app(OperationState::class);
        $state->write('lifecycle-cleanup-job', ['run_id' => $id, 'outcome' => 'failure', 'notification_outcome' => 'pending']);
        $this->artisan('famie:run-lifecycle-cleanup', ['--notify-run-id' => $id])->assertFailed();
        $this->assertTrue($state->read('monitor')['incidents']['lifecycle-cleanup']['notification_failed']);
        Mail::assertNothingSent();
    }

    public function test_identity_recording_defaults_to_read_only_and_appends_only_once(): void
    {
        [$record] = $this->restoreFixture();
        $operation = $this->authoritativeIdentitySource($record);
        $path = $this->root.'/ledger/account-lifecycle.jsonl';
        $before = File::get($path);
        $audit = File::get($this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl');
        $service = app(LifecycleIdentityRecorder::class);
        $plan = $service->record($operation->id, $record['ledger_sha256'], 'ops-01');
        $this->artisan('famie:lifecycle-operator', ['action' => 'identity-record', '--receipt' => $operation->id,
            '--ledger-sha256' => $record['ledger_sha256'], '--operator' => 'ops-01'])->assertSuccessful();
        $this->assertFalse($plan['appended']);
        $this->assertFalse($plan['identity_present']);
        $this->assertSame($before, File::get($path));
        $this->assertSame($audit, File::get($this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl'));
        $this->assertTrue($service->record($operation->id, $record['ledger_sha256'], 'ops-01', true)['appended']);
        $sha = hash_file('sha256', $path);
        $this->assertFalse($service->record($operation->id, $sha, 'ops-01', true)['appended']);
        $this->assertSame($sha, hash_file('sha256', $path));
        $identity = app(LifecycleLedgerInspector::class)->inspect($sha, $operation->id)['operation']['recovery_identity'];
        $this->assertSame(['actor_user_id' => $operation->actor_user_id, 'subjects' => [[
            'user_id' => $operation->actor_user_id, 'membership_id' => $operation->subjects()->firstOrFail()->membership_id,
        ]]], $identity);
        $this->assertStringNotContainsString('private-person', File::get($path));
        $this->assertSame($operation->getAttributes(), $operation->fresh()->getAttributes());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_restore_reconstructs_missing_receipt_or_subjects_only_from_recorded_identity(bool $subjectsOnly): void
    {
        [$record] = $this->restoreFixture();
        $operation = $this->authoritativeIdentitySource($record);
        $deadline = $operation->retention_until->toIso8601String();
        app(LifecycleIdentityRecorder::class)->record($operation->id, $record['ledger_sha256'], 'ops-01', true);
        $record['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $sha = $this->controlRecord($record, 'restore-receipt');
        if ($subjectsOnly) {
            $operation->subjects()->delete();
            $operation->forceFill(['state' => 'prepared', 'committed_at' => null, 'retention_until' => null])->save();
        } else {
            $operation->delete();
        }
        $service = app(LifecycleRestoreReapply::class);
        $plan = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01');
        $this->assertFalse($plan['applied']);
        $this->assertDatabaseCount('account_lifecycle_operations', $subjectsOnly ? 1 : 0);
        $this->assertDatabaseCount('account_lifecycle_subjects', 0);
        $result = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame('complete', $result['cleanup_state']);
        $this->assertFalse($result['reopen_allowed']);
        $restored = AccountLifecycleOperation::query()->findOrFail($operation->id);
        $this->assertSame($operation->actor_user_id, $restored->actor_user_id);
        $this->assertSame($deadline, $restored->retention_until->toIso8601String());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('group_members', 1);
        $this->assertDatabaseCount('account_lifecycle_subjects', 1);
        $this->assertDatabaseCount('account_lifecycle_credentials', $subjectsOnly ? 1 : 0);
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        $this->assertSame([], app(LifecycleRestoreCheck::class)->check($record['ledger_sha256'])['findings']);
    }

    #[TestWith(['legacy'])]
    #[TestWith(['actor'])]
    #[TestWith(['membership'])]
    public function test_missing_receipt_is_not_guessed_when_identity_evidence_disagrees(string $case): void
    {
        [$record] = $this->restoreFixture();
        $operation = $this->authoritativeIdentitySource($record);
        if ($case !== 'legacy') {
            app(LifecycleIdentityRecorder::class)->record($operation->id, $record['ledger_sha256'], 'ops-01', true);
            $record['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        }
        $sha = $this->controlRecord($record, 'restore-receipt');
        $operation->delete();
        if ($case === 'actor') {
            Group::query()->whereKey($operation->group_id)->update(['representative_user_id' => null]);
        } elseif ($case === 'membership') {
            GroupMember::query()->firstOrFail()->delete();
            DB::table('group_members')->delete();
        }
        try {
            app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('Unproven receipt must not be recreated.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('account_lifecycle_operations', 0);
            $this->assertDatabaseCount('account_lifecycle_subjects', 0);
            $this->assertNull(User::query()->firstOrFail()->deleted_at);
            $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        }
    }

    public function test_identity_recording_refuses_reconstructed_source_records(): void
    {
        [$record] = $this->restoreFixture();
        $operation = $this->authoritativeIdentitySource($record);
        $operation->forceFill(['last_error_code' => 'restore_reapply_complete:'.str_repeat('a', 64)])->save();
        $this->expectException(RuntimeException::class);
        app(LifecycleIdentityRecorder::class)->record($operation->id, $record['ledger_sha256'], 'ops-01', true);
    }

    public function test_restore_does_not_overwrite_existing_conflicting_subject_mapping(): void
    {
        [$record] = $this->restoreFixture();
        $operation = $this->authoritativeIdentitySource($record);
        app(LifecycleIdentityRecorder::class)->record($operation->id, $record['ledger_sha256'], 'ops-01', true);
        $record['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $sha = $this->controlRecord($record, 'restore-receipt');
        $other = GroupMember::factory()->create();
        $operation->subjects()->update(['membership_id' => $other->id]);
        try {
            app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('An existing mapping must not be overwritten.');
        } catch (RuntimeException) {
            $this->assertSame($other->id, $operation->subjects()->firstOrFail()->membership_id);
            $this->assertNull(User::query()->firstOrFail()->deleted_at);
            $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        }
    }

    public function test_missing_receipt_with_a_hold_is_reconstructed_before_masking_and_preserves_the_vault(): void
    {
        [$record] = $this->restoreHoldFixture();
        $operation = $this->authoritativeIdentitySource($record);
        app(LifecycleIdentityRecorder::class)->record($operation->id, $record['ledger_sha256'], 'ops-01', true);
        $record['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $sha = $this->controlRecord($record, 'restore-receipt');
        $operation->delete();
        $service = app(LifecycleRestoreReapply::class);
        $holds = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true, true);
        $this->assertTrue($holds['held']);
        $this->assertNull(User::query()->firstOrFail()->deleted_at);
        $this->assertDatabaseCount('account_lifecycle_holds', 1);
        $result = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertTrue($result['held']);
        $this->assertFalse($result['reopen_allowed']);
        $this->assertNotNull(User::withTrashed()->firstOrFail()->deleted_at);
        $this->assertFileExists($this->root.'/vault/'.$record['receipt_id'].'.json');
        $this->assertDatabaseCount('account_lifecycle_credentials', 0);
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
    }

    #[TestWith(['extra'])]
    #[TestWith(['contradiction'])]
    public function test_ledger_rejects_private_fields_or_changed_recovery_mapping(string $case): void
    {
        [$record] = $this->restoreFixture();
        $operation = $this->authoritativeIdentitySource($record);
        app(LifecycleIdentityRecorder::class)->record($operation->id, $record['ledger_sha256'], 'ops-01', true);
        $path = $this->root.'/ledger/account-lifecycle.jsonl';
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $event = json_decode(end($lines), true, 32, JSON_THROW_ON_ERROR);
        if ($case === 'extra') {
            $event['recovery_identity']['subjects'][0]['email'] = 'synthetic@example.test';
        } else {
            $event['recovery_identity']['subjects'][0]['membership_id']++;
        }
        File::append($path, json_encode($event, JSON_THROW_ON_ERROR).PHP_EOL);
        $this->expectException(RuntimeException::class);
        app(LifecycleLedgerInspector::class)->inspect(hash_file('sha256', $path), $operation->id);
    }

    /** @param array<string,mixed> $record */
    private function authoritativeIdentitySource(array $record): AccountLifecycleOperation
    {
        $operation = AccountLifecycleOperation::query()->findOrFail($record['receipt_id']);
        $evidence = app(LifecycleLedgerInspector::class)->inspect($record['ledger_sha256'], $operation->id)['operation'];
        Group::query()->whereKey($operation->group_id)->update(['representative_user_id' => $operation->actor_user_id]);
        $operation->forceFill(['state' => 'committed', 'committed_at' => $evidence['committed_at'],
            'retention_until' => CarbonImmutable::parse($evidence['committed_at'])->addDays(30), 'last_error_code' => null])->save();

        return $operation;
    }

    public function test_orphan_images_plan_is_read_only_then_erases_only_verified_copies_idempotently(): void
    {
        [$record, $sha, $image] = $this->orphanImageFixture();
        Storage::disk('local')->put('activity-images/unknown-copy.webp', 'keep');
        $auditPath = $this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl';
        $auditSha = hash_file('sha256', $auditPath);
        $service = app(LifecycleOrphanImages::class);
        $plan = $service->erase($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01');
        $this->assertSame(1, $plan['verified_orphans']);
        $this->assertSame(0, $plan['deleted']);
        $this->assertFalse($plan['applied']);
        $this->assertSame($auditSha, hash_file('sha256', $auditPath));
        Storage::disk('local')->assertExists($image->path);
        $result = $service->erase($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame(1, $result['deleted']);
        $this->assertFalse($result['database_changed']);
        $this->assertFalse($result['reopen_allowed']);
        Storage::disk('local')->assertMissing($image->path);
        $this->assertSame('keep', Storage::disk('local')->get('activity-images/unknown-copy.webp'));
        $again = $service->erase($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame(0, $again['deleted']);
        $this->assertSame(1, $again['already_absent']);
        $this->assertDatabaseCount('activity_images', 0);
        $this->assertDatabaseCount('activity_image_deletions', 0);
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        $this->assertSame(0, app(LifecycleAudit::class)->prune()['expired']);
    }

    public function test_held_orphan_images_are_preserved_and_cli_does_not_claim_erasure(): void
    {
        [$record, $sha, $image] = $this->orphanImageFixture(true);
        $options = ['action' => 'restore-orphan-images', '--operator' => 'ops-01', '--restore' => $record['restore_id'], '--receipt' => $record['receipt_id'],
            '--restore-sha256' => $sha, '--ledger-sha256' => $record['ledger_sha256'], '--source-url' => $record['source_url'], '--source-environment' => $record['environment']];
        $this->artisan('famie:lifecycle-operator', $options)->expectsOutputToContain('"held":true')->assertSuccessful();
        $this->artisan('famie:lifecycle-operator', [...$options, '--apply' => true])->expectsOutputToContain('"deleted":0')->assertFailed();
        Storage::disk('local')->assertExists($image->path);
        $this->assertDatabaseHas('account_lifecycle_holds', ['operation_id' => $record['receipt_id'], 'released_at' => null]);
    }

    #[TestWith(['manifest'])]
    #[TestWith(['fingerprint'])]
    #[TestWith(['hardlink'])]
    #[TestWith(['legacy'])]
    #[TestWith(['hold'])]
    #[TestWith(['unretired'])]
    #[TestWith(['disabled'])]
    public function test_orphan_images_fail_closed_when_evidence_or_protection_is_unsafe(string $case): void
    {
        [$record, $sha, $image] = $this->orphanImageFixture();
        if ($case === 'manifest') {
            File::append(app(LifecycleOperatorFiles::class)->imageEvidencePath($record['receipt_id']), 'tampered');
        } elseif ($case === 'fingerprint') {
            Storage::disk('local')->put($image->path, 'swap');
        } elseif ($case === 'hardlink') {
            $this->assertTrue(link(Storage::disk('local')->path($image->path), $this->root.'/linked-orphan'));
        } elseif ($case === 'hold') {
            DB::table('account_lifecycle_holds')->insert(['operation_id' => $record['receipt_id'], 'scope' => 'all', 'reference' => 'unreconciled',
                'started_at' => now(), 'review_at' => now()->addDay()]);
        } elseif ($case === 'unretired') {
            AccountLifecycleOperation::query()->whereKey($record['receipt_id'])->update(['last_error_code' => null]);
        } elseif ($case === 'disabled') {
            config(['famie.lifecycle_restore_test_enabled' => false]);
        } else {
            $path = $this->root.'/ledger/account-lifecycle.jsonl';
            $lines = array_map(fn ($line) => json_decode($line, true, 32, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
            unset($lines[0]['image_manifest_sha256']);
            File::put($path, implode(PHP_EOL, array_map(fn ($line) => json_encode($line, JSON_THROW_ON_ERROR), $lines)).PHP_EOL);
            $record['ledger_sha256'] = hash_file('sha256', $path);
            $sha = $this->controlRecord($record, 'restore-receipt');
            AccountLifecycleOperation::query()->whereKey($record['receipt_id'])->update(['last_error_code' => 'restore_reapply_complete:'.$sha]);
        }
        try {
            app(LifecycleOrphanImages::class)->erase($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('Unsafe orphan removal succeeded.');
        } catch (RuntimeException) {
            Storage::disk('local')->assertExists($image->path);
            $this->assertDatabaseCount('activity_images', 0);
        }
    }

    public function test_orphan_images_preserve_a_file_referenced_by_another_family(): void
    {
        [$record, $sha, $image] = $this->orphanImageFixture();
        $foreign = GroupMember::factory()->create();
        $category = Category::query()->create(['group_id' => $foreign->group_id, 'name' => 'Foreign owner', 'color_code' => '#10B981', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $foreign->group_id, 'user_id' => $foreign->user_id, 'category_id' => $category->id,
            'activity_date' => now()->toDateString(), 'content' => 'Foreign original']);
        ActivityImage::query()->create(['group_id' => $foreign->group_id, 'activity_log_id' => $log->id, 'disk' => 'local', 'path' => $image->path,
            'bytes' => 4, 'width' => 1, 'height' => 1, 'mime' => 'image/webp', 'position' => 0]);
        $result = app(LifecycleOrphanImages::class)->erase($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame(1, $result['referenced']);
        $this->assertSame(0, $result['deleted']);
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => 'Foreign original']);
        Storage::disk('local')->assertExists($image->path);
    }

    public function test_orphan_images_are_not_removed_when_initial_audit_fails(): void
    {
        [$record, $sha, $image] = $this->orphanImageFixture();
        $audit = Mockery::mock(LifecycleAudit::class);
        $audit->shouldReceive('record')->with('restore.orphans', 'started', 'ops-01', $record['receipt_id'], null, ['control_id' => $record['restore_id']])
            ->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(LifecycleAudit::class, $audit);
        try {
            app(LifecycleOrphanImages::class)->erase($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('Unaudited orphan removal succeeded.');
        } catch (RuntimeException) {
            Storage::disk('local')->assertExists($image->path);
        }
    }

    public function test_source_image_evidence_is_immutable_and_contains_no_account_contact_information(): void
    {
        [$record, $image] = $this->restoreImageFixture();
        $operation = AccountLifecycleOperation::query()->findOrFail($record['receipt_id']);
        $service = app(LifecycleImageEvidence::class);
        $sha = $service->capture($operation);
        $this->assertSame($sha, $service->capture($operation));
        $path = app(LifecycleOperatorFiles::class)->imageEvidencePath($operation->id);
        $contents = File::get($path);
        $this->assertStringNotContainsString('private-person', $contents);
        $this->assertStringNotContainsString('example.test', $contents);
        Storage::disk('local')->put($image->path, 'swap');
        try {
            $service->capture($operation);
            $this->fail('Existing evidence was overwritten.');
        } catch (RuntimeException) {
            $this->assertSame($sha, hash_file('sha256', $path));
            $this->assertDatabaseHas('activity_images', ['id' => $image->id, 'retired_at' => null]);
        }
    }

    #[TestWith(['size'])]
    #[TestWith(['disk'])]
    #[TestWith(['hardlink'])]
    public function test_source_image_evidence_refuses_ambiguous_targets_without_retiring_records(string $case): void
    {
        [$record, $image] = $this->restoreImageFixture();
        if ($case === 'size') {
            Storage::disk('local')->put($image->path, 'larger');
        } elseif ($case === 'disk') {
            $image->forceFill(['disk' => 'unconfigured'])->save();
        } else {
            $this->assertTrue(link(Storage::disk('local')->path($image->path), $this->root.'/linked-source-image'));
        }
        try {
            app(LifecycleImageEvidence::class)->capture(AccountLifecycleOperation::query()->findOrFail($record['receipt_id']));
            $this->fail('Ambiguous source evidence was saved.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist(app(LifecycleOperatorFiles::class)->imageEvidencePath($record['receipt_id']));
            $this->assertDatabaseHas('activity_images', ['id' => $image->id, 'retired_at' => null]);
        }
    }

    public function test_orphan_images_without_a_source_fingerprint_are_preserved(): void
    {
        [$record, $sha, $image] = $this->orphanImageFixture(missingFingerprint: true);
        try {
            app(LifecycleOrphanImages::class)->erase($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('A resurrected copy without a source fingerprint was removed.');
        } catch (RuntimeException) {
            Storage::disk('local')->assertExists($image->path);
        }
    }

    public function test_orphan_images_report_failure_after_partial_file_removal_and_allow_safe_retry(): void
    {
        [$record, $sha, $image] = $this->orphanImageFixture(twoImages: true);
        $files = app(LifecycleOperatorFiles::class);
        $images = Mockery::mock(LifecycleImageEvidence::class, [$files, app(LifecycleCleanupBudget::class)])->makePartial();
        $images->shouldReceive('fingerprint')->with('activity-images/second-verified.webp', hash('sha256', 'test'), true)
            ->once()->andThrow(new RuntimeException('Synthetic removal failure'));
        $this->app->instance(LifecycleImageEvidence::class, $images);
        try {
            app(LifecycleOrphanImages::class)->erase($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('Partial removal must not report success.');
        } catch (RuntimeException) {
            Storage::disk('local')->assertMissing($image->path);
            Storage::disk('local')->assertExists('activity-images/second-verified.webp');
        }
        $this->app->instance(LifecycleImageEvidence::class, new LifecycleImageEvidence($files, app(LifecycleCleanupBudget::class)));
        $result = app(LifecycleOrphanImages::class)->erase($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame(1, $result['already_absent']);
        $this->assertSame(1, $result['deleted']);
        $this->assertFalse($result['reopen_allowed']);
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
    }

    /** @return array{array<string,mixed>,string,ActivityImage} */
    private function orphanImageFixture(bool $held = false, bool $missingFingerprint = false, bool $twoImages = false): array
    {
        [$record, $image] = $this->restoreImageFixture();
        $operation = AccountLifecycleOperation::query()->findOrFail($record['receipt_id']);
        $source = app(LifecycleLedgerInspector::class)->inspect($record['ledger_sha256'], $operation->id)['operation'];
        if ($missingFingerprint) {
            Storage::disk('local')->delete($image->path);
        }
        if ($twoImages) {
            $second = $image->replicate()->forceFill(['path' => 'activity-images/second-verified.webp', 'position' => 1]);
            $second->save();
            Storage::disk('local')->put($second->path, 'test');
        }
        $checksum = app(LifecycleImageEvidence::class)->capture($operation);
        if ($missingFingerprint) {
            Storage::disk('local')->put($image->path, 'test');
        }
        $this->assertNotNull($checksum);
        File::delete($this->root.'/ledger/account-lifecycle.jsonl');
        $operation->forceFill(['state' => 'committed', 'committed_at' => $source['committed_at'], 'retention_until' => CarbonImmutable::parse($source['committed_at'])->addDays(30)])->save();
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => $source['vault_sha256'], 'subject_ids' => $source['subject_ids'], 'image_manifest_sha256' => $checksum]);
        $ledger->append($operation, 'committed');
        if ($held) {
            app(LifecycleHold::class)->change($operation->id, 'image-preservation', now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z'), 'ops-01', hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'), apply: true);
        }
        $operation->forceFill(['state' => 'prepared', 'committed_at' => null, 'retention_until' => null])->save();
        ActivityImage::query()->where('group_id', $image->group_id)->delete();
        $record['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $sha = $this->controlRecord($record, 'restore-receipt');
        app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);

        return [$record, $sha, $image];
    }

    public function test_restore_images_reports_sizes_without_writes_or_disclosing_paths(): void
    {
        [$record, $image] = $this->restoreImageFixture();
        $before = $image->fresh()->getAttributes();
        $auditPath = $this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl';
        $audit = File::get($auditPath);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $result = app(LifecycleRestoreImages::class)->check($record['ledger_sha256']);
        $this->assertSame([], $result['findings']);
        $this->assertSame(4, $result['recorded_bytes']);
        $this->assertSame(4, $result['physical_bytes']);
        $this->assertSame(0, $result['queued_recorded_bytes']);
        $this->assertSame(1, $result['physical_files']);
        $this->assertFalse($result['database_changed']);
        $this->assertFalse($result['files_changed']);
        $this->assertFalse($result['reopen_allowed']);
        $this->assertStringNotContainsString($image->path, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/\A\s*(?:insert|update|delete|create|drop|alter|replace)\b/i', $sql))));
        $this->artisan('famie:lifecycle-operator', ['action' => 'restore-images', '--operator' => 'ops-01', '--ledger-sha256' => $record['ledger_sha256']])
            ->expectsOutputToContain('"assessment_only":true')->assertSuccessful();
        $this->artisan('famie:lifecycle-operator', ['action' => 'restore-images', '--operator' => 'ops-01', '--ledger-sha256' => $record['ledger_sha256'], '--apply' => true])->assertFailed();
        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame($audit, File::get($auditPath));
        $this->assertSame('test', Storage::disk('local')->get($image->path));
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
    }

    public function test_restore_images_does_not_attribute_or_erase_an_orphaned_copy(): void
    {
        [$record] = $this->restoreImageFixture();
        Storage::disk('local')->put('activity-images/unknown/nested.webp', 'orphan');
        $result = app(LifecycleRestoreImages::class)->check($record['ledger_sha256']);
        $this->assertSame(['orphaned_image_file' => 1], $result['findings']);
        $this->assertSame(1, $result['orphaned_files']);
        $this->assertSame(6, $result['unattributed_bytes']);
        $this->assertSame(10, $result['physical_bytes']);
        $this->assertSame(4, $result['recorded_bytes']);
        $this->artisan('famie:lifecycle-operator', ['action' => 'restore-images', '--operator' => 'ops-01', '--ledger-sha256' => $record['ledger_sha256']])->assertFailed();
        $this->assertDatabaseCount('activity_images', 1);
        $this->assertDatabaseCount('activity_image_deletions', 0);
        $this->assertSame('orphan', Storage::disk('local')->get('activity-images/unknown/nested.webp'));
    }

    #[TestWith(['size'])]
    #[TestWith(['missing'])]
    #[TestWith(['retired'])]
    #[TestWith(['quota'])]
    #[TestWith(['disk'])]
    #[TestWith(['queue'])]
    public function test_restore_images_detects_record_and_capacity_inconsistencies(string $case): void
    {
        [$record, $image] = $this->restoreImageFixture();
        if ($case === 'size') {
            Storage::disk('local')->put($image->path, 'larger synthetic');
            $expected = ['image_size_mismatch' => 1, 'physical_usage_exceeds_recorded' => 1];
        } elseif ($case === 'missing') {
            Storage::disk('local')->delete($image->path);
            $expected = ['missing_image_file' => 1];
        } elseif ($case === 'retired') {
            $image->forceFill(['retired_at' => now()])->save();
            $expected = ['retired_image_without_queue' => 1];
        } elseif ($case === 'quota') {
            Group::query()->whereKey($image->group_id)->update(['image_quota_bytes' => 3]);
            $expected = ['recorded_quota_exceeded' => 1];
        } elseif ($case === 'disk') {
            $image->forceFill(['disk' => 'unconfigured-private-disk'])->save();
            $expected = ['orphaned_image_file' => 1, 'unsupported_image_disk' => 1];
        } else {
            app(ActivityImageDeletionService::class)->retire($image, null, false);
            DB::table('activity_image_deletions')->where('activity_image_id', $image->id)->update(['bytes' => 3]);
            $expected = ['deletion_queue_mismatch' => 1, 'queued_size_mismatch' => 1];
        }
        $result = app(LifecycleRestoreImages::class)->check($record['ledger_sha256']);
        $this->assertSame($expected, $result['findings']);
        $this->assertDatabaseCount('activity_images', 1);
        $this->assertFalse($result['reopen_allowed']);
    }

    public function test_restore_images_counts_retired_images_once_and_cleanup_releases_usage(): void
    {
        [$record, $image] = $this->restoreImageFixture();
        app(ActivityImageDeletionService::class)->retire($image, null, false);
        $service = app(LifecycleRestoreImages::class);
        $before = $service->check($record['ledger_sha256']);
        $this->assertSame([], $before['findings']);
        $this->assertSame(4, $before['recorded_bytes']);
        $this->assertSame(4, $before['queued_recorded_bytes']);
        $this->assertSame(4, $before['physical_bytes']);
        $this->assertSame(4, app(GroupImageUsage::class)->bytes(Group::query()->findOrFail($image->group_id)));
        $this->assertSame(0, app(ActivityImageDeletionService::class)->process());
        $after = $service->check($record['ledger_sha256']);
        $this->assertSame([], $after['findings']);
        $this->assertSame(0, $after['recorded_bytes']);
        $this->assertSame(0, $after['physical_bytes']);
        $this->assertSame(0, $after['queued_recorded_bytes']);
        $this->assertSame(0, app(GroupImageUsage::class)->bytes(Group::query()->findOrFail($image->group_id)));
    }

    public function test_restore_images_reports_a_file_shared_by_different_families_without_assigning_it(): void
    {
        [$record, $image] = $this->restoreImageFixture();
        $foreign = GroupMember::factory()->create();
        $category = Category::query()->create(['group_id' => $foreign->group_id, 'name' => 'Foreign synthetic', 'color_code' => '#10B981', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $foreign->group_id, 'user_id' => $foreign->user_id, 'category_id' => $category->id,
            'activity_date' => now()->toDateString(), 'content' => 'Foreign synthetic content']);
        $duplicate = ActivityImage::query()->create(['group_id' => $foreign->group_id, 'activity_log_id' => $log->id, 'disk' => 'local',
            'path' => $image->path, 'bytes' => 4, 'width' => 1, 'height' => 1, 'mime' => 'image/webp', 'position' => 0]);
        $result = app(LifecycleRestoreImages::class)->check($record['ledger_sha256']);
        $this->assertSame(['duplicate_image_reference' => 1, 'shared_file_ownership' => 1], $result['findings']);
        $this->assertSame(8, $result['recorded_bytes']);
        $this->assertSame(4, $result['physical_bytes']);
        $this->assertSame($foreign->group_id, $duplicate->fresh()->group_id);
        Storage::disk('local')->assertExists($image->path);
    }

    #[TestWith(['disabled'])]
    #[TestWith(['storage'])]
    #[TestWith(['traversal'])]
    #[TestWith(['hardlink'])]
    #[TestWith(['checksum'])]
    #[TestWith(['depth'])]
    public function test_restore_images_refuses_unsafe_or_unverified_assessments(string $case): void
    {
        [$record, $image] = $this->restoreImageFixture();
        if ($case === 'disabled') {
            config(['famie.lifecycle_restore_test_enabled' => false]);
        } elseif ($case === 'storage') {
            config(['filesystems.disks.local.root' => $this->root.'/vault']);
            Storage::forgetDisk('local');
        } elseif ($case === 'traversal') {
            $image->forceFill(['path' => 'activity-images/../vault.json'])->save();
        } elseif ($case === 'hardlink') {
            $this->assertTrue(link(Storage::disk('local')->path($image->path), $this->root.'/linked-image'));
        } elseif ($case === 'depth') {
            Storage::disk('local')->put('activity-images/'.str_repeat('nested/', 10).'file.webp', 'synthetic');
        } else {
            $record['ledger_sha256'] = str_repeat('a', 64);
        }
        $this->expectException(RuntimeException::class);
        app(LifecycleRestoreImages::class)->check($record['ledger_sha256']);
    }

    /** @return array{array<string,mixed>,ActivityImage} */
    private function restoreImageFixture(): array
    {
        [$record] = $this->restoreFixture();
        $operation = AccountLifecycleOperation::query()->findOrFail($record['receipt_id']);
        $category = Category::query()->create(['group_id' => $operation->group_id, 'name' => 'Synthetic images', 'color_code' => '#10B981', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $operation->group_id, 'user_id' => $operation->actor_user_id, 'category_id' => $category->id,
            'activity_date' => now()->toDateString(), 'content' => 'Synthetic image content']);
        $image = ActivityImage::query()->create(['group_id' => $operation->group_id, 'activity_log_id' => $log->id, 'disk' => 'local',
            'path' => 'activity-images/private-synthetic.webp', 'bytes' => 4, 'width' => 1, 'height' => 1, 'mime' => 'image/webp', 'position' => 0]);
        Group::query()->whereKey($operation->group_id)->update(['image_quota_bytes' => 100]);
        Storage::disk('local')->put($image->path, 'test');

        return [$record, $image];
    }

    public function test_restore_reapply_defaults_to_a_read_only_plan_then_masks_and_revokes_access(): void
    {
        [$record, $sha] = $this->restoreFixture();
        $user = User::query()->firstOrFail();
        config(['legal.storage_enabled' => true, 'legal.acceptance_enabled' => false]);
        $other = User::factory()->create();
        foreach ([$user, $other] as $subject) {
            LegalAcceptance::query()->create(['user_id' => $subject->id, 'document_id' => 'terms',
                'document_version' => '0.1.0', 'document_sha256' => str_repeat('a', 64),
                'action' => 'accept', 'locale' => 'ja', 'recorded_at' => now()->utc()]);
        }
        $user->createToken('restored-token');
        DB::table('sessions')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'payload' => 'synthetic', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'synthetic-reset', 'created_at' => now()]);
        $auditPath = $this->root.'/audit/audit-'.now()->utc()->format('Y-m-d').'.jsonl';
        $auditSha = hash_file('sha256', $auditPath);
        $service = app(LifecycleRestoreReapply::class);
        $plan = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01');
        $this->assertFalse($plan['applied']);
        $this->assertDatabaseCount('legal_acceptances', 2);
        $this->assertSame($auditSha, hash_file('sha256', $auditPath));
        $this->assertSame('prepared', AccountLifecycleOperation::query()->firstOrFail()->state);
        $this->assertNull($user->fresh()->deleted_at);
        $result = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame('complete', $result['cleanup_state']);
        $this->assertFalse($result['reopen_allowed']);
        $masked = User::withTrashed()->findOrFail($user->id);
        $this->assertNotNull($masked->deleted_at);
        $this->assertDatabaseMissing('legal_acceptances', ['user_id' => $user->id]);
        $this->assertDatabaseHas('legal_acceptances', ['user_id' => $other->id]);
        $this->assertFalse(Hash::check('password', $masked->password));
        $this->assertNull($masked->email);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        $this->assertSame([], app(LifecycleRestoreCheck::class)->check($record['ledger_sha256'])['findings']);
        $this->assertSame(0, app(LifecycleAudit::class)->prune()['expired']);
    }

    public function test_expired_restore_does_not_restart_retention_or_recreate_inquiry_credentials(): void
    {
        [$record, $sha] = $this->restoreFixture(true);
        $result = app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertTrue($result['expired']);
        $this->assertTrue(AccountLifecycleOperation::query()->firstOrFail()->retention_until->isPast());
        $this->assertDatabaseCount('account_lifecycle_credentials', 0);
        $this->assertFileDoesNotExist($this->root.'/vault/'.$record['receipt_id'].'.json');
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
    }

    #[TestWith(['disabled'])]
    #[TestWith(['production'])]
    #[TestWith(['target'])]
    #[TestWith(['stale'])]
    #[TestWith(['subjects'])]
    #[TestWith(['hold'])]
    #[TestWith(['files'])]
    #[TestWith(['checksum'])]
    #[TestWith(['vault'])]
    public function test_restore_reapply_rejects_unsafe_context_without_masking_accounts(string $case): void
    {
        [$record, $sha] = $this->restoreFixture($case === 'vault');
        $userId = User::query()->firstOrFail()->id;
        if ($case === 'disabled') {
            config(['famie.lifecycle_restore_test_enabled' => false]);
        } elseif ($case === 'production') {
            $this->app['env'] = 'production';
        } elseif ($case === 'target') {
            $record['target']['database'] = 'different-target';
            $sha = $this->controlRecord($record, 'restore-receipt');
        } elseif ($case === 'stale') {
            $this->travel(16)->minutes();
        } elseif ($case === 'subjects') {
            DB::table('account_lifecycle_subjects')->delete();
        } elseif ($case === 'hold') {
            DB::table('account_lifecycle_holds')->insert(['operation_id' => $record['receipt_id'], 'scope' => 'all', 'reference' => 'case-01', 'started_at' => now(), 'review_at' => now()->addDay()]);
        } elseif ($case === 'files') {
            config(['filesystems.disks.local.root' => $this->root.'/vault']);
            Storage::forgetDisk('local');
        } elseif ($case === 'vault') {
            File::put($this->root.'/vault/'.$record['receipt_id'].'.json', 'Unmatched synthetic vault');
        } else {
            $sha = str_repeat('a', 64);
        }
        try {
            app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('Unsafe restoration was applied.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('users', ['id' => $userId, 'deleted_at' => null]);
            $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $record['receipt_id'], 'state' => 'prepared']);
            $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        }
    }

    public function test_restore_reapply_continues_the_cursor_across_multiple_batches(): void
    {
        [$record, $sha] = $this->restoreFixture();
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        $category = Category::query()->create(['group_id' => $operation->group_id, 'name' => 'Synthetic', 'color_code' => '#10B981', 'sort_order' => 1]);
        for ($index = 0; $index < 151; $index++) {
            ActivityLog::query()->create(['group_id' => $operation->group_id, 'user_id' => $operation->actor_user_id, 'category_id' => $category->id,
                'activity_date' => now()->toDateString(), 'content' => 'Synthetic content']);
        }
        $service = app(LifecycleRestoreReapply::class);
        $first = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame('processing', $first['cleanup_state']);
        $this->assertSame(51, ActivityLog::query()->where('content', 'Synthetic content')->count());
        $second = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame('complete', $second['cleanup_state']);
        $this->assertSame(151, $operation->fresh()->content_cursor);
        $this->assertSame(0, ActivityLog::withTrashed()->where('content', 'Synthetic content')->count());
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        $firstLog = ActivityLog::withTrashed()->orderBy('id')->firstOrFail();
        $firstLog->forceFill(['content' => 'Resurrected low-ID content', 'content_erased_at' => null])->save();
        $previousRestore = $record['restore_id'];
        $record['restore_id'] = (string) Str::uuid();
        $this->assertTrue(File::copy($this->root.'/keys/'.$previousRestore.'.restore.dump', $this->root.'/keys/'.$record['restore_id'].'.restore.dump'));
        $sha = $this->controlRecord($record, 'restore-receipt');
        $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame('', $firstLog->fresh()->content);
    }

    public function test_restore_reapply_command_requires_apply_and_keeps_normal_cleanup_from_rewriting_source_evidence(): void
    {
        [$record, $sha] = $this->restoreFixture();
        config(['famie.lifecycle_cleanup_batch' => 1]);
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        $category = Category::query()->create(['group_id' => $operation->group_id, 'name' => 'Synthetic CLI', 'color_code' => '#10B981', 'sort_order' => 1]);
        for ($index = 0; $index < 2; $index++) {
            ActivityLog::query()->create(['group_id' => $operation->group_id, 'user_id' => $operation->actor_user_id, 'category_id' => $category->id,
                'activity_date' => now()->toDateString(), 'content' => 'Synthetic CLI content']);
        }
        $options = ['action' => 'restore-reapply', '--operator' => 'ops-01', '--restore' => $record['restore_id'], '--receipt' => $record['receipt_id'],
            '--restore-sha256' => $sha, '--ledger-sha256' => $record['ledger_sha256'], '--source-url' => $record['source_url'], '--source-environment' => $record['environment']];
        $this->artisan('famie:lifecycle-operator', $options)->expectsOutputToContain('"applied":false')->assertSuccessful();
        $this->assertSame('prepared', AccountLifecycleOperation::query()->firstOrFail()->state);
        $this->artisan('famie:lifecycle-operator', [...$options, '--apply' => true])->expectsOutputToContain('"cleanup_state":"processing"')->assertFailed();
        $cursor = $operation->fresh()->content_cursor;
        $this->artisan('famie:cleanup-account-lifecycle')->assertSuccessful();
        $this->assertSame($cursor, $operation->fresh()->content_cursor);
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        $this->artisan('famie:lifecycle-operator', [...$options, '--apply' => true])->expectsOutputToContain('"cleanup_state":"complete"')->assertSuccessful();
    }

    public function test_restore_reapply_leaves_another_familys_image_queue_untouched(): void
    {
        [$record, $sha] = $this->restoreFixture();
        $foreign = GroupMember::factory()->create();
        $category = Category::query()->create(['group_id' => $foreign->group_id, 'name' => 'Foreign synthetic', 'color_code' => '#10B981', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $foreign->group_id, 'user_id' => $foreign->user_id, 'category_id' => $category->id,
            'activity_date' => now()->toDateString(), 'content' => 'Foreign synthetic content']);
        $image = ActivityImage::query()->create(['group_id' => $foreign->group_id, 'activity_log_id' => $log->id, 'disk' => 'local',
            'path' => 'activity-images/foreign-restore.webp', 'bytes' => 4, 'width' => 1, 'height' => 1, 'mime' => 'image/webp', 'position' => 0]);
        Storage::disk('local')->put($image->path, 'test');
        app(ActivityImageDeletionService::class)->retire($image, null, false);
        app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertDatabaseHas('users', ['id' => $foreign->user_id, 'deleted_at' => null]);
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => 'Foreign synthetic content']);
        $this->assertDatabaseHas('activity_image_deletions', ['activity_image_id' => $image->id]);
        Storage::disk('local')->assertExists($image->path);
    }

    public function test_restore_reapply_refuses_external_hold_evidence_missing_from_the_restored_database(): void
    {
        [$record] = $this->restoreFixture();
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        $evidence = app(LifecycleLedgerInspector::class)->inspect($record['ledger_sha256'], $operation->id)['operation'];
        $operation->forceFill(['state' => 'committed', 'committed_at' => $evidence['committed_at']]);
        app(LifecycleLedger::class)->append($operation, 'hold.started', ['hold_id' => 1, 'reference' => 'hold-01', 'scope' => 'all',
            'review_at' => now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z'), 'operator' => 'ops-01']);
        $record['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $sha = $this->controlRecord($record, 'restore-receipt');
        try {
            app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('External hold ignored.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $record['receipt_id'], 'state' => 'prepared']);
            $this->assertNull(User::query()->firstOrFail()->deleted_at);
            $this->assertDatabaseCount('account_lifecycle_holds', 0);
        }
    }

    public function test_restore_reapply_does_not_modify_accounts_when_the_initial_audit_fails(): void
    {
        [$record, $sha] = $this->restoreFixture();
        $audit = Mockery::mock(LifecycleAudit::class);
        $audit->shouldReceive('record')->with('restore.reapply', 'started', 'ops-01', $record['receipt_id'], null, ['control_id' => $record['restore_id']])
            ->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(LifecycleAudit::class, $audit);
        try {
            app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('Unaudited mutation accepted.');
        } catch (RuntimeException) {
            $this->assertNull(User::query()->firstOrFail()->deleted_at);
            $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $record['receipt_id'], 'state' => 'prepared']);
        }
    }

    public function test_restore_reapply_preserves_expired_vault_when_a_hold_is_added_during_cleanup(): void
    {
        [$record, $sha] = $this->restoreFixture(true);
        $cleanup = Mockery::mock(AccountLifecycleCleanup::class);
        $cleanup->shouldReceive('process')->once()->andReturnUsing(function (AccountLifecycleOperation $operation): void {
            DB::table('account_lifecycle_holds')->insert(['operation_id' => $operation->id, 'scope' => 'all', 'reference' => 'late-hold',
                'started_at' => now(), 'review_at' => now()->addDay()]);
        });
        $this->app->instance(AccountLifecycleCleanup::class, $cleanup);
        try {
            app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('Late hold was ignored.');
        } catch (RuntimeException) {
            $this->assertFileExists($this->root.'/vault/'.$record['receipt_id'].'.json');
            $this->assertNotNull(User::withTrashed()->firstOrFail()->deleted_at);
            $this->assertTrue(AccountLifecycleOperation::query()->firstOrFail()->hasActiveHold());
            $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        }
    }

    public function test_restored_active_hold_preserves_expired_content_images_and_vault_while_revoking_access(): void
    {
        [$record, $sha] = $this->restoreHoldFixture();
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        $category = Category::query()->create(['group_id' => $operation->group_id, 'name' => 'Held category', 'color_code' => '#10B981', 'sort_order' => 1]);
        $log = ActivityLog::query()->create(['group_id' => $operation->group_id, 'user_id' => $operation->actor_user_id, 'category_id' => $category->id,
            'activity_date' => now()->toDateString(), 'content' => 'Held content', 'note' => 'Held note']);
        $image = ActivityImage::query()->create(['group_id' => $operation->group_id, 'activity_log_id' => $log->id, 'disk' => 'local',
            'path' => 'activity-images/held.webp', 'bytes' => 4, 'width' => 1, 'height' => 1, 'mime' => 'image/webp', 'position' => 0]);
        Storage::disk('local')->put($image->path, 'test');
        User::query()->firstOrFail()->createToken('held-restored-token');
        $options = ['action' => 'restore-holds', '--operator' => 'ops-01', '--restore' => $record['restore_id'], '--receipt' => $record['receipt_id'],
            '--restore-sha256' => $sha, '--ledger-sha256' => $record['ledger_sha256'], '--source-url' => $record['source_url'], '--source-environment' => $record['environment']];
        $this->artisan('famie:lifecycle-operator', $options)->expectsOutputToContain('"missing_holds":1')->assertSuccessful();
        $this->assertDatabaseCount('account_lifecycle_holds', 0);
        $this->artisan('famie:lifecycle-operator', [...$options, '--apply' => true])->expectsOutputToContain('"held":true')->assertSuccessful();
        $this->artisan('famie:lifecycle-operator', [...$options, '--apply' => true])->expectsOutputToContain('"missing_holds":0')->assertSuccessful();
        $this->assertDatabaseCount('account_lifecycle_holds', 1);
        $this->artisan('famie:cleanup-account-lifecycle')->assertSuccessful();
        $this->assertSame('prepared', $operation->fresh()->state);
        $result = app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertTrue($result['held']);
        $this->assertSame('processing', $result['cleanup_state']);
        $this->assertNotNull(User::withTrashed()->firstOrFail()->deleted_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue($operation->fresh()->retention_until->isPast());
        $this->assertTrue($operation->hasActiveHold());
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'content' => 'Held content', 'note' => 'Held note']);
        Storage::disk('local')->assertExists($image->path);
        $this->assertFileExists($this->root.'/vault/'.$operation->id.'.json');
        $this->assertDatabaseCount('account_lifecycle_credentials', 1);
        $this->assertSame([], app(LifecycleRestoreCheck::class)->check($record['ledger_sha256'])['findings']);
        $sourceHold = app(LifecycleLedgerInspector::class)->inspect($record['ledger_sha256'], $record['receipt_id'])['holds'][100];
        try {
            app(LifecycleHold::class)->change($record['receipt_id'], $sourceHold['reference'], $sourceHold['review_at'], 'ops-01', $record['ledger_sha256'], release: true, apply: true);
            $this->fail('Normal hold release modified restored source evidence.');
        } catch (RuntimeException) {
            $this->assertTrue($operation->hasActiveHold());
        }
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        $this->assertSame(0, app(LifecycleAudit::class)->prune()['expired']);
    }

    public function test_released_hold_history_can_be_reconstructed_without_restarting_retention(): void
    {
        [$record, $sha] = $this->restoreHoldFixture(true);
        $service = app(LifecycleRestoreReapply::class);
        $result = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true, true);
        $this->assertFalse($result['held']);
        $this->assertNotNull(DB::table('account_lifecycle_holds')->where('id', 100)->value('released_at'));
        $this->artisan('famie:cleanup-account-lifecycle')->assertSuccessful();
        $this->assertSame('prepared', AccountLifecycleOperation::query()->firstOrFail()->state);
        $result = $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
        $this->assertSame('complete', $result['cleanup_state']);
        $this->assertFalse($result['held']);
        $this->assertDatabaseCount('account_lifecycle_credentials', 0);
        $this->assertFileDoesNotExist($this->root.'/vault/'.$record['receipt_id'].'.json');
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_hold_recovery_never_changes_a_conflicting_existing_hold_state(bool $sourceReleased): void
    {
        [$record, $sha] = $this->restoreHoldFixture($sourceReleased);
        $hold = app(LifecycleLedgerInspector::class)->inspect($record['ledger_sha256'], $record['receipt_id'])['holds'][100];
        $localRelease = $sourceReleased ? null : now();
        DB::table('account_lifecycle_holds')->insert(['id' => 100, 'operation_id' => $record['receipt_id'], 'scope' => 'all', 'reference' => $hold['reference'],
            'started_at' => now()->subDays(31), 'review_at' => CarbonImmutable::parse($hold['review_at']), 'released_at' => $localRelease]);
        try {
            app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true, true);
            $this->fail('Conflicting hold state was overwritten.');
        } catch (RuntimeException) {
            $this->assertSame($sourceReleased, DB::table('account_lifecycle_holds')->where('id', 100)->value('released_at') === null);
            $this->assertNull(User::query()->firstOrFail()->deleted_at);
            $this->assertFileExists($this->root.'/vault/'.$record['receipt_id'].'.json');
        }
    }

    public function test_hold_recovery_refuses_an_id_owned_by_another_operation(): void
    {
        [$record, $sha] = $this->restoreHoldFixture();
        $other = AccountLifecycleOperation::query()->firstOrFail()->replicate();
        $other->id = (string) Str::uuid();
        $other->save();
        DB::table('account_lifecycle_holds')->insert(['id' => 100, 'operation_id' => $other->id, 'scope' => 'all', 'reference' => 'other-hold',
            'started_at' => now(), 'review_at' => now()->addDay()]);
        try {
            app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true, true);
            $this->fail('Foreign hold was overwritten.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('account_lifecycle_holds', ['id' => 100, 'operation_id' => $other->id, 'reference' => 'other-hold']);
            $this->assertDatabaseCount('account_lifecycle_holds', 1);
        }
    }

    public function test_held_accounts_are_not_masked_when_identity_vault_is_missing(): void
    {
        [$record, $sha] = $this->restoreHoldFixture();
        $service = app(LifecycleRestoreReapply::class);
        $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true, true);
        File::delete($this->root.'/vault/'.$record['receipt_id'].'.json');
        try {
            $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true);
            $this->fail('Held identifying data was erased without a preserved vault.');
        } catch (RuntimeException) {
            $this->assertNull(User::query()->firstOrFail()->deleted_at);
            $this->assertTrue(AccountLifecycleOperation::query()->firstOrFail()->hasActiveHold());
        }
    }

    public function test_hold_recovery_does_not_insert_rows_when_the_initial_audit_fails(): void
    {
        [$record, $sha] = $this->restoreHoldFixture();
        $audit = Mockery::mock(LifecycleAudit::class);
        $audit->shouldReceive('record')->with('restore.holds', 'started', 'ops-01', $record['receipt_id'], null, ['control_id' => $record['restore_id']])
            ->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(LifecycleAudit::class, $audit);
        try {
            app(LifecycleRestoreReapply::class)->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true, true);
            $this->fail('Unaudited hold recovery accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('account_lifecycle_holds', 0);
            $this->assertSame('prepared', AccountLifecycleOperation::query()->firstOrFail()->state);
        }
    }

    public function test_ledger_rejects_a_hold_release_recorded_before_its_start(): void
    {
        [$record] = $this->restoreHoldFixture();
        $snapshot = app(LifecycleLedgerInspector::class)->inspect($record['ledger_sha256'], $record['receipt_id']);
        $hold = $snapshot['holds'][100];
        $this->travelTo(CarbonImmutable::parse($hold['started_recorded_at'])->subSecond());
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        $operation->forceFill(['state' => 'committed', 'committed_at' => $snapshot['operation']['committed_at']]);
        app(LifecycleLedger::class)->append($operation, 'hold.released', ['hold_id' => 100, 'reference' => $hold['reference'], 'scope' => 'all', 'review_at' => $hold['review_at'], 'operator' => 'ops-01']);
        $this->expectException(RuntimeException::class);
        app(LifecycleLedgerInspector::class)->inspect(hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_hold_recovery_reserves_ids_for_other_source_operations_and_rejects_duplicate_owners(bool $duplicate): void
    {
        [$record] = $this->restoreHoldFixture();
        $member = GroupMember::factory()->create(['role' => 'admin']);
        $member->group->forceFill(['representative_user_id' => $member->user_id])->save();
        $other = AccountLifecycleOperation::query()->create(['id' => (string) Str::uuid(), 'actor_user_id' => $member->user_id,
            'group_id' => $member->group_id, 'kind' => 'group_withdraw', 'state' => 'prepared', 'prepared_expires_at' => now()->addMinutes(15)]);
        $other->forceFill(['state' => 'committed', 'committed_at' => now()]);
        $ledger = app(LifecycleLedger::class);
        $ledger->append($other, 'intent', ['vault_sha256' => str_repeat('b', 64), 'subject_ids' => [$member->user_id]]);
        $ledger->append($other, 'committed');
        $ledger->append($other, 'hold.started', ['hold_id' => $duplicate ? 100 : 200, 'reference' => 'other-source-hold', 'scope' => 'all',
            'review_at' => now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z'), 'operator' => 'ops-01']);
        $record['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $sha = $this->controlRecord($record, 'restore-receipt');
        $service = app(LifecycleRestoreReapply::class);
        if ($duplicate) {
            $this->expectException(RuntimeException::class);
            $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true, true);

            return;
        }
        $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true, true);
        $this->assertDatabaseCount('account_lifecycle_holds', 1);
        $probe = ['operation_id' => $other->id, 'scope' => 'all', 'reference' => 'sequence-probe', 'started_at' => now(), 'review_at' => now()->addDay()];
        $first = DB::table('account_lifecycle_holds')->insertGetId($probe);
        $this->assertGreaterThan(200, $first);
        DB::table('account_lifecycle_holds')->where('id', $first)->delete();
        $service->reapply($record['restore_id'], $record['receipt_id'], $sha, $record['ledger_sha256'], $record['source_url'], $record['environment'], 'ops-01', true, true);
        $second = DB::table('account_lifecycle_holds')->insertGetId($probe);
        $this->assertGreaterThan($first, $second);
        $this->assertSame($record['ledger_sha256'], hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
    }

    /** @return array{array<string,mixed>,string} */
    private function restoreHoldFixture(bool $released = false): array
    {
        [$record] = $this->restoreFixture(true);
        $operation = AccountLifecycleOperation::query()->firstOrFail();
        $evidence = app(LifecycleLedgerInspector::class)->inspect($record['ledger_sha256'], $operation->id)['operation'];
        $restoredTime = now()->copy();
        $this->travelTo(CarbonImmutable::parse($evidence['committed_at'])->addSecond());
        $operation->forceFill(['state' => 'committed', 'committed_at' => $evidence['committed_at']]);
        $context = ['hold_id' => 100, 'reference' => 'restored-hold', 'scope' => 'all',
            'review_at' => now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z'), 'operator' => 'ops-01'];
        app(LifecycleLedger::class)->append($operation, 'hold.started', $context);
        if ($released) {
            $this->travel(1)->seconds();
            app(LifecycleLedger::class)->append($operation, 'hold.released', $context);
        }
        $this->travelTo($restoredTime);
        $record['ledger_sha256'] = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');

        return [$record, $this->controlRecord($record, 'restore-receipt')];
    }

    /** @return array{array<string,mixed>,string} */
    private function restoreFixture(bool $expired = false): array
    {
        [$context] = $this->decryptionFixture();
        Storage::fake('local');
        config(['famie.lifecycle_restore_test_enabled' => true]);
        $operation = AccountLifecycleOperation::query()->findOrFail($context['receipt_id']);
        $member = GroupMember::factory()->create(['group_id' => $operation->group_id, 'user_id' => $operation->actor_user_id]);
        $operation->subjects()->create(['user_id' => $operation->actor_user_id, 'membership_id' => $member->id]);
        $operation->forceFill(['state' => 'prepared', 'committed_at' => null, 'retention_until' => null])->save();
        if ($expired) {
            $this->travel(31)->days();
        }
        $id = (string) Str::uuid();
        $backup = $this->root.'/keys/'.$id.'.restore.dump';
        File::put($backup, 'Synthetic in-memory restore fixture');
        $record = ['format_version' => 1, 'restore_id' => $id, 'receipt_id' => $operation->id, 'source_url' => $context['source_url'],
            'environment' => $context['environment'], 'ledger_sha256' => $context['ledger_sha256'], 'backup_sha256' => hash_file('sha256', $backup),
            'restored_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'target' => ['driver' => 'sqlite', 'host' => null, 'port' => null, 'database' => ':memory:', 'schema' => null]];

        return [$record, $this->controlRecord($record, 'restore-receipt')];
    }

    /** @return array{array<string,mixed>,string} */
    private function backupInventoryFixture(): array
    {
        [$operation] = $this->operation();
        $ledger = app(LifecycleLedger::class);
        $ledger->append($operation, 'intent', ['vault_sha256' => str_repeat('a', 64), 'subject_ids' => [$operation->actor_user_id]]);
        $ledger->append($operation, 'committed');
        $record = ['format_version' => 1, 'inventory_id' => (string) Str::uuid(), 'source_url' => 'https://source.example.test', 'environment' => 'testing',
            'inventoried_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'coverage_complete' => true, 'copies' => [
                ['copy_id' => (string) Str::uuid(), 'medium_id' => 'server-01', 'kind' => 'application', 'contents' => ['database', 'images'],
                    'created_at' => now()->utc()->subDays(2)->format('Y-m-d\TH:i:s\Z'), 'restorable_until' => now()->utc()->addDays(7)->format('Y-m-d\TH:i:s\Z'),
                    'parent_ids' => [], 'expired_verified_at' => null, 'verification_ref' => null],
            ]];

        return [$record, hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl')];
    }

    /** @param array<string,mixed> $record */
    private function controlRecord(array $record, string $kind): string
    {
        $id = $record[match ($kind) {
            'backup-inventory' => 'inventory_id', 'ledger-sync' => 'sync_id', 'restore-receipt' => 'restore_id',
            'backup-catalogue' => 'catalogue_id', 'backup-coverage' => 'coverage_id'
        }];
        $path = $this->root.'/keys/'.$id.'.'.$kind.'.json';
        File::put($path, json_encode($record, JSON_THROW_ON_ERROR));
        chmod($path, 0600);

        return hash_file('sha256', $path);
    }

    /** @return array{AccountLifecycleOperation, string} */
    private function operation(): array
    {
        $user = User::factory()->create(['email' => 'private-person@example.test']);
        $operation = AccountLifecycleOperation::query()->create(['id' => (string) Str::uuid(), 'actor_user_id' => $user->id,
            'group_id' => Group::factory()->create()->id, 'kind' => 'group_withdraw', 'state' => 'committed',
            'cleanup_state' => 'complete', 'prepared_expires_at' => now()->addMinutes(15)]);
        $operation->forceFill(['committed_at' => now(), 'retention_until' => now()->addDays(30)])->save();
        $code = str_repeat('A', 43);
        AccountLifecycleCredential::query()->create(['operation_id' => $operation->id, 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addDays(30)]);

        return [$operation, $code];
    }
}
