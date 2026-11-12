<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use App\Models\Group;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LifecycleOrphanImages
{
    public function __construct(private LifecycleRestoreReapply $restore, private LifecycleLedgerInspector $ledger,
        private LifecycleImageEvidence $images, private LifecycleRestoreHolds $holds, private LifecycleAudit $audit) {}

    /** @return array<string,mixed> */
    public function erase(string $restoreId, string $receipt, string $recordSha, string $ledgerSha, string $sourceUrl, string $environment, string $operator, bool $apply = false): array
    {
        $this->restore->reapply($restoreId, $receipt, $recordSha, $ledgerSha, $sourceUrl, $environment, $operator);
        $context = ['control_id' => $restoreId];
        if ($apply) {
            $this->audit->record('restore.orphans', 'started', $operator, $receipt, context: $context);
        }
        try {
            $result = DB::transaction(function () use ($receipt, $recordSha, $ledgerSha, $apply): array {
                $source = $this->ledger->inspect($ledgerSha, $receipt)['operation'];
                Group::query()->whereKey($source['group_id'])->lockForUpdate()->firstOrFail();
                $operation = AccountLifecycleOperation::query()->whereKey($receipt)->lockForUpdate()->firstOrFail();
                if ($operation->state !== 'committed' || ! in_array($operation->last_error_code, [
                    'restore_reapply_pending:'.$recordSha, 'restore_reapply_complete:'.$recordSha,
                ], true)) {
                    throw new RuntimeException('Apply restored account retirement before orphan image processing.');
                }

                return $this->ledger->inspect($ledgerSha, $receipt, consume: function (array $snapshot) use ($operation, $receipt, $apply): array {
                    $evidence = $snapshot['operation'];
                    if ($snapshot['unresolved'] !== 0 || $evidence === null || $evidence['state'] !== 'committed'
                        || $operation->group_id !== $evidence['group_id'] || $operation->kind !== $evidence['kind']
                        || $operation->committed_at?->utc()->toIso8601String() !== $evidence['committed_at']) {
                        throw new RuntimeException('Restored image receipt identity changed.');
                    }
                    $held = $this->holds->inspect($operation, $snapshot['holds'])['held'];
                    $entries = $this->images->read($receipt, $evidence);
                    $targets = [];
                    $referenced = 0;
                    $absent = 0;
                    foreach ($entries as $entry) {
                        if ($this->referenced($entry['path'])) {
                            $referenced++;

                            continue;
                        }
                        $fingerprint = $this->images->fingerprint($entry['path']);
                        if ($fingerprint === null) {
                            $absent++;

                            continue;
                        }
                        if ($entry['sha256'] === null || $fingerprint['bytes'] !== $entry['bytes'] || ! hash_equals($entry['sha256'], $fingerprint['sha256'])) {
                            throw new RuntimeException('Orphan image differs from independently preserved evidence.');
                        }
                        $targets[] = $entry;
                    }
                    $deleted = 0;
                    if ($apply && ! $held) {
                        foreach ($targets as $entry) {
                            $this->restore->isolatedTarget();
                            if ($this->referenced($entry['path']) || $operation->hasActiveHold()) {
                                throw new RuntimeException('Image ownership or hold changed before removal.');
                            }
                            if ($this->images->fingerprint($entry['path'], $entry['sha256'], true) !== null) {
                                $deleted++;
                            }
                        }
                    }

                    return ['receipt_id' => $receipt, 'applied' => $apply, 'held' => $held, 'verified_orphans' => count($targets),
                        'referenced' => $referenced, 'already_absent' => $absent, 'deleted' => $deleted,
                        'database_changed' => false, 'reopen_allowed' => false];
                });
            });
            if ($apply) {
                $this->audit->record('restore.orphans', 'success', $operator, $receipt, context: $context);
            }

            return $result;
        } catch (Throwable) {
            if ($apply) {
                $this->audit->record('restore.orphans', 'failure', $operator, $receipt, context: $context);
            }
            throw new RuntimeException('Orphan image reapplication failed; preserve evidence and keep the restored application offline.');
        }
    }

    private function referenced(string $path): bool
    {
        return DB::table('activity_images')->where('disk', 'local')->where('path', $path)->exists()
            || DB::table('activity_image_deletions')->where('disk', 'local')->where('path', $path)->exists();
    }
}
