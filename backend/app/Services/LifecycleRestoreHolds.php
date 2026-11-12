<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LifecycleRestoreHolds
{
    /**
     * @param  array<int,array<string,mixed>>  $source
     * @return array{held:bool,missing:array<int,array<string,mixed>>,holds:int}
     */
    public function inspect(AccountLifecycleOperation $operation, array $source, bool $allowMissing = false): array
    {
        $rows = DB::table('account_lifecycle_holds')->where('operation_id', $operation->id)->get()->keyBy('id');
        foreach ($rows as $id => $row) {
            $hold = $source[$id] ?? null;
            if ($hold === null || $hold['reference'] !== $row->reference || $hold['scope'] !== $row->scope
                || $hold['review_at'] !== CarbonImmutable::parse($row->review_at)->utc()->format('Y-m-d\TH:i:s\Z')
                || $hold['released'] !== ($row->released_at !== null)) {
                throw new RuntimeException('Restored hold state requires manual reconciliation.');
            }
        }
        $missing = [];
        foreach ($source as $id => $hold) {
            if ($rows->has($id)) {
                continue;
            }
            if (! $allowMissing || DB::table('account_lifecycle_holds')->where('id', $id)->exists()) {
                throw new RuntimeException('Restored hold identity is missing or belongs to another operation.');
            }
            foreach ([$hold['started_recorded_at'], $hold['released_recorded_at']] as $time) {
                if ($time !== null && CarbonImmutable::parse($time)->isFuture()) {
                    throw new RuntimeException('Hold evidence is in the future.');
                }
            }
            $missing[$id] = $hold;
        }

        return ['held' => count(array_filter($source, fn ($hold) => ! $hold['released'])) > 0, 'missing' => $missing, 'holds' => count($source)];
    }

    /** @param array<int,array<string,mixed>> $missing */
    public function insert(AccountLifecycleOperation $operation, array $missing): void
    {
        foreach ($missing as $id => $hold) {
            DB::table('account_lifecycle_holds')->insert(['id' => $id, 'operation_id' => $operation->id, 'scope' => $hold['scope'],
                'reference' => $hold['reference'], 'started_at' => CarbonImmutable::parse($hold['started_recorded_at']),
                'review_at' => CarbonImmutable::parse($hold['review_at']),
                'released_at' => $hold['released_recorded_at'] === null ? null : CarbonImmutable::parse($hold['released_recorded_at']),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Call after releasing the ledger lock, inside the same database transaction as inserts. */
    public function advanceSequence(int $minimum): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $current = DB::table('sqlite_sequence')->where('name', 'account_lifecycle_holds')->value('seq');
            $next = max($minimum, (int) $current, (int) DB::table('account_lifecycle_holds')->max('id'));
            if ($next > 0) {
                DB::table('sqlite_sequence')->updateOrInsert(['name' => 'account_lifecycle_holds'], ['seq' => $next]);
            }

            return;
        }
        DB::statement('LOCK TABLE account_lifecycle_holds IN ACCESS EXCLUSIVE MODE');
        $sequence = DB::selectOne("select last_value from pg_sequences where schemaname = current_schema() and sequencename = 'account_lifecycle_holds_id_seq'");
        if ($sequence === null) {
            throw new RuntimeException('Restore hold sequence is unavailable.');
        }
        $next = max(1, $minimum, (int) $sequence->last_value, (int) DB::table('account_lifecycle_holds')->max('id'));
        DB::select("select setval(pg_get_serial_sequence('account_lifecycle_holds', 'id')::regclass, ?, true)", [$next]);
    }
}
