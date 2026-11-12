<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use App\Support\LifecycleStoragePath;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class LifecycleLedger
{
    /** @param array<string, mixed> $context */
    public function append(AccountLifecycleOperation $operation, string $event, array $context = []): void
    {
        $root = LifecycleStoragePath::root('famie.lifecycle_ledger_root', 'messages.lifecycle_ledger_unavailable');
        $holdEvent = in_array($event, ['hold.started', 'hold.released'], true);
        $allowedKeys = $event === 'intent' ? ['vault_sha256', 'subject_ids', 'recovery_identity', 'image_manifest_sha256'] : ($holdEvent ? ['hold_id', 'reference', 'scope', 'review_at', 'operator'] : ($event === 'cleanup.progress' ? ['cleanup_state', 'content_cursor'] : ($event === 'recovery.identity' ? ['recovery_identity'] : [])));
        if (! in_array($event, ['intent', 'committed', 'aborted', 'hold.started', 'hold.released', 'cleanup.progress', 'recovery.identity'], true) || array_diff(array_keys($context), $allowedKeys)) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
        }
        if (array_key_exists('recovery_identity', $context) || $event === 'recovery.identity') {
            $ids = $event === 'intent' ? ($context['subject_ids'] ?? []) : $operation->subjects()->pluck('user_id')->all();
            if (! is_array($ids) || ! LifecycleRecoveryIdentity::valid($context['recovery_identity'] ?? null, $ids, $operation->kind)
                || $context['recovery_identity'] !== LifecycleRecoveryIdentity::capture($operation)
                || ($event === 'recovery.identity' && ($operation->state !== 'committed' || str_starts_with((string) $operation->last_error_code, 'restore_reapply_')))) {
                throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
            }
        }
        if ($event === 'cleanup.progress' && (! in_array($context['cleanup_state'] ?? null, ['processing', 'complete'], true)
            || ! is_int($context['content_cursor'] ?? null) || $context['content_cursor'] < 0
            || $operation->state !== AccountLifecycleOperation::STATE_COMMITTED
            || $context['cleanup_state'] !== $operation->cleanup_state || $context['content_cursor'] !== $operation->content_cursor)) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
        }
        if ($holdEvent && (! is_int($context['hold_id'] ?? null) || $context['hold_id'] < 1
            || ($context['scope'] ?? null) !== 'all'
            || ! is_string($context['reference'] ?? null) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $context['reference'])
            || ! is_string($context['operator'] ?? null) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $context['operator'])
            || ! is_string($context['review_at'] ?? null) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $context['review_at']))) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
        }
        if ($event === 'intent') {
            if (array_key_exists('image_manifest_sha256', $context) && (! is_string($context['image_manifest_sha256'])
                || ! hash_equals($context['image_manifest_sha256'], app(LifecycleOperatorFiles::class)->readImageEvidence($operation->id)['sha256']))) {
                throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
            }
            if (! is_string($context['vault_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $context['vault_sha256']) !== 1
                || ! is_array($context['subject_ids'] ?? null) || ! array_is_list($context['subject_ids'])) {
                throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
            }
            foreach ($context['subject_ids'] as $subjectId) {
                if (! is_int($subjectId) || $subjectId < 1) {
                    throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
                }
            }
        }
        $record = json_encode([
            ...$context,
            'recorded_at' => now()->utc()->toIso8601String(),
            'event' => $event,
            'operation_id' => $operation->id,
            'kind' => $operation->kind,
            'group_id' => $operation->group_id,
            'committed_at' => $operation->committed_at?->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $path = $root.DIRECTORY_SEPARATOR.'account-lifecycle.jsonl';
        $exists = file_exists($path) || is_link($path);
        if ($exists) {
            LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_ledger_unavailable');
        }
        $handle = @fopen($path, $exists ? 'c+b' : 'x+b');
        if ($handle === false) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
            }
            LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_ledger_unavailable');
            $opened = fstat($handle);
            $named = lstat($path);
            if ($opened === false || $named === false || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
                || $opened['nlink'] !== 1 || ! chmod($path, 0600)) {
                throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
            }
            if ($opened['size'] > 0 && (fseek($handle, -1, SEEK_END) !== 0 || fread($handle, 1) !== "\n")) {
                throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
            }
            if (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, $record) !== strlen($record) || ! fflush($handle) || ! fsync($handle)) {
                throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
            }
        } finally {
            fclose($handle);
        }
    }
}
