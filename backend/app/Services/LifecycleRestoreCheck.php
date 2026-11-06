<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LifecycleRestoreCheck
{
    public function __construct(private LifecycleLedgerInspector $ledger) {}

    /** @return array{assessment_only:bool,database_changed:bool,reopen_allowed:bool,ledger_sha256:string,checked:int,findings:array<string,int>} */
    public function check(string $checksum): array
    {
        if (! config('famie.lifecycle_operator_enabled') || ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Restore assessment belongs in an isolated local environment.');
        }

        return $this->ledger->inspect($checksum, includeOperations: true, consume: function (array $snapshot) use ($checksum): array {
            $findings = [];
            $add = function (string $category, int $count = 1) use (&$findings): void {
                if ($count > 0) {
                    $findings[$category] = ($findings[$category] ?? 0) + $count;
                }
            };
            $add('unresolved_intent', $snapshot['unresolved']);
            foreach ($snapshot['operations'] as $receipt => $evidence) {
                $operation = AccountLifecycleOperation::query()->find($receipt);
                if ($evidence['state'] !== 'committed') {
                    if ($operation?->state === 'committed') {
                        $add('unconfirmed_database_commit');
                    }

                    continue;
                }
                if ($operation === null) {
                    $add('missing_committed_operation');

                    continue;
                }
                $committed = CarbonImmutable::parse($evidence['committed_at']);
                if ($operation->state !== 'committed' || $operation->group_id !== $evidence['group_id'] || $operation->kind !== $evidence['kind']
                    || $operation->committed_at?->utc()->toIso8601String() !== $evidence['committed_at']
                    || ! $operation->retention_until?->equalTo($committed->addDays(30))) {
                    $add('operation_identity_or_deadline_mismatch');

                    continue;
                }
                $holds = DB::table('account_lifecycle_holds')->where('operation_id', $receipt)->get();
                $holdMismatch = $holds->count() !== count($evidence['holds']);
                foreach ($holds as $hold) {
                    $external = $evidence['holds'][$hold->id] ?? null;
                    if ($external === null || $external['scope'] !== $hold->scope || $external['reference'] !== $hold->reference
                        || $external['released'] !== ($hold->released_at !== null)
                        || $external['review_at'] !== CarbonImmutable::parse($hold->review_at)->utc()->format('Y-m-d\TH:i:s\Z')) {
                        $holdMismatch = true;
                    }
                }
                if ($holdMismatch) {
                    $add('hold_evidence_mismatch');
                }
                $held = $holdMismatch || $holds->contains(fn ($hold) => $hold->released_at === null)
                    || count(array_filter($evidence['holds'], fn ($hold) => ! $hold['released'])) > 0;
                $subjects = $operation->subjects()->get();
                $expectedIds = $evidence['subject_ids'];
                sort($expectedIds);
                $actualIds = $subjects->pluck('user_id')->sort()->values()->all();
                if ($actualIds !== $expectedIds) {
                    $add('subject_evidence_mismatch');

                    continue;
                }
                foreach ($subjects as $subject) {
                    $user = DB::table('users')->where('id', $subject->user_id)->first();
                    $member = DB::table('group_members')->where('id', $subject->membership_id)->first();
                    $username = 'deleted:'.$subject->user_id.':'.substr(str_replace('-', '', $receipt), 0, 8);
                    if ($user === null || $user->deleted_at === null || $user->lifecycle_operation_id !== $receipt
                        || $user->username !== $username || $user->display_name !== 'Deleted member' || $user->email !== null
                        || $user->email_verified_at !== null || $user->remember_token !== null || $user->pending_email !== null
                        || $user->email_verification_token !== null || $user->email_verification_expires_at !== null) {
                        $add('account_mask_required');
                    }
                    if ($member === null || $member->user_id !== $subject->user_id || $member->group_id !== $evidence['group_id']
                        || $member->deleted_at === null || $member->status !== 'inactive' || $member->lifecycle_operation_id !== $receipt) {
                        $add('membership_retirement_required');
                    }
                }
                $add('token_revocation_required', DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $expectedIds)->count());
                $add('session_revocation_required', DB::table('sessions')->whereIn('user_id', $expectedIds)->count());
                if ($evidence['kind'] === 'group_withdraw') {
                    $group = DB::table('groups')->where('id', $evidence['group_id'])->first();
                    if ($group === null || $group->name !== 'Withdrawn family' || $group->withdrawn_at === null || $group->lifecycle_operation_id !== $receipt) {
                        $add('family_retirement_required');
                    }
                }
                if (! $held) {
                    $logs = DB::table('activity_logs')->where('group_id', $evidence['group_id'])->whereIn('user_id', $expectedIds);
                    $add('content_erasure_required', (clone $logs)->where(function ($query) use ($receipt): void {
                        $query->where('content', '!=', '')->orWhereNotNull('note')->orWhereNull('deleted_at')->orWhereNull('content_erased_at')
                            ->orWhereNull('lifecycle_operation_id')->orWhere('lifecycle_operation_id', '!=', $receipt);
                    })->count());
                    $add('image_retirement_required', DB::table('activity_images')->whereIn('activity_log_id', (clone $logs)->select('id'))->whereNull('retired_at')->count());
                    $add('image_file_deletion_pending', DB::table('activity_image_deletions')->where('operation_id', $receipt)->count());
                    if ($operation->retention_until->lessThanOrEqualTo(now())) {
                        $add('expired_credential_present', DB::table('account_lifecycle_credentials')->where('operation_id', $receipt)->count());
                    }
                }
            }
            $add('database_commit_missing_from_ledger', AccountLifecycleOperation::query()->where('state', 'committed')->whereNotIn('id', array_keys($snapshot['operations']))->count());
            ksort($findings);

            return ['assessment_only' => true, 'database_changed' => false, 'reopen_allowed' => false, 'ledger_sha256' => $checksum,
                'checked' => $snapshot['committed'], 'findings' => $findings];
        });
    }
}
