<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LifecycleCopyExpiry
{
    public function __construct(private LifecycleOperatorFiles $files, private LifecycleLedgerInspector $ledger, private LifecycleAudit $audit) {}

    /**
     * @param  array{package_id:string,package_sha256:string,ledger_sha256:string,source_url:string,environment:string}  $expected
     * @return array{package_id:string,retention_until:string,expired:bool,held:bool,deleted:bool}
     */
    public function expire(array $expected, string $operator, bool $incoming, bool $apply = false): array
    {
        if (! config('famie.lifecycle_operator_enabled') || ($incoming && ! app()->environment(['local', 'testing']))
            || count($expected) !== 5 || array_diff(array_keys($expected), ['package_id', 'package_sha256', 'ledger_sha256', 'source_url', 'environment'])) {
            throw new RuntimeException('Copy expiry unavailable.');
        }
        foreach ($expected as $value) {
            if (! is_string($value) || $value === '') {
                throw new RuntimeException('Invalid copy context.');
            }
        }
        if (! Str::isUuid($expected['package_id']) || ! preg_match('/\A[a-f0-9]{64}\z/', $expected['package_sha256'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', $expected['ledger_sha256'])
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $operator)) {
            throw new RuntimeException('Independent copy references are required.');
        }
        $loaded = $this->files->readPackage($expected['package_id'], $incoming);
        $package = $loaded['data'];
        if (! hash_equals($expected['package_sha256'], $loaded['sha256']) || count($package) !== 14
            || array_diff(array_keys($package), ['format_version', 'package_id', 'receipt_id', 'source_url', 'environment', 'key_id', 'group_id', 'kind', 'committed_at', 'retention_until', 'confirmation_code_hash', 'ledger_sha256', 'vault_sha256', 'vault'])
            || ($package['format_version'] ?? null) !== 1 || ($package['package_id'] ?? null) !== $expected['package_id']
            || ($package['source_url'] ?? null) !== $expected['source_url'] || ($package['environment'] ?? null) !== $expected['environment']
            || ! is_string($package['receipt_id'] ?? null) || ! Str::isUuid($package['receipt_id'])) {
            throw new RuntimeException('Copy identity mismatch.');
        }
        $committed = $this->instant($package['committed_at'] ?? null);
        $until = $this->instant($package['retention_until'] ?? null);
        if ($committed->isFuture() || ! $committed->addDays(30)->equalTo($until)) {
            throw new RuntimeException('Copy deadline is not the original deadline.');
        }
        $receipt = $package['receipt_id'];
        $inspect = function (?AccountLifecycleOperation $operation = null) use ($expected, $package, $until, $receipt, $operator, $incoming, $apply): array {
            return $this->ledger->inspect($expected['ledger_sha256'], $receipt, consume: function (array $verified) use ($expected, $package, $until, $receipt, $operator, $incoming, $apply, $operation): array {
                $evidence = $verified['operation'];
                if ($verified['unresolved'] !== 0 || $evidence === null || $evidence['state'] !== 'committed'
                    || $evidence['group_id'] !== $package['group_id'] || $evidence['kind'] !== $package['kind']
                    || $evidence['committed_at'] !== $package['committed_at'] || $evidence['vault_sha256'] !== $package['vault_sha256']) {
                    throw new RuntimeException('Copy requires reconciled ledger evidence.');
                }
                $held = count(array_filter($verified['holds'], fn ($hold) => ! $hold['released'])) > 0;
                if ($operation !== null) {
                    if ($operation->state !== 'committed' || $operation->group_id !== $package['group_id'] || $operation->kind !== $package['kind']
                        || $operation->committed_at?->utc()->toIso8601String() !== $package['committed_at']
                        || $operation->retention_until?->utc()->toIso8601String() !== $package['retention_until']) {
                        throw new RuntimeException('Source copy does not match the database.');
                    }
                    $held = $held || $operation->hasActiveHold();
                }
                $expired = $until->lessThanOrEqualTo(now());
                if ($apply) {
                    if (! $expired || $held) {
                        throw new RuntimeException('Copy is retained or held.');
                    }
                    $context = ['package_id' => $expected['package_id'], 'copy_location' => $incoming ? 'operator' : 'export'];
                    $this->audit->record('copy.expire', 'started', $operator, $receipt, null, $context);
                    try {
                        $this->files->deletePackage($expected['package_id'], $expected['package_sha256'], $incoming);
                        $this->audit->record('copy.expire', 'success', $operator, $receipt, null, $context);
                    } catch (Throwable) {
                        $this->audit->record('copy.expire', 'failure', $operator, $receipt, null, $context);
                        throw new RuntimeException('Copy expiry requires private evidence review.');
                    }
                }

                return ['package_id' => $expected['package_id'], 'retention_until' => $package['retention_until'], 'expired' => $expired, 'held' => $held, 'deleted' => $apply];
            });
        };
        if ($incoming) {
            return $inspect();
        }
        if ($expected['source_url'] !== config('app.url') || $expected['environment'] !== app()->environment()) {
            throw new RuntimeException('Source environment mismatch.');
        }

        return DB::transaction(fn () => $inspect(AccountLifecycleOperation::query()->whereKey($receipt)->lockForUpdate()->firstOrFail()));
    }

    private function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)\z/', $value)) {
            throw new RuntimeException('Invalid copy timestamp.');
        }
        $time = CarbonImmutable::parse($value)->utc();
        if ($time->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            throw new RuntimeException('Invalid copy date.');
        }

        return $time;
    }
}
