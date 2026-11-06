<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

class LifecycleBackupAssessment
{
    public function __construct(private LifecycleOperatorFiles $files, private LifecycleLedgerInspector $ledger, private LifecycleImageEvidence $images) {}

    /** @return array<string,mixed> */
    public function inventory(string $id, string $checksum, string $ledgerChecksum, string $sourceUrl, string $environment): array
    {
        $record = $this->record($id, 'backup-inventory', $checksum, $sourceUrl, $environment);
        $fields = ['format_version', 'inventory_id', 'source_url', 'environment', 'inventoried_at', 'coverage_complete', 'copies'];
        if (count($record) !== count($fields) || array_diff(array_keys($record), $fields) || ($record['inventory_id'] ?? null) !== $id
            || ! is_bool($record['coverage_complete'] ?? null) || ! is_array($record['copies'] ?? null)
            || ! array_is_list($record['copies']) || count($record['copies']) > 1000) {
            throw new RuntimeException('Invalid backup inventory.');
        }
        $inventoried = $this->instant($record['inventoried_at'] ?? null);
        if ($inventoried->isFuture()) {
            throw new RuntimeException('Inventory timestamp is in the future.');
        }
        $copies = [];
        $unknown = 0;
        $unverified = 0;
        $latest = null;
        $copyFields = ['copy_id', 'medium_id', 'kind', 'contents', 'created_at', 'restorable_until', 'parent_ids', 'expired_verified_at', 'verification_ref'];
        foreach ($record['copies'] as $copy) {
            if (! is_array($copy) || count($copy) !== count($copyFields) || array_diff(array_keys($copy), $copyFields)
                || ! is_string($copy['copy_id'] ?? null) || ! Str::isUuid($copy['copy_id']) || isset($copies[$copy['copy_id']])
                || ! $this->opaque($copy['medium_id'] ?? null)
                || ! in_array($copy['kind'] ?? null, ['application', 'provider', 'deployment', 'investigation', 'usb'], true)
                || ! is_array($copy['contents'] ?? null) || ! array_is_list($copy['contents']) || $copy['contents'] === []
                || count(array_unique($copy['contents'], SORT_REGULAR)) !== count($copy['contents'])
                || ! is_array($copy['parent_ids'] ?? null) || ! array_is_list($copy['parent_ids']) || count($copy['parent_ids']) > 100
                || count(array_unique($copy['parent_ids'], SORT_REGULAR)) !== count($copy['parent_ids'])) {
                throw new RuntimeException('Invalid backup copy metadata.');
            }
            foreach ($copy['contents'] as $content) {
                if (! in_array($content, ['database', 'images', 'vault', 'inquiry'], true)) {
                    throw new RuntimeException('Unknown backup content.');
                }
            }
            foreach ($copy['parent_ids'] as $parent) {
                if (! is_string($parent) || ! Str::isUuid($parent) || $parent === $copy['copy_id']) {
                    throw new RuntimeException('Invalid backup ancestry.');
                }
            }
            $created = $this->instant($copy['created_at'] ?? null);
            if ($created->greaterThan($inventoried)) {
                throw new RuntimeException('Copy is newer than its inventory.');
            }
            $until = $copy['restorable_until'] === null ? null : $this->instant($copy['restorable_until']);
            if ($until !== null && $until->lessThan($created)) {
                throw new RuntimeException('Copy deadline precedes creation.');
            }
            if ($copy['expired_verified_at'] !== null) {
                $verified = $this->instant($copy['expired_verified_at']);
                if ($until === null || $verified->lessThan($until) || $verified->greaterThan($inventoried) || ! $this->opaque($copy['verification_ref'])) {
                    throw new RuntimeException('Invalid expiry verification.');
                }
                $until = $verified;
            } elseif ($copy['verification_ref'] !== null) {
                throw new RuntimeException('Expiry verification time is required.');
            } else {
                $unverified++;
            }
            if ($until === null) {
                $unknown++;
            } elseif ($latest === null || $until->greaterThan($latest)) {
                $latest = $until;
            }
            $copies[$copy['copy_id']] = $copy;
        }
        $visited = [];
        $visiting = [];
        foreach (array_keys($copies) as $copyId) {
            $this->ancestry($copyId, $copies, $visited, $visiting);
        }
        $snapshot = $this->ledger->inspect($ledgerChecksum, includeOperations: true);
        $cleanupVerified = $snapshot['committed'] > 0;
        foreach ($snapshot['operations'] as $operation) {
            if ($operation['state'] === 'committed' && ($operation['cleanup']['state'] ?? null) !== 'complete') {
                $cleanupVerified = false;
            }
        }
        $blockers = $cleanupVerified ? [] : ['cleanup_completion_not_verified'];
        if (! $record['coverage_complete'] || $copies === []) {
            $blockers[] = 'inventory_incomplete';
        }
        $stale = $inventoried->lessThan(now()->utc()->subDay());
        if ($stale) {
            $blockers[] = 'inventory_stale';
        }
        if ($unknown > 0) {
            $blockers[] = 'copy_deadline_unknown';
        }
        if ($unverified > 0) {
            $blockers[] = 'copy_expiry_not_verified';
        }
        if ($snapshot['unresolved'] > 0) {
            $blockers[] = 'unresolved_intent';
        }
        foreach ($snapshot['operations'] as $operation) {
            if (array_filter($operation['holds'], fn ($hold) => ! $hold['released'])) {
                $blockers[] = 'active_hold';
                break;
            }
        }
        $minimum = $record['coverage_complete'] && ! $stale && $unknown === 0 && $latest !== null ? $latest->addDays(30) : null;
        if ($minimum !== null && $minimum->isFuture()) {
            $blockers[] = 'retention_period_not_elapsed';
        }

        return ['inventory_id' => $id, 'copies' => count($copies), 'unknown_deadlines' => $unknown,
            'last_restorable_until' => $minimum === null ? null : $latest->format('Y-m-d\TH:i:s\Z'),
            'minimum_ledger_retention_until' => $minimum?->format('Y-m-d\TH:i:s\Z'),
            'cleanup_verified' => $cleanupVerified, 'ledger_expiry_authorized' => false, 'blockers' => $blockers];
    }

    /** @return array<string,mixed> */
    public function synchronization(string $id, string $checksum, string $ledgerChecksum, string $sourceUrl, string $environment): array
    {
        $record = $this->record($id, 'ledger-sync', $checksum, $sourceUrl, $environment);
        $fields = ['format_version', 'sync_id', 'source_url', 'environment', 'captured_at', 'received_at', 'ledger_sha256'];
        $bundle = $record['format_version'] === 2;
        if ($bundle) {
            $fields = [...$fields, 'destination_medium_id', 'image_manifests'];
        }
        if (count($record) !== count($fields) || array_diff(array_keys($record), $fields) || ($record['sync_id'] ?? null) !== $id
            || ($record['ledger_sha256'] ?? null) !== $ledgerChecksum) {
            throw new RuntimeException('Invalid ledger synchronization receipt.');
        }
        $captured = $this->instant($record['captured_at'] ?? null);
        $received = $this->instant($record['received_at'] ?? null);
        if ($received->lessThan($captured) || $received->isFuture()) {
            throw new RuntimeException('Invalid ledger synchronization times.');
        }
        $snapshot = $this->ledger->inspect($ledgerChecksum, includeOperations: true, consume: function (array $snapshot) use ($id, $record, $bundle): array {
            $required = [];
            foreach ($snapshot['operations'] as $receipt => $operation) {
                if ($operation['image_manifest_sha256'] !== null) {
                    $required[$receipt] = $operation['image_manifest_sha256'];
                }
            }
            $verified = 0;
            if ($bundle) {
                if (! $this->opaque($record['destination_medium_id'] ?? null) || ! is_array($record['image_manifests'] ?? null)
                    || ! array_is_list($record['image_manifests']) || count($record['image_manifests']) > 1000
                    || ! hash_equals($snapshot['sha256'], $this->files->copiedLedgerHash($id))) {
                    throw new RuntimeException('Copied evidence bundle identity differs.');
                }
                $declared = [];
                foreach ($record['image_manifests'] as $manifest) {
                    if (! is_array($manifest) || count($manifest) !== 2 || array_diff(array_keys($manifest), ['receipt_id', 'sha256'])
                        || ! is_string($manifest['receipt_id'] ?? null) || ! Str::isUuid($manifest['receipt_id'])
                        || isset($declared[$manifest['receipt_id']]) || ! is_string($manifest['sha256'] ?? null)
                        || ! preg_match('/\A[a-f0-9]{64}\z/', $manifest['sha256'])) {
                        throw new RuntimeException('Invalid copied image evidence list.');
                    }
                    $declared[$manifest['receipt_id']] = $manifest['sha256'];
                }
                ksort($declared);
                ksort($required);
                if ($declared !== $required) {
                    throw new RuntimeException('Copied image evidence coverage is incomplete or contradictory.');
                }
                $names = ['account-lifecycle.jsonl'];
                foreach ($required as $receipt => $hash) {
                    $this->images->read($receipt, $snapshot['operations'][$receipt], $id);
                    $names[] = $receipt.'.images.json';
                    $verified++;
                }
                sort($names);
                if ($names !== $this->files->copiedEvidenceFiles($id)) {
                    throw new RuntimeException('Copied evidence contains unlisted files.');
                }
            }

            return [...$snapshot, 'required_image_manifests' => count($required), 'verified_image_manifests' => $verified];
        });
        $blockers = [];
        if ($captured->lessThanOrEqualTo(now()->utc()->subMinutes(15))) {
            $blockers[] = 'sync_stale';
        }
        if ($snapshot['unresolved'] > 0) {
            $blockers[] = 'unresolved_intent';
        }
        if (! $bundle && $snapshot['required_image_manifests'] > 0) {
            $blockers[] = 'image_evidence_not_verified';
        }

        return ['sync_id' => $id, 'snapshot_matches' => true, 'captured_at' => $record['captured_at'], 'received_at' => $record['received_at'],
            'fresh' => ! in_array('sync_stale', $blockers, true), 'blockers' => $blockers, 'remote_current_state_verified' => false,
            'bundle_verified' => $bundle, 'verified_image_manifests' => $snapshot['verified_image_manifests'],
            'destination_medium_id' => $bundle ? $record['destination_medium_id'] : null, 'physical_medium_verified' => false];
    }

    /** @return array<string,mixed> */
    private function record(string $id, string $kind, string $checksum, string $sourceUrl, string $environment): array
    {
        if (! config('famie.lifecycle_operator_enabled') || ! app()->environment(['local', 'testing'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', $checksum) || $sourceUrl === '' || $environment === '') {
            throw new RuntimeException('Local backup assessment unavailable.');
        }
        $loaded = $this->files->readControlRecord($id, $kind);
        $record = $loaded['data'];
        if (! hash_equals($checksum, $loaded['sha256']) || ! in_array($record['format_version'] ?? null, $kind === 'ledger-sync' ? [1, 2] : [1], true)
            || ($record['source_url'] ?? null) !== $sourceUrl || ($record['environment'] ?? null) !== $environment) {
            throw new RuntimeException('Backup assessment identity mismatch.');
        }

        return $record;
    }

    private function opaque(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $value) === 1;
    }

    private function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value)) {
            throw new RuntimeException('Invalid backup timestamp.');
        }
        $time = CarbonImmutable::parse($value)->utc();
        if ($time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('Invalid backup date.');
        }

        return $time;
    }

    /**
     * @param  array<string,array<string,mixed>>  $copies
     * @param  array<string,bool>  $visited
     * @param  array<string,bool>  $visiting
     */
    private function ancestry(string $id, array $copies, array &$visited, array &$visiting): void
    {
        if (isset($visiting[$id]) || ! isset($copies[$id])) {
            throw new RuntimeException('Cyclic or missing backup ancestry.');
        }
        if (isset($visited[$id])) {
            return;
        }
        $visiting[$id] = true;
        foreach ($copies[$id]['parent_ids'] as $parent) {
            if (! isset($copies[$parent]) || strcmp($copies[$parent]['created_at'], $copies[$id]['created_at']) > 0) {
                throw new RuntimeException('Invalid backup parent chronology.');
            }
            $this->ancestry($parent, $copies, $visited, $visiting);
        }
        unset($visiting[$id]);
        $visited[$id] = true;
    }
}
