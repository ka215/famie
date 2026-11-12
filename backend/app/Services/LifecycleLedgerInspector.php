<?php

namespace App\Services;

use App\Support\LifecycleStoragePath;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

class LifecycleLedgerInspector
{
    /**
     * The consumer runs under the shared ledger lock, after checksum validation.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $expectedSha256, ?string $receipt = null, bool $includeOperations = false, ?Closure $consume = null): array
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) || ($receipt !== null && ! Str::isUuid($receipt))) {
            throw new RuntimeException('A separately trusted ledger checksum is required.');
        }
        $root = LifecycleStoragePath::root('famie.lifecycle_ledger_root', 'messages.lifecycle_ledger_unavailable');
        $path = $root.'/account-lifecycle.jsonl';
        LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_ledger_unavailable');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Ledger unreadable.');
        }
        try {
            if (! flock($handle, LOCK_SH | LOCK_NB)) {
                throw new RuntimeException('Ledger is busy.');
            }
            $opened = fstat($handle);
            $named = lstat($path);
            if ($opened === false || $named === false || $opened['size'] > 16777216 || $opened['ino'] !== $named['ino']
                || $opened['dev'] !== $named['dev'] || $opened['nlink'] !== 1) {
                throw new RuntimeException('Unsafe or oversized ledger.');
            }
            $states = [];
            $identities = [];
            $holds = [];
            $holdOwners = [];
            $cleanup = [];
            $records = 0;
            $hash = hash_init('sha256');
            while (($line = fgets($handle, 65536)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('Incomplete ledger record.');
                }
                hash_update($hash, $line);
                $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($record) || array_diff(array_keys($record), ['recorded_at', 'event', 'operation_id', 'kind', 'group_id', 'committed_at', 'vault_sha256', 'subject_ids', 'hold_id', 'reference', 'scope', 'review_at', 'operator', 'cleanup_state', 'content_cursor', 'recovery_identity', 'image_manifest_sha256'])
                    || ! is_string($record['operation_id'] ?? null) || ! Str::isUuid($record['operation_id'])
                    || ! in_array($record['kind'] ?? null, ['member_delete', 'group_withdraw'], true)
                    || ! is_int($record['group_id'] ?? null) || $record['group_id'] <= 0
                    || ! is_string($record['recorded_at'] ?? null) || ! array_key_exists('committed_at', $record)
                    || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)\z/', $record['recorded_at'])) {
                    throw new RuntimeException('Invalid ledger record.');
                }
                $id = $record['operation_id'];
                $event = $record['event'] ?? null;
                if (array_key_exists('image_manifest_sha256', $record) && ($event !== 'intent' || ! is_string($record['image_manifest_sha256'])
                    || ! preg_match('/\A[a-f0-9]{64}\z/', $record['image_manifest_sha256']))) {
                    throw new RuntimeException('Invalid image evidence context.');
                }
                $previous = $states[$id] ?? null;
                $holdEvent = in_array($event, ['hold.started', 'hold.released'], true);
                if (! in_array($event, ['intent', 'recovery.identity'], true) && array_key_exists('recovery_identity', $record)) {
                    throw new RuntimeException('Unexpected recovery identity context.');
                }
                if ($event !== 'cleanup.progress' && array_intersect(array_keys($record), ['cleanup_state', 'content_cursor'])) {
                    throw new RuntimeException('Unexpected cleanup context.');
                }
                if (! $holdEvent && array_intersect(array_keys($record), ['hold_id', 'reference', 'scope', 'review_at', 'operator'])) {
                    throw new RuntimeException('Unexpected hold context.');
                }
                foreach ([$record['recorded_at'], $record['committed_at']] as $time) {
                    if ($time === null) {
                        continue;
                    }
                    if (! is_string($time) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)\z/', $time)
                        || CarbonImmutable::parse($time)->utc()->format('Y-m-d\TH:i:s') !== substr($time, 0, 19)) {
                        throw new RuntimeException('Invalid ledger timestamp.');
                    }
                }
                if (isset($identities[$id]) && ($identities[$id]['group_id'] !== $record['group_id'] || $identities[$id]['kind'] !== $record['kind'])) {
                    throw new RuntimeException('Ledger operation identity changed.');
                }
                if ($event !== 'intent' && (array_key_exists('subject_ids', $record) || array_key_exists('vault_sha256', $record))) {
                    throw new RuntimeException('Unexpected ledger context.');
                }
                if ($event === 'recovery.identity') {
                    $identity = $record['recovery_identity'] ?? null;
                    if ($previous !== 'committed' || $record['committed_at'] !== $identities[$id]['committed_at']
                        || ! LifecycleRecoveryIdentity::valid($identity, $identities[$id]['subject_ids'], $record['kind'])
                        || ($identities[$id]['recovery_identity'] !== null && $identities[$id]['recovery_identity'] !== $identity)) {
                        throw new RuntimeException('Contradictory recovery identity.');
                    }
                    $identities[$id]['recovery_identity'] = $identity;
                    $records++;

                    continue;
                } elseif ($event === 'cleanup.progress') {
                    $old = $cleanup[$id] ?? null;
                    if ($previous !== 'committed' || $record['committed_at'] !== $identities[$id]['committed_at']
                        || ! in_array($record['cleanup_state'] ?? null, ['processing', 'complete'], true)
                        || ! is_int($record['content_cursor'] ?? null) || $record['content_cursor'] < 0
                        || ($old !== null && ($record['content_cursor'] < $old['content_cursor'] || ($old['state'] === 'complete' && $record['cleanup_state'] !== 'complete')))) {
                        throw new RuntimeException('Contradictory cleanup evidence.');
                    }
                    $cleanup[$id] = ['state' => $record['cleanup_state'], 'content_cursor' => $record['content_cursor'], 'recorded_at' => $record['recorded_at']];
                    $records++;

                    continue;
                } elseif ($holdEvent) {
                    if ($previous !== 'committed' || $record['committed_at'] !== $identities[$id]['committed_at']
                        || ! is_int($record['hold_id'] ?? null) || $record['hold_id'] < 1 || ($record['scope'] ?? null) !== 'all'
                        || ! is_string($record['reference'] ?? null) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $record['reference'])
                        || ! is_string($record['operator'] ?? null) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $record['operator'])
                        || ! is_string($record['review_at'] ?? null) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $record['review_at'])
                        || CarbonImmutable::parse($record['review_at'])->format('Y-m-d\TH:i:s\Z') !== $record['review_at']) {
                        throw new RuntimeException('Invalid hold evidence.');
                    }
                    $holdId = $record['hold_id'];
                    if (isset($holdOwners[$holdId]) && $holdOwners[$holdId] !== $id) {
                        throw new RuntimeException('Hold identity belongs to another operation.');
                    }
                    $holdOwners[$holdId] = $id;
                    $evidence = ['reference' => $record['reference'], 'scope' => $record['scope'], 'review_at' => $record['review_at']];
                    $old = $holds[$id][$holdId] ?? null;
                    if (($event === 'hold.started' && $old !== null)
                        || ($event === 'hold.released' && ($old === null || $old['released'] || array_diff_assoc($evidence, $old)))) {
                        throw new RuntimeException('Contradictory hold evidence.');
                    }
                    if ($event === 'hold.released' && CarbonImmutable::parse($record['recorded_at'])->lessThan(CarbonImmutable::parse($old['started_recorded_at']))) {
                        throw new RuntimeException('Hold release precedes its recorded start.');
                    }
                    $holds[$id][$holdId] = [...$evidence, 'released' => $event === 'hold.released',
                        'started_recorded_at' => $old['started_recorded_at'] ?? $record['recorded_at'],
                        'released_recorded_at' => $event === 'hold.released' ? $record['recorded_at'] : null];
                    $records++;

                    continue;
                } elseif ($event === 'intent') {
                    if (! in_array($previous, [null, 'aborted'], true) || ! is_string($record['vault_sha256'] ?? null)
                        || ! preg_match('/\A[a-f0-9]{64}\z/', $record['vault_sha256']) || ! is_array($record['subject_ids'] ?? null)
                        || ! array_is_list($record['subject_ids']) || $record['subject_ids'] === [] || ! is_string($record['committed_at'])
                        || ($record['kind'] === 'member_delete' && count($record['subject_ids']) !== 1)) {
                        throw new RuntimeException('Ambiguous ledger intent.');
                    }
                    foreach ($record['subject_ids'] as $subject) {
                        if (! is_int($subject) || $subject <= 0) {
                            throw new RuntimeException('Invalid ledger subject.');
                        }
                    }
                    $identity = $record['recovery_identity'] ?? null;
                    if (array_key_exists('recovery_identity', $record) && ! LifecycleRecoveryIdentity::valid($identity, $record['subject_ids'], $record['kind'])) {
                        throw new RuntimeException('Invalid recovery identity.');
                    }
                    $identities[$id] = ['group_id' => $record['group_id'], 'kind' => $record['kind'], 'committed_at' => $record['committed_at'], 'vault_sha256' => $record['vault_sha256'], 'subject_ids' => $record['subject_ids'], 'recovery_identity' => $identity,
                        'image_manifest_sha256' => $record['image_manifest_sha256'] ?? null];
                } elseif ($event === 'committed') {
                    if (! in_array($previous, ['intent', 'committed'], true) || ! is_string($record['committed_at'])
                        || $record['committed_at'] !== $identities[$id]['committed_at']) {
                        throw new RuntimeException('Unconfirmed ledger commitment.');
                    }
                } elseif ($event === 'aborted') {
                    if (! in_array($previous, ['intent', 'aborted'], true)) {
                        throw new RuntimeException('Contradictory ledger abort.');
                    }
                } else {
                    throw new RuntimeException('Unknown ledger event.');
                }
                $states[$id] = $event;
                $records++;
            }
            $actual = hash_final($hash);
            if (! hash_equals($expectedSha256, $actual)) {
                throw new RuntimeException('Ledger checksum mismatch.');
            }

            $result = ['records' => $records, 'committed' => count(array_filter($states, fn ($state) => $state === 'committed')),
                'aborted' => count(array_filter($states, fn ($state) => $state === 'aborted')),
                'unresolved' => count(array_filter($states, fn ($state) => $state === 'intent')), 'sha256' => $actual];
            if ($receipt !== null) {
                $result['operation'] = isset($identities[$receipt]) ? ['state' => $states[$receipt], ...$identities[$receipt]] : null;
                $result['holds'] = $holds[$receipt] ?? [];
                $result['cleanup'] = $cleanup[$receipt] ?? null;
            }
            if ($includeOperations) {
                $result['operations'] = [];
                foreach ($identities as $id => $identity) {
                    $result['operations'][$id] = ['state' => $states[$id], ...$identity, 'holds' => $holds[$id] ?? [], 'cleanup' => $cleanup[$id] ?? null];
                }
            }

            return $consume === null ? $result : $consume($result);
        } finally {
            fclose($handle);
        }
    }
}
