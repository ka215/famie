<?php

namespace Tests\Feature;

use App\Models\AccountLifecycleOperation;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\AccountLifecycleCleanup;
use App\Services\AccountLifecycleService;
use App\Services\GroupImageUsage;
use App\Services\LifecycleBackupAssessment;
use App\Services\LifecycleCleanupBudget;
use App\Services\LifecycleImageEvidence;
use App\Services\LifecycleLedgerInspector;
use App\Services\LifecycleOperatorFiles;
use App\Services\LifecycleOrphanImages;
use App\Services\LifecycleRestoreReapply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class LifecycleImageLoadTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        if (getenv('FAMIE_LIFECYCLE_IMAGE_LOAD_TEST') !== '1') {
            $this->markTestSkipped('Requires explicit isolated image load training.');
        }
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->root = sys_get_temp_dir().'/famie-f03-'.bin2hex(random_bytes(8));
        foreach (['ledger', 'vault', 'keys', 'audit', 'runtime/logs'] as $directory) {
            File::makeDirectory($this->root.'/'.$directory, 0700, true);
        }
        $this->app->useStoragePath($this->root.'/runtime');
        config(['famie.lifecycle_ledger_root' => $this->root.'/ledger', 'famie.lifecycle_vault_root' => $this->root.'/vault',
            'famie.lifecycle_operator_root' => $this->root.'/keys', 'famie.lifecycle_audit_root' => $this->root.'/audit',
            'logging.channels.operations.path' => $this->root.'/runtime/logs/operations.log']);
        $this->beforeApplicationDestroyed(function (): void {
            if (! is_link($this->root) && dirname(realpath($this->root)) === realpath(sys_get_temp_dir())
                && preg_match('/\Afamie-f03-[a-f0-9]{16}\z/', basename($this->root))) {
                File::deleteDirectory($this->root);
            }
        });
    }

    #[TestWith([100, 4096])]
    #[TestWith([1000, 262144])]
    #[TestWith([2001, 8192])]
    public function test_image_load_capture_commit_and_bounded_cleanup_preserve_evidence_and_release_usage(int $count, int $bytes): void
    {
        [$group, $actor, $subject, $operation, $code] = $this->fixture($count, $bytes);
        $probe = new class(app(LifecycleOperatorFiles::class), app(LifecycleCleanupBudget::class)) extends LifecycleImageEvidence
        {
            public float $milliseconds = 0;

            public bool $measuring = false;

            public function capture(AccountLifecycleOperation $operation): ?string
            {
                $start = hrtime(true);
                $this->measuring = true;
                try {
                    return parent::capture($operation);
                } finally {
                    $this->milliseconds = (hrtime(true) - $start) / 1000000;
                    $this->measuring = false;
                }
            }
        };
        $queries = 0;
        DB::listen(function ($query) use ($probe, &$queries): void {
            if ($probe->measuring) {
                $queries++;
            }
        });
        $this->app->instance(LifecycleImageEvidence::class, $probe);
        memory_reset_peak_usage();
        $start = hrtime(true);
        $committed = app(AccountLifecycleService::class)->commit($actor, $group, $operation->id, 'password', $code);
        $commitMs = (hrtime(true) - $start) / 1000000;
        $this->assertSame('committed', $committed->state);
        $this->assertGreaterThan(0, $probe->milliseconds);
        $this->assertLessThan(10000, $commitMs, 'Synthetic initial commit exceeded the 10-second target.');
        $this->assertLessThanOrEqual((int) ceil($count / 20) + 5, $queries, 'Evidence reads must grow by pages rather than by individual images.');
        $files = app(LifecycleOperatorFiles::class);
        $evidence = $files->readImageEvidence($operation->id);
        $this->assertCount($count, $evidence['data']['images']);
        $rounds = 0;
        $slowest = 0;
        $maximumRounds = (int) ceil($count / config('famie.lifecycle_cleanup_batch')) + 2;
        while ($committed->cleanup_state !== 'complete' && $rounds < $maximumRounds) {
            $budget = app(LifecycleCleanupBudget::class);
            $budget->start();
            $roundStart = hrtime(true);
            app(AccountLifecycleCleanup::class)->process($committed, $budget);
            $slowest = max($slowest, (hrtime(true) - $roundStart) / 1000000);
            $committed->refresh();
            $rounds++;
        }
        $totalMs = (hrtime(true) - $start) / 1000000;
        $this->assertSame('complete', $committed->cleanup_state);
        $this->assertLessThan(10000, $slowest);
        $this->assertSame(0, app(GroupImageUsage::class)->bytes($group));
        $this->assertDatabaseCount('activity_images', 0);
        $this->assertDatabaseCount('activity_image_deletions', 0);
        $this->assertSame($count, DB::table('activity_logs')->where('user_id', $subject->id)->where('content', '')->whereNull('note')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('activity-images'));
        $this->assertSame($evidence['sha256'], $files->readImageEvidence($operation->id)['sha256']);
        $this->assertSoftDeleted('users', ['id' => $subject->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        fwrite(STDERR, json_encode(['profile' => 'synthetic-image-load', 'images' => $count, 'total_bytes' => $count * $bytes,
            'capture_ms' => round($probe->milliseconds, 2), 'capture_queries' => $queries, 'initial_commit_ms' => round($commitMs, 2),
            'remaining_cleanup_rounds' => $rounds, 'slowest_remaining_round_ms' => round($slowest, 2), 'total_ms' => round($totalMs, 2),
            'php_peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR).PHP_EOL);
        Mail::assertNothingSent();
    }

    public function test_multi_page_evidence_is_verified_in_a_copied_bundle_and_reapplied_to_orphans(): void
    {
        config(['famie.lifecycle_operator_enabled' => true, 'famie.lifecycle_restore_test_enabled' => true]);
        [$group, $actor, $subject, $operation, $code] = $this->fixture(1001, 4);
        $committed = app(AccountLifecycleService::class)->commit($actor, $group, $operation->id, 'password', $code);
        for ($round = 0; $round < 12 && $committed->cleanup_state !== 'complete'; $round++) {
            app(AccountLifecycleCleanup::class)->process($committed);
            $committed->refresh();
        }
        $this->assertSame('complete', $committed->cleanup_state);
        $files = app(LifecycleOperatorFiles::class);
        $manifestSha = $files->readImageEvidence($operation->id)['sha256'];
        $ledgerSha = hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl');
        $evidence = app(LifecycleLedgerInspector::class)->inspect($ledgerSha, $operation->id)['operation'];
        $this->assertCount(1001, app(LifecycleImageEvidence::class)->read($operation->id, $evidence));
        $syncId = (string) Str::uuid();
        $directory = $this->root.'/keys/'.$syncId.'.evidence';
        File::makeDirectory($directory, 0700);
        File::copy($this->root.'/ledger/account-lifecycle.jsonl', $directory.'/account-lifecycle.jsonl');
        File::copy($files->imageEvidencePath($operation->id), $directory.'/'.$operation->id.'.images.json');
        $sync = ['format_version' => 2, 'sync_id' => $syncId, 'source_url' => 'https://synthetic.example.test', 'environment' => 'testing',
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'received_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'ledger_sha256' => $ledgerSha,
            'destination_medium_id' => 'synthetic-offline-copy', 'image_manifests' => [['receipt_id' => $operation->id, 'sha256' => $manifestSha]]];
        $syncPath = $this->root.'/keys/'.$syncId.'.ledger-sync.json';
        File::put($syncPath, json_encode($sync, JSON_THROW_ON_ERROR));
        $verified = app(LifecycleBackupAssessment::class)->synchronization($syncId, hash_file('sha256', $syncPath), $ledgerSha, $sync['source_url'], 'testing');
        $this->assertTrue($verified['bundle_verified']);
        $this->assertSame(1, $verified['verified_image_manifests']);
        $restoreId = (string) Str::uuid();
        $dump = $this->root.'/keys/'.$restoreId.'.restore.dump';
        File::put($dump, 'Synthetic already-retired database with missing image rows');
        $record = ['format_version' => 1, 'restore_id' => $restoreId, 'receipt_id' => $operation->id, 'source_url' => $sync['source_url'], 'environment' => 'testing',
            'ledger_sha256' => $ledgerSha, 'backup_sha256' => hash_file('sha256', $dump), 'restored_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'target' => ['driver' => 'sqlite', 'host' => null, 'port' => null, 'database' => ':memory:', 'schema' => null]];
        $recordPath = $this->root.'/keys/'.$restoreId.'.restore-receipt.json';
        File::put($recordPath, json_encode($record, JSON_THROW_ON_ERROR));
        $sha = hash_file('sha256', $recordPath);
        app(LifecycleRestoreReapply::class)->reapply($restoreId, $operation->id, $sha, $ledgerSha, $sync['source_url'], 'testing', 'synthetic-operator', true);
        for ($index = 0; $index < 1001; $index++) {
            Storage::disk('local')->put('activity-images/load-'.$index.'.webp', 'xxxx');
        }
        Storage::disk('local')->put('activity-images/unknown-copy.webp', 'keep');
        $result = app(LifecycleOrphanImages::class)->erase($restoreId, $operation->id, $sha, $ledgerSha, $sync['source_url'], 'testing', 'synthetic-operator', true);
        $this->assertSame(1001, $result['deleted']);
        $this->assertFalse($result['reopen_allowed']);
        $this->assertSame(['activity-images/unknown-copy.webp'], Storage::disk('local')->allFiles('activity-images'));
        $this->assertSame($manifestSha, $files->readImageEvidence($operation->id)['sha256']);
        $this->assertSame($ledgerSha, hash_file('sha256', $this->root.'/ledger/account-lifecycle.jsonl'));
        $this->assertSoftDeleted('users', ['id' => $subject->id]);
        Mail::assertNothingSent();
    }

    public function test_image_load_over_the_record_limit_does_not_change_accounts_or_source_data(): void
    {
        [$group, $actor, $subject, $operation, $code] = $this->fixture(10001, 4, false);
        $start = hrtime(true);
        try {
            app(AccountLifecycleService::class)->commit($actor, $group, $operation->id, 'password', $code);
            $this->fail('Oversized image set was committed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('bounded capture limit', $exception->getMessage());
            $this->assertPreserved($subject, $operation, 10001, 1);
            $this->assertFileDoesNotExist(app(LifecycleOperatorFiles::class)->imageEvidencePath($operation->id));
            fwrite(STDERR, json_encode(['profile' => 'synthetic-image-limit-rejection', 'images' => 10001,
                'rejection_ms' => round((hrtime(true) - $start) / 1000000, 2)], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        Mail::assertNothingSent();
    }

    public function test_ten_thousand_image_metadata_entries_fit_the_bounded_evidence_and_are_readable(): void
    {
        [, , $subject, $operation] = $this->fixture(10000, 4, false);
        $images = app(LifecycleImageEvidence::class);
        $sha = $images->capture($operation);
        $entries = $images->read($operation->id, ['image_manifest_sha256' => $sha, 'group_id' => $operation->group_id,
            'kind' => $operation->kind, 'subject_ids' => [$subject->id]]);
        $this->assertCount(10000, $entries);
        $this->assertSame(hash('sha256', 'xxxx'), $entries[0]['sha256']);
        $this->assertNull($entries[9999]['sha256']);
        $this->assertLessThanOrEqual(4194304, filesize(app(LifecycleOperatorFiles::class)->imageEvidencePath($operation->id)));
        $this->assertPreserved($subject, $operation, 10000, 1);
        Mail::assertNothingSent();
    }

    public function test_oversized_serialized_image_evidence_is_rejected_before_file_publication(): void
    {
        [, , $subject, $operation] = $this->fixture(1, 4);
        $images = [];
        for ($index = 0; $index < 10000; $index++) {
            $images[] = ['image_id' => $index + 1, 'user_id' => $subject->id, 'disk' => 'local',
                'path' => 'activity-images/'.$index.'-'.str_repeat('a', 190).'.webp', 'bytes' => 4, 'sha256' => hash('sha256', 'xxxx')];
        }
        $data = ['format_version' => 1, 'receipt_id' => $operation->id, 'group_id' => $operation->group_id,
            'kind' => $operation->kind, 'subject_ids' => [$subject->id], 'images' => $images];
        try {
            app(LifecycleOperatorFiles::class)->createImageEvidence($operation->id, $data);
            $this->fail('Oversized evidence was published.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('publication size limit', $exception->getMessage());
            $this->assertSame([], File::files($this->root.'/ledger'));
            $this->assertPreserved($subject, $operation, 1);
        }
        Mail::assertNothingSent();
    }

    public function test_second_page_timeout_does_not_publish_partial_evidence_or_change_accounts(): void
    {
        [$group, $actor, $subject, $operation, $code] = $this->fixture(501, 4, false);
        $expired = false;
        $budget = Mockery::mock(LifecycleCleanupBudget::class);
        $budget->shouldReceive('start')->with(10)->once();
        $budget->shouldReceive('available')->andReturnUsing(function () use (&$expired): bool {
            return ! $expired;
        });
        $files = app(LifecycleOperatorFiles::class);
        $real = new LifecycleImageEvidence($files, new LifecycleCleanupBudget);
        $images = Mockery::mock(LifecycleImageEvidence::class, [$files, $budget])->makePartial();
        $read = 0;
        $images->shouldReceive('fingerprint')->andReturnUsing(function ($path) use ($real, &$read, &$expired): ?array {
            $result = $real->fingerprint($path);
            if (++$read === 501) {
                $expired = true;
            }

            return $result;
        });
        $this->app->instance(LifecycleImageEvidence::class, $images);
        try {
            app(AccountLifecycleService::class)->commit($actor, $group, $operation->id, 'password', $code);
            $this->fail('Second-page timeout was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('budget exhausted', $exception->getMessage());
            $this->assertSame(501, $read);
            $this->assertSame([], File::files($this->root.'/ledger'));
            $this->assertPreserved($subject, $operation, 501, 1);
        }
        Mail::assertNothingSent();
    }

    public function test_duplicate_ownership_in_a_later_page_is_not_ignored(): void
    {
        [$group, $actor, $subject, $operation, $code] = $this->fixture(501, 4, false);
        $last = DB::table('activity_images')->orderByDesc('id')->value('id');
        DB::table('activity_images')->where('id', $last)->update(['path' => 'activity-images/load-0.webp']);
        try {
            app(AccountLifecycleService::class)->commit($actor, $group, $operation->id, 'password', $code);
            $this->fail('Ownership conflict on another page was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ownership is ambiguous', $exception->getMessage());
            $this->assertSame([], File::files($this->root.'/ledger'));
            $this->assertPreserved($subject, $operation, 501, 1);
        }
        Mail::assertNothingSent();
    }

    #[TestWith([20971520, true])]
    #[TestWith([20971521, false])]
    public function test_image_load_file_size_boundary_is_checked_before_account_changes(int $bytes, bool $allowed): void
    {
        [, , $subject, $operation] = $this->fixture(1, 4);
        $path = Storage::disk('local')->path('activity-images/load-0.webp');
        $handle = fopen($path, 'r+b');
        $this->assertTrue(ftruncate($handle, $bytes));
        fclose($handle);
        DB::table('activity_images')->update(['bytes' => $bytes]);
        try {
            $sha = app(LifecycleImageEvidence::class)->capture($operation);
            $this->assertTrue($allowed, 'Oversized file was accepted.');
            $this->assertSame($sha, app(LifecycleOperatorFiles::class)->readImageEvidence($operation->id)['sha256']);
        } catch (RuntimeException $exception) {
            $this->assertFalse($allowed, $exception->getMessage());
            $this->assertFileDoesNotExist(app(LifecycleOperatorFiles::class)->imageEvidencePath($operation->id));
        }
        $this->assertPreserved($subject, $operation, 1);
        Mail::assertNothingSent();
    }

    #[TestWith(['before-read'])]
    #[TestWith(['after-last-read'])]
    #[TestWith(['after-publication'])]
    public function test_image_load_budget_expiry_preserves_accounts_and_allows_retry_after_publication(string $phase): void
    {
        [$group, $actor, $subject, $operation, $code] = $this->fixture(1, 4);
        $expired = $phase === 'before-read';
        $budget = Mockery::mock(LifecycleCleanupBudget::class);
        $budget->shouldReceive('start')->with(10)->once();
        $budget->shouldReceive('available')->andReturnUsing(function () use (&$expired): bool {
            return ! $expired;
        });
        $realFiles = app(LifecycleOperatorFiles::class);
        $files = $realFiles;
        if ($phase === 'after-publication') {
            $files = Mockery::mock(LifecycleOperatorFiles::class)->makePartial();
            $files->shouldReceive('createImageEvidence')->once()->andReturnUsing(function ($id, $data) use ($realFiles, &$expired): void {
                $realFiles->createImageEvidence($id, $data);
                $expired = true;
            });
        }
        $images = new LifecycleImageEvidence($files, $budget);
        if ($phase === 'after-last-read') {
            $realImages = $images;
            $images = Mockery::mock(LifecycleImageEvidence::class, [$files, $budget])->makePartial();
            $images->shouldReceive('fingerprint')->once()->andReturnUsing(function ($path) use ($realImages, &$expired): ?array {
                $result = $realImages->fingerprint($path);
                $expired = true;

                return $result;
            });
        }
        $this->app->instance(LifecycleImageEvidence::class, $images);
        try {
            app(AccountLifecycleService::class)->commit($actor, $group, $operation->id, 'password', $code);
            $this->fail('Exhausted capture budget was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('budget exhausted', $exception->getMessage());
            $this->assertPreserved($subject, $operation, 1);
        }
        $path = $realFiles->imageEvidencePath($operation->id);
        if ($phase === 'after-publication') {
            $sha = hash_file('sha256', $path);
            $this->app->instance(LifecycleImageEvidence::class, new LifecycleImageEvidence($realFiles, new LifecycleCleanupBudget));
            $committed = app(AccountLifecycleService::class)->commit($actor, $group, $operation->id, 'password', $code);
            $this->assertSame('committed', $committed->state);
            $this->assertSame($sha, hash_file('sha256', $path));
        } else {
            $this->assertFileDoesNotExist($path);
        }
        Mail::assertNothingSent();
    }

    /** @return array{Group,User,User,AccountLifecycleOperation,string} */
    private function fixture(int $count, int $bytes, bool $allFiles = true): array
    {
        Storage::fake('local');
        Mail::fake();
        $pair = sodium_crypto_box_keypair();
        config(['famie.lifecycle_public_key' => base64_encode(sodium_crypto_box_publickey($pair)), 'famie.lifecycle_key_id' => 'synthetic-load-key']);
        sodium_memzero($pair);
        $group = Group::factory()->create();
        $actor = GroupMember::factory()->for($group)->for(User::factory())->create(['role' => 'admin']);
        $member = GroupMember::factory()->for($group)->for(User::factory())->create(['status' => 'inactive']);
        $group->forceFill(['representative_user_id' => $actor->user_id])->save();
        $category = Category::query()->create(['group_id' => $group->id, 'name' => 'Synthetic load', 'color_code' => '#10B981', 'sort_order' => 1]);
        $logs = [];
        for ($index = 0; $index < $count; $index++) {
            $logs[] = ['group_id' => $group->id, 'user_id' => $member->user_id, 'category_id' => $category->id,
                'activity_date' => now()->toDateString(), 'content' => 'Synthetic load content', 'note' => 'Synthetic note',
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($logs, 100) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }
        $images = [];
        $payload = str_repeat('x', $bytes);
        foreach (DB::table('activity_logs')->where('user_id', $member->user_id)->orderBy('id')->pluck('id') as $index => $logId) {
            $path = 'activity-images/load-'.$index.'.webp';
            if ($allFiles || $index === 0) {
                Storage::disk('local')->put($path, $payload);
            }
            $images[] = ['group_id' => $group->id, 'activity_log_id' => $logId, 'disk' => 'local', 'path' => $path, 'bytes' => $bytes,
                'width' => 1, 'height' => 1, 'mime' => 'image/webp', 'position' => 0, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($images, 100) as $chunk) {
            DB::table('activity_images')->insert($chunk);
        }
        $member->user->createToken('synthetic-preserved-token');
        $prepared = app(AccountLifecycleService::class)->prepare($actor->user, $group, 'member_delete', $member->id);

        return [$group, $actor->user, $member->user, $prepared['operation'], $prepared['code']];
    }

    private function assertPreserved(User $subject, AccountLifecycleOperation $operation, int $count, ?int $files = null): void
    {
        $this->assertSame($subject->getAttributes(), $subject->fresh()->getAttributes());
        $this->assertDatabaseHas('account_lifecycle_operations', ['id' => $operation->id, 'state' => 'prepared', 'committed_at' => null]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertSame($count, DB::table('activity_images')->whereNull('retired_at')->count());
        $this->assertSame($count, DB::table('activity_logs')->where('content', 'Synthetic load content')->where('note', 'Synthetic note')->count());
        $this->assertCount($files ?? $count, Storage::disk('local')->allFiles('activity-images'));
        $this->assertSame([], File::files($this->root.'/vault'));
        $this->assertFileDoesNotExist($this->root.'/ledger/account-lifecycle.jsonl');
    }
}
