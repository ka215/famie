<?php

namespace App\Services;

use App\Models\AccountLifecycleCredential;
use App\Models\AccountLifecycleOperation;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\OperationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountLifecycleService
{
    public function __construct(
        private AccountEmailService $emails,
        private LifecycleVault $vault,
        private LifecycleLedger $ledger,
        private AccountLifecycleCleanup $cleanup,
    ) {}

    /** @return array{operation: AccountLifecycleOperation, code: string} */
    public function prepare(User $actor, Group $group, string $kind, ?int $membershipId): array
    {
        return DB::transaction(function () use ($actor, $group, $kind, $membershipId): array {
            $lockedGroup = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedGroup->withdrawn_at !== null, 409, __('messages.lifecycle_group_withdrawn'));
            abort_unless($lockedGroup->isRepresentative($actor->id), 403, __('messages.lifecycle_representative_only'));

            $target = null;
            if ($kind === AccountLifecycleOperation::KIND_MEMBER_DELETE) {
                $target = GroupMember::query()->where('group_id', $group->id)->whereKey($membershipId)->lockForUpdate()->firstOrFail();
                abort_if($lockedGroup->isRepresentative($target->user_id), 403, __('messages.representative_protected'));
                abort_if($target->status !== GroupMember::STATUS_INACTIVE, 409, __('messages.lifecycle_member_must_be_inactive'));
            } elseif ($kind !== AccountLifecycleOperation::KIND_GROUP_WITHDRAW) {
                throw ValidationException::withMessages(['kind' => [__('messages.lifecycle_kind_invalid')]]);
            }

            $pending = AccountLifecycleOperation::query()->where('group_id', $group->id)
                ->where('state', AccountLifecycleOperation::STATE_PREPARED)->where('prepared_expires_at', '>', now())
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($pending as $previous) {
                abort_unless($previous->actor_user_id === $actor->id, 409, __('messages.lifecycle_already_prepared', ['minutes' => config('famie.lifecycle_prepare_minutes')]));
                $this->abortPrepared($previous);
            }

            $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $operation = AccountLifecycleOperation::query()->create([
                'id' => (string) Str::uuid(),
                'actor_user_id' => $actor->id,
                'group_id' => $group->id,
                'target_membership_id' => $target?->id,
                'kind' => $kind,
                'state' => AccountLifecycleOperation::STATE_PREPARED,
                'cleanup_state' => 'pending',
                'prepared_expires_at' => now()->addMinutes(config('famie.lifecycle_prepare_minutes')),
            ]);
            $subjects = $kind === AccountLifecycleOperation::KIND_GROUP_WITHDRAW
                ? GroupMember::query()->where('group_id', $group->id)->orderBy('id')->get()
                : collect([$target]);
            foreach ($subjects as $subject) {
                $operation->subjects()->create(['user_id' => $subject->user_id, 'membership_id' => $subject->id]);
            }
            $operation->credential()->create([
                'code_hash' => hash('sha256', $code),
                'expires_at' => $operation->prepared_expires_at,
            ]);

            return ['operation' => $operation, 'code' => $code];
        });
    }

    public function commit(User $actor, Group $group, string $operationId, string $password, string $code): AccountLifecycleOperation
    {
        $vaultWritten = false;
        $intentWritten = false;
        try {
            $operation = DB::transaction(function () use ($actor, $group, $operationId, $password, $code, &$vaultWritten, &$intentWritten): AccountLifecycleOperation {
                $lockedGroup = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
                $operation = AccountLifecycleOperation::query()->where('group_id', $group->id)->whereKey($operationId)->lockForUpdate()->firstOrFail();
                abort_unless($lockedGroup->isRepresentative($actor->id) && $operation->actor_user_id === $actor->id, 403, __('messages.lifecycle_representative_only'));
                $alreadyCommitted = $operation->state === AccountLifecycleOperation::STATE_COMMITTED;
                if (! $alreadyCommitted) {
                    abort_unless($operation->state === AccountLifecycleOperation::STATE_PREPARED && $operation->prepared_expires_at->isFuture(), 409, __('messages.lifecycle_prepare_expired'));
                    abort_if($lockedGroup->withdrawn_at !== null, 409, __('messages.lifecycle_group_withdrawn'));
                }
                if (! Hash::check($password, User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail()->password)) {
                    throw ValidationException::withMessages(['current_password' => [__('messages.password_incorrect')]]);
                }
                $credential = AccountLifecycleCredential::query()->whereKey($operation->id)->lockForUpdate()->first();
                abort_unless($credential && $credential->expires_at->isFuture(), 409, __('messages.lifecycle_prepare_expired'));
                if (! hash_equals($credential->code_hash, hash('sha256', $code))) {
                    throw ValidationException::withMessages(['confirmation_code' => [__('messages.lifecycle_code_invalid')]]);
                }
                if ($alreadyCommitted) {
                    return $operation;
                }

                $subjectIds = $operation->subjects()->orderBy('membership_id')->pluck('membership_id');
                if ($operation->kind === AccountLifecycleOperation::KIND_GROUP_WITHDRAW) {
                    $currentIds = GroupMember::query()->where('group_id', $group->id)->orderBy('id')->lockForUpdate()->pluck('id');
                    abort_unless($currentIds->map(fn ($id): int => (int) $id)->all() === $subjectIds->map(fn ($id): int => (int) $id)->all(), 409, __('messages.lifecycle_subject_changed'));
                }
                $subjects = GroupMember::withTrashed()->whereIn('id', $subjectIds)->orderBy('id')->lockForUpdate()->get();
                if ($operation->kind === AccountLifecycleOperation::KIND_MEMBER_DELETE) {
                    abort_unless($subjects->count() === 1 && $subjects->first()->status === GroupMember::STATUS_INACTIVE && $subjects->first()->deleted_at === null, 409, __('messages.lifecycle_subject_changed'));
                }
                $users = User::withTrashed()->whereIn('id', $subjects->pluck('user_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                abort_if($users->count() !== $subjects->count() || $users->contains(fn (User $user): bool => $user->deleted_at !== null), 409, __('messages.lifecycle_subject_changed'));

                $committedAt = now();
                $operation->forceFill([
                    'committed_at' => $committedAt,
                    'retention_until' => $committedAt->copy()->addDays(config('famie.lifecycle_retention_days')),
                ]);
                $payload = [
                    'operation_id' => $operation->id,
                    'kind' => $operation->kind,
                    'group_id' => $group->id,
                    'group_name' => $operation->kind === AccountLifecycleOperation::KIND_GROUP_WITHDRAW ? $lockedGroup->name : null,
                    'committed_at' => $committedAt->utc()->toIso8601String(),
                    'subjects' => $subjects->map(fn (GroupMember $member): array => [
                        'user_id' => $member->user_id,
                        'membership_id' => $member->id,
                        'username' => $users[$member->user_id]->username,
                        'display_name' => $users[$member->user_id]->display_name,
                        'email' => $users[$member->user_id]->email,
                    ])->values()->all(),
                ];
                $vault = $this->vault->store($operation, $payload);
                $vaultWritten = true;
                $this->ledger->append($operation, 'intent', ['vault_sha256' => $vault['sha256'], 'subject_ids' => $subjects->pluck('user_id')->all()]);
                $intentWritten = true;

                foreach ($subjects as $membership) {
                    $user = $users[$membership->user_id];
                    $this->emails->clearChallenges($user);
                    $user->tokens()->delete();
                    $user->forceFill([
                        'username' => 'deleted:'.$user->id.':'.substr(str_replace('-', '', $operation->id), 0, 8),
                        'display_name' => 'Deleted member',
                        'email' => null,
                        'email_verified_at' => null,
                        'password' => Hash::make(Str::password(64)),
                        'remember_token' => null,
                        'lifecycle_operation_id' => $operation->id,
                    ])->save();
                    $membership->forceFill(['status' => GroupMember::STATUS_INACTIVE, 'lifecycle_operation_id' => $operation->id])->save();
                    $membership->delete();
                    $user->delete();
                }
                if ($operation->kind === AccountLifecycleOperation::KIND_GROUP_WITHDRAW) {
                    $lockedGroup->forceFill(['name' => 'Withdrawn family', 'withdrawn_at' => $committedAt, 'lifecycle_operation_id' => $operation->id])->save();
                }
                $operation->forceFill([
                    'state' => AccountLifecycleOperation::STATE_COMMITTED,
                    'cleanup_state' => 'processing',
                    'last_error_code' => 'ledger_commit_pending',
                ])->save();
                $credential->forceFill(['expires_at' => $operation->retention_until])->save();

                return $operation->fresh();
            });
        } catch (Throwable $exception) {
            if (! $vaultWritten && ! $intentWritten) {
                throw $exception;
            }
            $reconciled = null;
            try {
                $reconciled = DB::transaction(function () use ($group, $operationId, $vaultWritten): ?AccountLifecycleOperation {
                    Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
                    $current = AccountLifecycleOperation::query()->whereKey($operationId)->lockForUpdate()->firstOrFail();
                    if ($current->state === AccountLifecycleOperation::STATE_COMMITTED) {
                        return $current;
                    }
                    if ($current->state !== AccountLifecycleOperation::STATE_PREPARED || $current->hasActiveHold()) {
                        return null;
                    }
                    $this->ledger->append($current, 'aborted');
                    if ($vaultWritten) {
                        $this->vault->delete($operationId);
                    }

                    return null;
                });
            } catch (Throwable) {
                OperationLog::write('lifecycle.reconciliation_required', 'failure', ['operation_id' => $operationId], 'critical');
            }
            if ($reconciled === null) {
                throw $exception;
            }
            $operation = $reconciled;
        }

        try {
            $this->cleanup->process($operation);
        } catch (Throwable $exception) {
            OperationLog::write('lifecycle.cleanup_deferred', 'failure', ['operation_id' => $operation->id], 'critical');
            report($exception);
        }

        return $operation->fresh();
    }

    public function cancel(User $actor, Group $group, string $operationId): AccountLifecycleOperation
    {
        return DB::transaction(function () use ($actor, $group, $operationId): AccountLifecycleOperation {
            $lockedGroup = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $operation = AccountLifecycleOperation::query()->where('group_id', $group->id)->whereKey($operationId)->lockForUpdate()->firstOrFail();
            abort_unless($lockedGroup->isRepresentative($actor->id) && $operation->actor_user_id === $actor->id, 403, __('messages.lifecycle_representative_only'));
            if ($operation->state === AccountLifecycleOperation::STATE_ABORTED) {
                return $operation;
            }
            abort_unless($operation->state === AccountLifecycleOperation::STATE_PREPARED, 409, __('messages.lifecycle_cancel_committed'));
            $this->abortPrepared($operation);

            return $operation;
        });
    }

    private function abortPrepared(AccountLifecycleOperation $operation): void
    {
        abort_if($operation->hasActiveHold() || $operation->last_error_code !== null || $this->vault->exists($operation->id), 409, __('messages.lifecycle_cancel_unavailable'));
        $operation->forceFill(['state' => AccountLifecycleOperation::STATE_ABORTED, 'cleanup_state' => 'complete'])->save();
        $operation->credential()->delete();
    }

    public function status(string $operationId, string $code): ?AccountLifecycleOperation
    {
        $operation = AccountLifecycleOperation::query()->find($operationId);
        $credential = $operation?->credential;
        if (! $operation || ! $credential || $credential->expires_at->isPast() || ! hash_equals($credential->code_hash, hash('sha256', $code))) {
            return null;
        }

        return $operation;
    }
}
