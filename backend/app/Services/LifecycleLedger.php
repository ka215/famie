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
        $allowedKeys = $event === 'intent' ? ['vault_sha256', 'subject_ids'] : [];
        if (! in_array($event, ['intent', 'committed', 'aborted'], true) || array_diff(array_keys($context), $allowedKeys)) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_ledger_unavailable'));
        }
        if ($event === 'intent') {
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
