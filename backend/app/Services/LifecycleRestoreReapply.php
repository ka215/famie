<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\DestructiveDatabaseCommandGuard;
use App\Support\LifecycleStoragePath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

class LifecycleRestoreReapply
{
    public function __construct(private LifecycleOperatorFiles $files, private LifecycleLedgerInspector $ledger,
        private LifecycleAudit $audit, private AccountEmailService $emails, private AccountLifecycleCleanup $cleanup,
        private LifecycleVault $vault, private LifecycleCleanupBudget $budget, private LifecycleRestoreHolds $holds, private LifecycleRestoreIdentity $identity,
        private LegalAcceptanceService $legalAcceptances) {}

    /** @return array<string,mixed> */
    public function reapply(string $restoreId, string $receipt, string $recordSha, string $ledgerSha, string $sourceUrl, string $sourceEnvironment, string $operator, bool $apply = false, bool $holdRecovery = false): array
    {
        $target = $this->isolatedTarget();
        if (! Str::isUuid($restoreId) || ! Str::isUuid($receipt) || ! preg_match('/\A[a-f0-9]{64}\z/', $recordSha)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $ledgerSha) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $operator)) {
            throw new RuntimeException('Invalid restore references.');
        }
        $loaded = $this->files->readControlRecord($restoreId, 'restore-receipt');
        $record = $loaded['data'];
        $fields = ['format_version', 'restore_id', 'receipt_id', 'source_url', 'environment', 'ledger_sha256', 'backup_sha256', 'restored_at', 'target'];
        if (! hash_equals($recordSha, $loaded['sha256']) || count($record) !== count($fields) || array_diff(array_keys($record), $fields)
            || ($record['format_version'] ?? null) !== 1 || ($record['restore_id'] ?? null) !== $restoreId || ($record['receipt_id'] ?? null) !== $receipt
            || ($record['source_url'] ?? null) !== $sourceUrl || $sourceUrl === '' || ($record['environment'] ?? null) !== $sourceEnvironment || $sourceEnvironment === ''
            || ($record['ledger_sha256'] ?? null) !== $ledgerSha || ! is_string($record['backup_sha256'] ?? null)
            || ! hash_equals($this->files->restoreBackupHash($restoreId), $record['backup_sha256'])
            || ! is_array($record['target'] ?? null) || count($record['target']) !== count($target)
            || array_diff(array_keys($record['target']), array_keys($target))) {
            throw new RuntimeException('Restore receipt does not match the source or backup.');
        }
        foreach ($target as $key => $value) {
            if ($record['target'][$key] !== $value) {
                throw new RuntimeException('Restore target mismatch.');
            }
        }
        $time = $record['restored_at'] ?? null;
        if (! is_string($time) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $time)) {
            throw new RuntimeException('Invalid restore time.');
        }
        $restored = CarbonImmutable::parse($time)->utc();
        if ($restored->format('Y-m-d\TH:i:s\Z') !== $time || $restored->isFuture() || $restored->lessThanOrEqualTo(now()->utc()->subMinutes(15))) {
            throw new RuntimeException('Restore receipt is stale.');
        }
        $snapshot = $this->ledger->inspect($ledgerSha, $receipt, includeOperations: $holdRecovery);
        $evidence = $snapshot['operation'];
        if ($snapshot['unresolved'] !== 0 || $evidence === null || $evidence['state'] !== 'committed') {
            throw new RuntimeException('Restore requires committed evidence.');
        }
        $event = $holdRecovery ? 'restore.holds' : 'restore.reapply';
        $sequenceFloor = 0;
        foreach ($snapshot['operations'] ?? [] as $sourceOperation) {
            foreach (array_keys($sourceOperation['holds']) as $holdId) {
                $sequenceFloor = max($sequenceFloor, $holdId);
            }
        }
        $context = ['control_id' => $restoreId];
        $pendingCheckpoint = 'restore_reapply_pending:'.$recordSha;
        $completeCheckpoint = 'restore_reapply_complete:'.$recordSha;
        if ($apply) {
            $this->audit->record($event, 'started', $operator, $receipt, context: $context);
        }
        try {
            $result = DB::transaction(function () use ($receipt, $ledgerSha, $evidence, $apply, $pendingCheckpoint, $completeCheckpoint, $holdRecovery, $recordSha, $sequenceFloor): array {
                $group = Group::query()->whereKey($evidence['group_id'])->lockForUpdate()->firstOrFail();
                $operation = $this->identity->operation($group, $receipt, $evidence, $recordSha, $apply);

                $result = $this->ledger->inspect($ledgerSha, $receipt, consume: function (array $verified) use ($receipt, $evidence, $group, $operation, $apply, $pendingCheckpoint, $completeCheckpoint, $holdRecovery, $recordSha): array {
                    if ($verified['operation'] !== $evidence
                        || $operation->group_id !== $evidence['group_id'] || $operation->kind !== $evidence['kind']
                        || ! in_array($operation->state, ['prepared', 'committed'], true)
                        || ($operation->committed_at !== null && $operation->committed_at->utc()->toIso8601String() !== $evidence['committed_at'])) {
                        throw new RuntimeException('Restored operation identity changed.');
                    }
                    $committed = CarbonImmutable::parse($evidence['committed_at']);
                    $until = $committed->addDays(30);
                    if ($committed->isFuture() || ($operation->retention_until !== null && ! $operation->retention_until->equalTo($until))) {
                        throw new RuntimeException('Restored deadline changed.');
                    }
                    $subjects = $this->identity->subjects($operation, $evidence, $apply);
                    $expected = $evidence['subject_ids'];
                    sort($expected);
                    if ($subjects->pluck('user_id')->all() !== $expected || count(array_unique($expected)) !== count($expected)) {
                        throw new RuntimeException('Restored subjects do not match committed evidence.');
                    }
                    if ($operation->kind === 'group_withdraw' && GroupMember::withTrashed()->where('group_id', $group->id)->count() !== $subjects->count()) {
                        throw new RuntimeException('Family membership changed.');
                    }
                    if ($operation->kind === 'member_delete' && in_array($group->representative_user_id, $expected, true)) {
                        throw new RuntimeException('Restored representative assignment requires reconciliation.');
                    }
                    $members = GroupMember::withTrashed()->whereIn('id', $subjects->pluck('membership_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $users = User::withTrashed()->whereIn('id', $expected)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    foreach ($subjects as $subject) {
                        $member = $members->get($subject->membership_id);
                        $user = $users->get($subject->user_id);
                        if ($member === null || $user === null || $member->group_id !== $group->id || $member->user_id !== $subject->user_id
                            || ($member->lifecycle_operation_id !== null && $member->lifecycle_operation_id !== $receipt)
                            || ($user->lifecycle_operation_id !== null && $user->lifecycle_operation_id !== $receipt)) {
                            throw new RuntimeException('Restored account mapping changed.');
                        }
                    }
                    $holdPlan = $this->holds->inspect($operation, $verified['holds'], $holdRecovery);
                    if ($holdRecovery) {
                        if ($apply) {
                            $this->holds->insert($operation, $holdPlan['missing']);
                            if (! in_array($operation->last_error_code, [$pendingCheckpoint, $completeCheckpoint], true)) {
                                $operation->forceFill(['last_error_code' => 'restore_reapply_holds:'.$recordSha])->save();
                            }
                        }

                        return ['receipt_id' => $receipt, 'holds' => $holdPlan['holds'], 'missing_holds' => count($holdPlan['missing']),
                            'held' => $holdPlan['held'], 'applied' => $apply, 'reopen_allowed' => false];
                    }
                    if (! $holdPlan['held']) {
                        $this->imageTargets($group->id, $expected, $receipt);
                    }
                    if ($holdPlan['held'] && (! $this->vault->exists($receipt)
                        || ! hash_equals($evidence['vault_sha256'], hash('sha256', $this->files->readVault($receipt))))) {
                        throw new RuntimeException('Held identity evidence must be preserved before masking.');
                    }
                    if (! $holdPlan['held'] && $until->lessThanOrEqualTo(now()) && $this->vault->exists($receipt)
                        && ! hash_equals($evidence['vault_sha256'], hash('sha256', $this->files->readVault($receipt)))) {
                        throw new RuntimeException('Expired vault identity changed.');
                    }
                    if ($apply) {
                        foreach ($subjects as $subject) {
                            $user = $users[$subject->user_id];
                            $this->emails->clearChallenges($user);
                            $user->tokens()->delete();
                            $this->legalAcceptances->forgetUser((int) $user->id);
                            DB::table('sessions')->where('user_id', $user->id)->delete();
                            $user->forceFill(['username' => 'deleted:'.$user->id.':'.substr(str_replace('-', '', $receipt), 0, 8),
                                'display_name' => 'Deleted member', 'email' => null, 'email_verified_at' => null,
                                'password' => Hash::make(Str::password(64)), 'remember_token' => null,
                                'deleted_at' => $committed, 'lifecycle_operation_id' => $receipt])->save();
                            $members[$subject->membership_id]->forceFill(['status' => 'inactive', 'deleted_at' => $committed, 'lifecycle_operation_id' => $receipt])->save();
                        }
                        if ($operation->kind === 'group_withdraw') {
                            $group->forceFill(['name' => 'Withdrawn family', 'withdrawn_at' => $committed, 'lifecycle_operation_id' => $receipt])->save();
                        }
                        $operation->forceFill(['state' => 'committed', 'committed_at' => $committed, 'retention_until' => $until,
                            'cleanup_state' => 'processing',
                            'content_cursor' => in_array($operation->last_error_code, [$pendingCheckpoint, $completeCheckpoint], true) ? $operation->content_cursor : 0,
                            'last_error_code' => $pendingCheckpoint])->save();
                        if (! $holdPlan['held'] && $until->lessThanOrEqualTo(now())) {
                            $operation->credential()->delete();
                        } else {
                            $operation->credential()->update(['expires_at' => $until]);
                        }
                    }

                    return ['receipt_id' => $receipt, 'subjects' => $subjects->count(), 'retention_until' => $until->toIso8601String(),
                        'expired' => $until->lessThanOrEqualTo(now()), 'held' => $holdPlan['held'], 'applied' => $apply, 'reopen_allowed' => false];
                });
                if ($holdRecovery && $apply) {
                    $this->holds->advanceSequence($sequenceFloor);
                }

                return $result;
            });
            if ($apply && ! $holdRecovery) {
                $operation = AccountLifecycleOperation::query()->findOrFail($receipt);
                if (! $result['held']) {
                    $this->budget->start();
                    $this->cleanup->process($operation, $this->budget, false);
                }
                DB::transaction(function () use ($receipt, $ledgerSha, $evidence, $completeCheckpoint, $pendingCheckpoint, &$result): void {
                    $locked = AccountLifecycleOperation::query()->whereKey($receipt)->lockForUpdate()->firstOrFail();
                    $this->ledger->inspect($ledgerSha, $receipt, consume: function (array $verified) use ($locked, $receipt, $evidence, $completeCheckpoint, $pendingCheckpoint, &$result): array {
                        $holdPlan = $this->holds->inspect($locked, $verified['holds']);
                        if ($holdPlan['held'] !== $result['held']) {
                            throw new RuntimeException('Restore protection changed during reapplication.');
                        }
                        if (! $result['held'] && $result['expired'] && $this->vault->exists($receipt)) {
                            if (! hash_equals($evidence['vault_sha256'], hash('sha256', $this->files->readVault($receipt)))) {
                                throw new RuntimeException('Expired restore vault changed.');
                            }
                            $this->vault->delete($receipt);
                        }
                        $result['cleanup_state'] = $locked->cleanup_state;
                        $locked->forceFill(['last_error_code' => $result['cleanup_state'] === 'complete' ? $completeCheckpoint : $pendingCheckpoint])->save();

                        return [];
                    });
                });
            }
            if ($apply) {
                $this->audit->record($event, 'success', $operator, $receipt, context: $context);
            }

            return $result;
        } catch (Throwable) {
            if ($apply) {
                $this->audit->record($event, 'failure', $operator, $receipt, context: $context);
            }
            throw new RuntimeException('Isolated restore reapplication failed; keep the restored application offline.');
        }
    }

    /** @return array<string,mixed> */
    public function isolatedTarget(): array
    {
        if (! config('famie.lifecycle_restore_test_enabled') || ! config('famie.lifecycle_operator_enabled')
            || ! app()->environment('testing') || getenv('FAMIE_DB_TEST_RUN') !== '1') {
            throw new RuntimeException('Restore reapplication is limited to explicit isolated tests.');
        }
        $connection = DB::connection()->getConfig();
        DestructiveDatabaseCommandGuard::assertTestConnection('testing', $connection, config('database-safety.profiles.'.config('database-safety.profile'), []));
        $target = ['driver' => $connection['driver'], 'host' => null, 'port' => null, 'database' => $connection['database'], 'schema' => null];
        if ($connection['driver'] === 'pgsql') {
            $identity = DB::selectOne("select current_database() as db, current_user as role, inet_server_port() as port, current_schema() as schema,
                current_setting('search_path') as search_path,
                case when not exists (select 1 from pg_roles where rolname = current_user and (rolsuper or rolcreatedb or rolcreaterole or rolreplication or rolbypassrls)) then 1 else 0 end as restricted");
            if ($connection['host'] !== '127.0.0.1' || (string) $connection['port'] !== '55432' || $connection['database'] !== 'famie_test'
                || $connection['username'] !== 'famie_test_runner' || $identity->db !== 'famie_test' || $identity->role !== 'famie_test_runner'
                || $identity->port !== 55432 || (int) $identity->restricted !== 1 || $identity->search_path !== $identity->schema
                || ! preg_match('/\Afamie_lifecycle_regression_\d+_[a-f0-9]{8}_test\z/', $identity->schema)) {
                throw new RuntimeException('Restore target is not a disposable PostgreSQL schema.');
            }
            $target = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55432, 'database' => 'famie_test', 'schema' => $identity->schema];
        } elseif ($connection['driver'] !== 'sqlite' || $connection['database'] !== ':memory:') {
            throw new RuntimeException('Restore target is not an in-memory test database.');
        } else {
            foreach (DB::select('PRAGMA database_list') as $database) {
                if (! in_array($database->name, ['main', 'temp'], true) || $database->file !== '') {
                    throw new RuntimeException('Attached databases are not permitted during restore training.');
                }
            }
        }
        $workspace = realpath(dirname(storage_path()));
        if ($workspace === false || dirname($workspace) !== realpath(sys_get_temp_dir())
            || ! preg_match('/\Afamie-(?:f03|restore-drill)-[a-f0-9]{16}\z/', basename($workspace))
            || realpath(storage_path()) !== $workspace.DIRECTORY_SEPARATOR.'runtime' || is_link(storage_path())) {
            throw new RuntimeException('Restore workspace is not an isolated fixture directory.');
        }
        foreach (['lifecycle_operator_root', 'lifecycle_audit_root', 'lifecycle_ledger_root', 'lifecycle_vault_root'] as $key) {
            $root = LifecycleStoragePath::root('famie.'.$key, 'messages.lifecycle_vault_unavailable');
            if (! str_starts_with(str_replace('\\', '/', $root).'/', str_replace('\\', '/', $workspace).'/')) {
                throw new RuntimeException('Restore evidence is outside the fixture directory.');
            }
        }
        $disk = Storage::disk('local');
        $imageRoot = realpath($disk->path(''));
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter || $imageRoot === false
            || $imageRoot !== realpath(storage_path('framework/testing/disks/local')) || is_link($imageRoot)
            || ! str_starts_with(str_replace('\\', '/', $imageRoot).'/', str_replace('\\', '/', $workspace).'/runtime/')) {
            throw new RuntimeException('Restore image storage is not a local fake disk.');
        }

        return $target;
    }

    /** @param list<int> $users */
    private function imageTargets(int $group, array $users, string $receipt): void
    {
        $logs = DB::table('activity_logs')->where('group_id', $group)->whereIn('user_id', $users)->select('id');
        $rows = DB::table('activity_images')->whereIn('activity_log_id', $logs)->get();
        foreach ($rows as $image) {
            if (($image->lifecycle_operation_id !== null && $image->lifecycle_operation_id !== $receipt)
                || ($image->retired_at !== null && $image->lifecycle_operation_id !== $receipt)) {
                throw new RuntimeException('Restored image ownership changed.');
            }
        }
        $queued = DB::table('activity_image_deletions')->where(fn ($query) => $query->where('operation_id', $receipt)->orWhereIn('activity_image_id', $rows->pluck('id')))->get();
        foreach ($queued as $image) {
            if ($image->operation_id !== $receipt) {
                throw new RuntimeException('Restored deletion queue ownership changed.');
            }
        }
        foreach ([...$rows, ...$queued] as $image) {
            if ($image->group_id !== $group || $image->disk !== 'local' || ! preg_match('/\Aactivity-images\/[A-Za-z0-9][A-Za-z0-9_.\/-]*\z/', $image->path)
                || in_array('', explode('/', $image->path), true) || in_array('..', explode('/', $image->path), true) || in_array('.', explode('/', $image->path), true)) {
                throw new RuntimeException('Restored image target is ambiguous.');
            }
            $path = rtrim(Storage::disk('local')->path(''), '/\\');
            foreach (explode('/', $image->path) as $part) {
                $path .= '/'.$part;
                if (is_link($path)) {
                    throw new RuntimeException('Restored image target is linked.');
                }
            }
            if (file_exists($path)) {
                $stat = lstat($path);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
                    throw new RuntimeException('Restored image is not a unique regular file.');
                }
            }
        }
    }
}
