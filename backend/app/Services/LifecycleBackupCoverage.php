<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

class LifecycleBackupCoverage
{
    public function __construct(private LifecycleOperatorFiles $files, private LifecycleBackupAssessment $backups) {}

    /** @return array<string,mixed> */
    public function check(string $id, string $checksum, string $catalogueChecksum, string $ledgerChecksum, string $sourceUrl, string $environment): array
    {
        if (! config('famie.lifecycle_operator_enabled') || ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Local backup coverage assessment unavailable.');
        }
        $coverage = $this->load($id, 'backup-coverage', $checksum, $sourceUrl, $environment);
        $this->shape($coverage, ['format_version', 'coverage_id', 'source_url', 'environment', 'captured_at', 'inventory_id', 'inventory_sha256', 'catalogue_id', 'catalogue_sha256', 'syncs']);
        if (($coverage['coverage_id'] ?? null) !== $id || ($coverage['catalogue_sha256'] ?? null) !== $catalogueChecksum
            || ! is_array($coverage['syncs']) || ! array_is_list($coverage['syncs']) || count($coverage['syncs']) > 100) {
            throw new RuntimeException('Invalid backup coverage references.');
        }
        $captured = $this->instant($coverage['captured_at']);
        $catalogue = $this->load($coverage['catalogue_id'], 'backup-catalogue', $catalogueChecksum, $sourceUrl, $environment);
        $this->shape($catalogue, ['format_version', 'catalogue_id', 'source_url', 'environment', 'captured_at', 'coverage_complete', 'media', 'copies']);
        if ($catalogue['catalogue_id'] !== $coverage['catalogue_id'] || ! is_bool($catalogue['coverage_complete'])
            || ! is_array($catalogue['media']) || ! array_is_list($catalogue['media']) || count($catalogue['media']) > 100) {
            throw new RuntimeException('Invalid independent backup catalogue.');
        }
        $catalogued = $this->instant($catalogue['captured_at']);
        $inventory = $this->load($coverage['inventory_id'], 'backup-inventory', $coverage['inventory_sha256'], $sourceUrl, $environment);
        $assessment = $this->backups->inventory($coverage['inventory_id'], $coverage['inventory_sha256'], $ledgerChecksum, $sourceUrl, $environment);
        $inventoried = $this->instant($inventory['inventoried_at']);
        if ($catalogued->isFuture() || $captured->isFuture() || $captured->lessThan($catalogued) || $captured->lessThan($inventoried)) {
            throw new RuntimeException('Invalid backup coverage chronology.');
        }
        $scopes = [];
        foreach ($catalogue['media'] as $medium) {
            if (! is_array($medium)) {
                throw new RuntimeException('Invalid catalogue medium.');
            }
            $this->shape($medium, ['medium_id', 'kind']);
            $key = $this->mediumKey($medium);
            if (isset($scopes[$key])) {
                throw new RuntimeException('Duplicate catalogue medium.');
            }
            $scopes[$key] = true;
        }
        $expected = $this->copyMap($catalogue['copies']);
        $actual = $this->copyMap($inventory['copies']);
        $missing = count(array_diff_key($expected, $actual));
        $unexpected = count(array_diff_key($actual, $expected));
        $mismatches = 0;
        $providerCounts = [];
        foreach ($expected as $copyId => $copy) {
            if (! isset($scopes[$this->mediumKey($copy)]) || $this->instant($copy['created_at'])->greaterThan($catalogued)) {
                throw new RuntimeException('Catalogue copy falls outside its declared scope.');
            }
            if ($copy['kind'] === 'provider') {
                $providerCounts[$copy['medium_id']] = ($providerCounts[$copy['medium_id']] ?? 0) + 1;
            }
            if (isset($actual[$copyId]) && $copy !== $actual[$copyId]) {
                $mismatches++;
            }
        }
        $generationBlockers = [];
        if (! $catalogue['coverage_complete'] || $expected === [] || $scopes === [] || ! $inventory['coverage_complete']) {
            $generationBlockers[] = 'generation_catalogue_incomplete';
        }
        if ($missing > 0) {
            $generationBlockers[] = 'catalogued_generations_missing';
        }
        if ($unexpected > 0) {
            $generationBlockers[] = 'uncatalogued_generations_present';
        }
        if ($mismatches > 0) {
            $generationBlockers[] = 'generation_metadata_mismatch';
        }
        if ($catalogued->lessThan(now()->utc()->subDay()) || $captured->lessThan(now()->utc()->subDay()) || $inventoried->lessThan(now()->utc()->subDay())) {
            $generationBlockers[] = 'coverage_records_stale';
        }
        if ($inventoried->lessThan($catalogued)) {
            $generationBlockers[] = 'inventory_precedes_catalogue';
        }
        if ($providerCounts !== [] && max($providerCounts) > 15) {
            $generationBlockers[] = 'provider_generation_limit_exceeded';
        }
        foreach ($actual as $copy) {
            foreach ($copy['parent_ids'] as $parent) {
                if (array_diff($actual[$parent]['contents'], $copy['contents'])) {
                    $generationBlockers[] = 'nested_copy_contents_omitted';
                }
                if ($actual[$parent]['expired_verified_at'] !== null && strcmp($copy['created_at'], $actual[$parent]['expired_verified_at']) >= 0) {
                    $generationBlockers[] = 'expired_generation_reused_as_parent';
                }
            }
        }
        $blockers = [...$assessment['blockers'], ...$generationBlockers];
        $verifiedBundles = 0;
        $separateMedium = false;
        $seen = [];
        $serverMedia = [];
        foreach ($catalogue['media'] as $medium) {
            if (in_array($medium['kind'], ['application', 'provider', 'deployment'], true)) {
                $serverMedia[$medium['medium_id']] = true;
            }
        }
        foreach ($coverage['syncs'] as $sync) {
            if (! is_array($sync)) {
                throw new RuntimeException('Invalid evidence synchronization reference.');
            }
            $this->shape($sync, ['sync_id', 'sync_sha256']);
            if (! is_string($sync['sync_id']) || ! Str::isUuid($sync['sync_id']) || isset($seen[$sync['sync_id']])) {
                throw new RuntimeException('Duplicate or invalid synchronization reference.');
            }
            $seen[$sync['sync_id']] = true;
            $result = $this->backups->synchronization($sync['sync_id'], $sync['sync_sha256'], $ledgerChecksum, $sourceUrl, $environment);
            $blockers = [...$blockers, ...$result['blockers']];
            if ($result['bundle_verified'] && $result['blockers'] === []) {
                $verifiedBundles++;
                $separateMedium = $separateMedium || ! isset($serverMedia[$result['destination_medium_id']]);
            }
        }
        if ($verifiedBundles === 0) {
            $blockers[] = 'evidence_bundle_not_verified';
        }
        if (! $separateMedium) {
            $blockers[] = 'separate_evidence_medium_not_recorded';
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers);

        return ['coverage_id' => $id, 'assessment_only' => true, 'database_changed' => false,
            'catalogued_generations' => count($expected), 'inventoried_generations' => count($actual),
            'missing_generations' => $missing, 'unexpected_generations' => $unexpected, 'metadata_mismatches' => $mismatches,
            'catalogued_generations_verified' => $generationBlockers === [], 'verified_evidence_bundles' => $verifiedBundles,
            'separate_medium_recorded' => $separateMedium, 'physical_medium_verified' => false, 'remote_current_state_verified' => false,
            'archive_contents_verified' => false,
            'minimum_ledger_retention_until' => $generationBlockers === [] ? $assessment['minimum_ledger_retention_until'] : null,
            'ledger_expiry_authorized' => false, 'reopen_allowed' => false, 'blockers' => $blockers];
    }

    /** @return array<string,mixed> */
    private function load(string $id, string $kind, string $checksum, string $source, string $environment): array
    {
        if (! Str::isUuid($id) || ! preg_match('/\A[a-f0-9]{64}\z/', $checksum) || $source === '' || $environment === '') {
            throw new RuntimeException('Invalid trusted coverage reference.');
        }
        $loaded = $this->files->readControlRecord($id, $kind);
        if (! hash_equals($checksum, $loaded['sha256']) || ($loaded['data']['format_version'] ?? null) !== 1
            || ($loaded['data']['source_url'] ?? null) !== $source || ($loaded['data']['environment'] ?? null) !== $environment) {
            throw new RuntimeException('Coverage source identity differs.');
        }

        return $loaded['data'];
    }

    /** @return array<string,array<string,mixed>> */
    private function copyMap(mixed $copies): array
    {
        if (! is_array($copies) || ! array_is_list($copies) || count($copies) > 1000) {
            throw new RuntimeException('Invalid catalogue copy list.');
        }
        $mapped = [];
        foreach ($copies as $copy) {
            if (! is_array($copy)) {
                throw new RuntimeException('Invalid catalogue copy.');
            }
            $this->shape($copy, ['copy_id', 'medium_id', 'kind', 'contents', 'created_at', 'restorable_until', 'parent_ids', 'expired_verified_at', 'verification_ref']);
            if (! is_string($copy['copy_id']) || ! Str::isUuid($copy['copy_id']) || isset($mapped[$copy['copy_id']])
                || ! is_array($copy['contents']) || ! array_is_list($copy['contents']) || ! is_array($copy['parent_ids']) || ! array_is_list($copy['parent_ids'])) {
                throw new RuntimeException('Ambiguous catalogue generation.');
            }
            $this->mediumKey($copy);
            sort($copy['contents']);
            sort($copy['parent_ids']);
            ksort($copy);
            $mapped[$copy['copy_id']] = $copy;
        }

        return $mapped;
    }

    /** @param array<string,mixed> $medium */
    private function mediumKey(array $medium): string
    {
        if (! is_string($medium['medium_id']) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $medium['medium_id'])
            || ! in_array($medium['kind'], ['application', 'provider', 'deployment', 'investigation', 'usb'], true)) {
            throw new RuntimeException('Invalid catalogue medium identity.');
        }

        return $medium['medium_id'].'|'.$medium['kind'];
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  list<string>  $fields
     */
    private function shape(array $record, array $fields): void
    {
        if (count($record) !== count($fields) || array_diff(array_keys($record), $fields)) {
            throw new RuntimeException('Unexpected coverage record fields.');
        }
    }

    private function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value)) {
            throw new RuntimeException('Invalid coverage timestamp.');
        }
        $instant = CarbonImmutable::parse($value)->utc();
        if ($instant->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('Invalid coverage date.');
        }

        return $instant;
    }
}
