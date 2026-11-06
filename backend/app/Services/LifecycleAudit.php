<?php

namespace App\Services;

use App\Support\LifecycleStoragePath;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

class LifecycleAudit
{
    public const EVENTS = ['key.create', 'key.verify', 'inquiry', 'inquiry.export', 'decrypt', 'audit.prune', 'ledger.verify', 'hold.started', 'hold.released', 'copy.expire', 'restore.check', 'backup.assess', 'ledger.sync-check', 'restore.reapply', 'restore.holds', 'recovery.identity', 'restore.orphans', 'backup.coverage', 'key.reprotect'];

    public const KEY_ATTEMPT_EVENTS = ['key.verify', 'decrypt', 'key.reprotect'];

    /** @param array{package_id?:string,copy_location?:string,control_id?:string} $context */
    public function record(string $event, string $outcome, string $operator, ?string $receipt = null, ?string $keyId = null, array $context = []): void
    {
        if (! in_array($event, self::EVENTS, true) || ! in_array($outcome, ['started', 'success', 'failure'], true)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $operator) || ($receipt !== null && ! Str::isUuid($receipt))
            || ($keyId !== null && ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $keyId))) {
            throw new RuntimeException('Invalid lifecycle audit metadata.');
        }
        $control = in_array($event, ['backup.assess', 'ledger.sync-check', 'restore.reapply', 'restore.holds', 'restore.orphans', 'backup.coverage'], true);
        if (($control && (array_keys($context) !== ['control_id'] || ! is_string($context['control_id']) || ! Str::isUuid($context['control_id'])))
            || (! $control && $event !== 'copy.expire' && $context !== []) || ($event === 'copy.expire'
            && (count($context) !== 2 || array_diff(array_keys($context), ['package_id', 'copy_location'])
                || ! is_string($context['package_id'] ?? null) || ! Str::isUuid($context['package_id'])
                || ! in_array($context['copy_location'] ?? null, ['operator', 'export'], true)))) {
            throw new RuntimeException('Invalid copy audit context.');
        }
        $this->locked(function (string $root) use ($event, $outcome, $operator, $receipt, $keyId, $context): void {
            $blocked = false;
            if ($outcome === 'started' && in_array($event, self::KEY_ATTEMPT_EVENTS, true)) {
                $blocked = ! $this->keyAttemptAvailable($root, $keyId);
            }
            $now = now()->utc();
            $path = $root.'/audit-'.$now->format('Y-m-d').'.jsonl';
            $exists = file_exists($path) || is_link($path);
            if ($exists) {
                LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_ledger_unavailable');
            }
            $handle = fopen($path, $exists ? 'c+b' : 'x+b');
            if ($handle === false) {
                throw new RuntimeException('Lifecycle audit unavailable.');
            }
            try {
                $opened = fstat($handle);
                $named = lstat($path);
                if ($opened === false || $named === false || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
                    || $opened['nlink'] !== 1 || ! chmod($path, 0600)) {
                    throw new RuntimeException('Unsafe lifecycle audit file.');
                }
                if ($opened['size'] > 0 && (fseek($handle, -1, SEEK_END) !== 0 || fread($handle, 1) !== "\n")) {
                    throw new RuntimeException('Incomplete lifecycle audit file.');
                }
                $record = json_encode(['recorded_at' => $now->format('Y-m-d\TH:i:s\Z'), 'event' => $event,
                    'outcome' => $blocked ? 'failure' : $outcome, 'operator' => $operator, 'receipt_id' => $receipt, 'key_id' => $keyId, ...$context], JSON_THROW_ON_ERROR)."\n";
                if (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, $record) !== strlen($record) || ! fflush($handle) || ! fsync($handle)) {
                    throw new RuntimeException('Lifecycle audit write failed.');
                }
            } finally {
                fclose($handle);
            }
            if ($blocked) {
                throw new RuntimeException('Operator key verification is temporarily limited.');
            }
        });
    }

    /** @return array{expired: int, files: int} */
    public function prune(bool $apply = false): array
    {
        return $this->locked(function (string $root) use ($apply): array {
            $expired = 0;
            $changed = 0;
            $cutoff = now()->utc()->subDays(90);
            foreach (glob($root.'/audit-*.jsonl') ?: [] as $path) {
                if (! preg_match('/\Aaudit-\d{4}-\d{2}-\d{2}\.jsonl\z/', basename($path))) {
                    throw new RuntimeException('Unexpected lifecycle audit filename.');
                }
                LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_ledger_unavailable');
                if (filesize($path) > 16777216) {
                    throw new RuntimeException('Lifecycle audit requires bounded manual review.');
                }
                $retained = '';
                $removed = 0;
                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    throw new RuntimeException('Lifecycle audit unreadable.');
                }
                try {
                    while (($line = fgets($handle, 4096)) !== false) {
                        if (! str_ends_with($line, "\n")) {
                            throw new RuntimeException('Incomplete lifecycle audit record.');
                        }
                        $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                        $at = $this->auditTime($record, $path);
                        if ($at->lessThanOrEqualTo($cutoff)) {
                            $removed++;
                        } else {
                            $retained .= $line;
                        }
                    }
                } finally {
                    fclose($handle);
                }
                if ($removed === 0) {
                    continue;
                }
                $expired += $removed;
                $changed++;
                if ($apply) {
                    if ($retained === '') {
                        if (! unlink($path)) {
                            throw new RuntimeException('Lifecycle audit expiry failed.');
                        }
                    } else {
                        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
                        $file = fopen($temporary, 'x+b');
                        if ($file === false) {
                            throw new RuntimeException('Lifecycle audit rewrite unavailable.');
                        }
                        try {
                            if (! chmod($temporary, 0600) || fwrite($file, $retained) !== strlen($retained) || ! fflush($file) || ! fsync($file)) {
                                throw new RuntimeException('Lifecycle audit rewrite failed.');
                            }
                        } finally {
                            fclose($file);
                        }
                        if (! rename($temporary, $path)) {
                            throw new RuntimeException('Lifecycle audit replacement failed.');
                        }
                    }
                }
            }

            return ['expired' => $expired, 'files' => $changed];
        });
    }

    private function locked(Closure $callback): mixed
    {
        $root = LifecycleStoragePath::root('famie.lifecycle_audit_root', 'messages.lifecycle_ledger_unavailable');
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($root) & 0077) !== 0) {
            throw new RuntimeException('Lifecycle audit directory must be private.');
        }
        $path = $root.'/.audit.lock';
        $exists = file_exists($path) || is_link($path);
        if ($exists) {
            LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_ledger_unavailable');
        }
        $lock = fopen($path, $exists ? 'c+b' : 'x+b');
        if ($lock === false) {
            throw new RuntimeException('Lifecycle audit lock unavailable.');
        }
        try {
            $opened = fstat($lock);
            $named = lstat($path);
            if ($opened === false || $named === false || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
                || $opened['nlink'] !== 1 || ! chmod($path, 0600) || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Unsafe or busy lifecycle audit lock.');
            }

            return $callback($root);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function keyAttemptAvailable(string $root, ?string $keyId): bool
    {
        if ($keyId === null) {
            throw new RuntimeException('Key identity is required for verification.');
        }
        $count = 0;
        $cutoff = now()->utc()->subMinutes(15);
        foreach ([now()->utc()->format('Y-m-d'), now()->utc()->subDay()->format('Y-m-d')] as $date) {
            $path = $root.'/audit-'.$date.'.jsonl';
            if (! file_exists($path) && ! is_link($path)) {
                continue;
            }
            LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_ledger_unavailable');
            if (filesize($path) > 16777216) {
                throw new RuntimeException('Audit requires bounded manual review.');
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Audit unavailable.');
            }
            try {
                while (($line = fgets($handle, 4096)) !== false) {
                    if (! str_ends_with($line, "\n")) {
                        throw new RuntimeException('Incomplete audit record.');
                    }
                    $entry = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    $at = $this->auditTime($entry, $path);
                    if (($entry['outcome'] ?? null) === 'started' && ($entry['key_id'] ?? null) === $keyId
                        && in_array($entry['event'] ?? null, self::KEY_ATTEMPT_EVENTS, true)
                        && $at->greaterThan($cutoff)) {
                        $count++;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return $count < 5;
    }

    private function auditTime(mixed $record, string $path): CarbonImmutable
    {
        $copy = is_array($record) && ($record['event'] ?? null) === 'copy.expire';
        $control = is_array($record) && in_array($record['event'] ?? null, ['backup.assess', 'ledger.sync-check', 'restore.reapply', 'restore.holds', 'restore.orphans', 'backup.coverage'], true);
        if (! is_array($record) || count($record) !== ($copy ? 8 : ($control ? 7 : 6))
            || array_diff(array_keys($record), ['recorded_at', 'event', 'outcome', 'operator', 'receipt_id', 'key_id', ...($copy ? ['package_id', 'copy_location'] : []), ...($control ? ['control_id'] : [])])
            || ! in_array($record['event'] ?? null, self::EVENTS, true)
            || ! in_array($record['outcome'] ?? null, ['started', 'success', 'failure'], true)
            || ! is_string($record['operator'] ?? null) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $record['operator'])
            || ! array_key_exists('receipt_id', $record) || ($record['receipt_id'] !== null && (! is_string($record['receipt_id']) || ! Str::isUuid($record['receipt_id'])))
            || ! array_key_exists('key_id', $record) || ($record['key_id'] !== null && (! is_string($record['key_id']) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $record['key_id'])))) {
            throw new RuntimeException('Invalid lifecycle audit metadata.');
        }
        if ($copy && (! is_string($record['package_id'] ?? null) || ! Str::isUuid($record['package_id'])
            || ! in_array($record['copy_location'] ?? null, ['operator', 'export'], true))) {
            throw new RuntimeException('Invalid copy audit context.');
        }
        if ($control && (! is_string($record['control_id'] ?? null) || ! Str::isUuid($record['control_id']))) {
            throw new RuntimeException('Invalid backup audit context.');
        }
        $time = $record['recorded_at'] ?? '';
        if (! is_string($time) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $time)) {
            throw new RuntimeException('Invalid lifecycle audit timestamp.');
        }
        $at = CarbonImmutable::parse($time, 'UTC');
        if ('audit-'.$at->format('Y-m-d').'.jsonl' !== basename($path) || $at->format('Y-m-d\TH:i:s\Z') !== $time) {
            throw new RuntimeException('Lifecycle audit timestamp mismatch.');
        }

        return $at;
    }
}
