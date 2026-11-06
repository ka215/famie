<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LifecycleHold
{
    public function __construct(private LifecycleLedgerInspector $inspector, private LifecycleLedger $ledger, private LifecycleAudit $audit) {}

    /** @return array{hold_id: int|null, scope: string, review_at: string, released: bool, applied: bool} */
    public function change(string $receipt, string $reference, string $reviewAt, string $operator, string $checksum, bool $release = false, bool $apply = false): array
    {
        if (! config('famie.lifecycle_operator_enabled') || ! Str::isUuid($receipt)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $reference)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $operator)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $reviewAt)) {
            throw new RuntimeException('Invalid hold request.');
        }
        $review = CarbonImmutable::parse($reviewAt);
        if ($review->format('Y-m-d\TH:i:s\Z') !== $reviewAt || (! $release && ($review->isPast() || $review->greaterThan(now()->utc()->addDays(31))))) {
            throw new RuntimeException('A valid review date within 31 days is required.');
        }
        $event = $release ? 'hold.released' : 'hold.started';
        if ($apply) {
            $this->audit->record($event, 'started', $operator, $receipt);
        }
        try {
            $result = DB::transaction(function () use ($receipt, $reference, $reviewAt, $operator, $checksum, $release, $apply, $event): array {
                $operation = AccountLifecycleOperation::query()->whereKey($receipt)->lockForUpdate()->firstOrFail();
                if (str_starts_with((string) $operation->last_error_code, 'restore_reapply_')) {
                    throw new RuntimeException('Restored holds require source reconciliation.');
                }
                $verified = $this->inspector->inspect($checksum, $receipt);
                $identity = $verified['operation'];
                if ($verified['unresolved'] !== 0 || $identity === null || $identity['state'] !== 'committed'
                    || $operation->state !== 'committed' || $operation->group_id !== $identity['group_id']
                    || $operation->kind !== $identity['kind'] || $operation->committed_at?->utc()->toIso8601String() !== $identity['committed_at']) {
                    throw new RuntimeException('Hold requires reconciled committed evidence.');
                }
                $rows = DB::table('account_lifecycle_holds')->where('operation_id', $receipt)->get();
                foreach ($rows as $row) {
                    $evidence = $verified['holds'][$row->id] ?? null;
                    if ($evidence === null || $evidence['released'] !== ($row->released_at !== null)
                        || $evidence['scope'] !== $row->scope || $evidence['reference'] !== $row->reference
                        || $evidence['review_at'] !== CarbonImmutable::parse($row->review_at)->utc()->format('Y-m-d\TH:i:s\Z')) {
                        throw new RuntimeException('Hold evidence requires manual reconciliation.');
                    }
                }
                if ($rows->count() !== count($verified['holds'])) {
                    throw new RuntimeException('Hold evidence missing from restored database.');
                }
                $hold = $rows->first(fn ($row) => $row->reference === $reference && $row->released_at === null);
                if (($release && ($hold === null || CarbonImmutable::parse($hold->review_at)->utc()->format('Y-m-d\TH:i:s\Z') !== $reviewAt))
                    || (! $release && $hold !== null)) {
                    throw new RuntimeException('Hold request conflicts with existing evidence.');
                }
                $id = $hold?->id;
                if ($apply) {
                    if (! $release) {
                        $id = DB::table('account_lifecycle_holds')->insertGetId(['operation_id' => $receipt, 'scope' => 'all', 'reference' => $reference,
                            'started_at' => now(), 'review_at' => CarbonImmutable::parse($reviewAt), 'released_at' => null, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    if ($release) {
                        $this->ledger->append($operation, $event, ['hold_id' => $id, 'reference' => $reference, 'scope' => 'all', 'review_at' => $reviewAt, 'operator' => $operator]);
                        $this->audit->record($event, 'success', $operator, $receipt);
                        DB::table('account_lifecycle_holds')->where('id', $id)->update(['released_at' => now(), 'updated_at' => now()]);
                    }
                }

                return ['hold_id' => $id, 'scope' => 'all', 'review_at' => $reviewAt, 'released' => $release, 'applied' => $apply];
            });
            if ($apply && ! $release) {
                // Commit protection before publishing evidence; a failed DB commit must not leave an unprotected external hold.
                $operation = AccountLifecycleOperation::query()->findOrFail($receipt);
                $this->ledger->append($operation, $event, ['hold_id' => $result['hold_id'], 'reference' => $reference, 'scope' => 'all', 'review_at' => $reviewAt, 'operator' => $operator]);
                $this->audit->record($event, 'success', $operator, $receipt);
            }

            return $result;
        } catch (Throwable) {
            if ($apply) {
                $this->audit->record($event, 'failure', $operator, $receipt);
            }
            throw new RuntimeException('Hold operation failed. Inspect private evidence before retrying.');
        }
    }
}
