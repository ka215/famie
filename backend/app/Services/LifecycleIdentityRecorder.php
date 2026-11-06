<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LifecycleIdentityRecorder
{
    public function __construct(private LifecycleLedgerInspector $inspector, private LifecycleLedger $ledger, private LifecycleAudit $audit) {}

    /** @return array<string,mixed> */
    public function record(string $receipt, string $checksum, string $operator, bool $apply = false): array
    {
        if (! config('famie.lifecycle_operator_enabled') || ! Str::isUuid($receipt)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $operator)) {
            throw new RuntimeException('Recovery identity recording unavailable.');
        }
        if ($apply) {
            $this->audit->record('recovery.identity', 'started', $operator, $receipt);
        }
        try {
            $result = DB::transaction(function () use ($receipt, $checksum, $apply): array {
                $operation = AccountLifecycleOperation::query()->whereKey($receipt)->lockForUpdate()->firstOrFail();
                $snapshot = $this->inspector->inspect($checksum, $receipt);
                $source = $snapshot['operation'];
                if ($snapshot['unresolved'] !== 0 || $source === null || $source['state'] !== 'committed' || $operation->state !== 'committed'
                    || str_starts_with((string) $operation->last_error_code, 'restore_reapply_') || $operation->group_id !== $source['group_id']
                    || $operation->kind !== $source['kind'] || $operation->committed_at?->utc()->toIso8601String() !== $source['committed_at']) {
                    throw new RuntimeException('Authoritative source receipt is required.');
                }
                $identity = LifecycleRecoveryIdentity::capture($operation);
                if (! LifecycleRecoveryIdentity::valid($identity, $source['subject_ids'], $operation->kind)
                    || ($source['recovery_identity'] !== null && $source['recovery_identity'] !== $identity)) {
                    throw new RuntimeException('Source identity mapping is ambiguous.');
                }
                $present = $source['recovery_identity'] !== null;
                if ($apply && ! $present) {
                    $this->ledger->append($operation, 'recovery.identity', ['recovery_identity' => $identity]);
                }

                return ['receipt_id' => $receipt, 'subjects' => count($identity['subjects']), 'identity_present' => $present, 'appended' => $apply && ! $present];
            });
            if ($apply) {
                $this->audit->record('recovery.identity', 'success', $operator, $receipt);
            }

            return $result;
        } catch (Throwable) {
            if ($apply) {
                $this->audit->record('recovery.identity', 'failure', $operator, $receipt);
            }
            throw new RuntimeException('Recovery identity recording failed. Preserve the source evidence.');
        }
    }
}
