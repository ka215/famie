<?php

declare(strict_types=1);

use App\Services\LifecycleAudit;
use App\Services\LifecycleOperatorKey;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never {
    throw new RuntimeException('Key recovery operation unavailable.');
});

/** @return array{data:array<string,mixed>,sha256:string} */
function recoveryRead(string $path): array
{
    recoveryPath($path);
    $stat = lstat($path);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || $stat['size'] > 16384) {
        throw new RuntimeException('Unsafe key recovery input.');
    }
    $handle = fopen($path, 'rb');
    try {
        if (! flock($handle, LOCK_SH | LOCK_NB)) {
            throw new RuntimeException('Key recovery input busy.');
        }
        $opened = fstat($handle);
        if ($opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev'] || $opened['size'] !== $stat['size'] || $opened['nlink'] !== 1) {
            throw new RuntimeException('Key recovery input changed.');
        }
        $bytes = stream_get_contents($handle, 16385);
        if (strlen($bytes) > 16384) {
            throw new RuntimeException('Key recovery input too large.');
        }
        $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Invalid key recovery input.');
        }

        return ['data' => $data, 'sha256' => hash('sha256', $bytes)];
    } finally {
        fclose($handle);
    }
}

function recoveryPath(string $path): void
{
    if ($path === '' || (! str_starts_with($path, '/') && ! preg_match('/\A[A-Za-z]:[\\\\\/]/', $path))) {
        throw new RuntimeException('Absolute recovery path required.');
    }
    for ($part = $path; dirname($part) !== $part; $part = dirname($part)) {
        if (is_link($part) || in_array(basename($part), ['.', '..'], true)) {
            throw new RuntimeException('Linked recovery paths are unavailable.');
        }
    }
}

/** @param array<string,mixed> $data */
function recoveryWrite(string $path, array $data): string
{
    recoveryPath($path);
    $bytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    $handle = fopen($path, 'x+b');
    try {
        if (! flock($handle, LOCK_EX | LOCK_NB) || ! chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
            throw new RuntimeException('Portable key publication incomplete.');
        }
    } finally {
        fclose($handle);
    }

    $actual = recoveryRead($path)['sha256'];
    if (! hash_equals(hash('sha256', $bytes), $actual)) {
        throw new RuntimeException('Published key recovery file changed.');
    }

    return $actual;
}

function recoveryAudit(string $directory, string $id, string $operator, string $event, string $outcome): void
{
    recoveryPath($directory);
    $resolved = realpath($directory);
    $project = realpath(dirname(__DIR__));
    if ($resolved === false || ! is_dir($directory)
        || str_starts_with(strtolower(str_replace('\\', '/', $resolved)).'/', strtolower(str_replace('\\', '/', $project)).'/')
        || (PHP_OS_FAMILY !== 'Windows' && (fileperms($directory) & 0077) !== 0)) {
        throw new RuntimeException('Private recovery audit directory required.');
    }
    $lockPath = $directory.'/.audit.lock';
    if (file_exists($lockPath) || is_link($lockPath)) {
        $stat = lstat($lockPath);
        if (is_link($lockPath) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            throw new RuntimeException('Unsafe audit lock.');
        }
    }
    $lock = fopen($lockPath, file_exists($lockPath) ? 'c+b' : 'x+b');
    try {
        if (! chmod($lockPath, 0600) || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Recovery audit busy.');
        }
        $opened = fstat($lock);
        $named = lstat($lockPath);
        if ($opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev'] || $opened['nlink'] !== 1 || is_link($lockPath)) {
            throw new RuntimeException('Audit lock identity changed.');
        }
        $attempts = 0;
        if ($outcome === 'started') {
            foreach ([gmdate('Y-m-d'), gmdate('Y-m-d', time() - 86400)] as $date) {
                $path = $directory.'/audit-'.$date.'.jsonl';
                if (! file_exists($path) && ! is_link($path)) {
                    continue;
                }
                recoveryPath($path);
                $stat = lstat($path);
                if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || $stat['size'] > 16777216) {
                    throw new RuntimeException('Unsafe recovery audit.');
                }
                $handle = fopen($path, 'rb');
                try {
                    $opened = fstat($handle);
                    if ($opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev'] || $opened['nlink'] !== 1 || $opened['size'] !== $stat['size']) {
                        throw new RuntimeException('Audit input changed.');
                    }
                    while (($line = fgets($handle, 4096)) !== false) {
                        if (! str_ends_with($line, "\n")) {
                            throw new RuntimeException('Incomplete recovery audit.');
                        }
                        $entry = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                        $baseFields = ['recorded_at', 'event', 'outcome', 'operator', 'receipt_id', 'key_id'];
                        if (! is_array($entry) || array_diff($baseFields, array_keys($entry))
                            || array_diff(array_keys($entry), [...$baseFields, 'package_id', 'copy_location', 'control_id'])
                            || ! in_array($entry['event'], LifecycleAudit::EVENTS, true) || ! in_array($entry['outcome'], ['started', 'success', 'failure'], true)
                            || ! is_string($entry['operator']) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $entry['operator'])
                            || ($entry['key_id'] !== null && (! is_string($entry['key_id']) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $entry['key_id'])))
                            || ($entry['receipt_id'] !== null && (! is_string($entry['receipt_id']) || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $entry['receipt_id'])))) {
                            throw new RuntimeException('Invalid recovery audit metadata.');
                        }
                        $at = is_array($entry) && is_string($entry['recorded_at'] ?? null)
                            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $entry['recorded_at'], new DateTimeZone('UTC')) : false;
                        if ($at === false || $at->format('Y-m-d\TH:i:s\Z') !== $entry['recorded_at'] || $at->format('Y-m-d') !== $date || $at->getTimestamp() > time()) {
                            throw new RuntimeException('Invalid recovery audit time.');
                        }
                        if (($entry['outcome'] ?? null) === 'started' && ($entry['key_id'] ?? null) === $id
                            && in_array($entry['event'] ?? null, LifecycleAudit::KEY_ATTEMPT_EVENTS, true) && $at->getTimestamp() > time() - 900) {
                            $attempts++;
                        }
                    }
                } finally {
                    fclose($handle);
                }
            }
        }
        $blocked = $outcome === 'started' && $attempts >= 5;
        $path = $directory.'/audit-'.gmdate('Y-m-d').'.jsonl';
        recoveryPath($path);
        $exists = file_exists($path);
        if ($exists) {
            $stat = lstat($path);
            if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || $stat['size'] > 16777216) {
                throw new RuntimeException('Unsafe recovery audit append.');
            }
        }
        $handle = fopen($path, $exists ? 'c+b' : 'x+b');
        try {
            $opened = fstat($handle);
            $named = lstat($path);
            if ($opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev'] || $opened['nlink'] !== 1 || is_link($path)) {
                throw new RuntimeException('Audit append identity changed.');
            }
            if (! chmod($path, 0600) || (fseek($handle, 0, SEEK_END) !== 0)
                || ($exists && ftell($handle) > 0 && (fseek($handle, -1, SEEK_END) !== 0 || fread($handle, 1) !== "\n"))) {
                throw new RuntimeException('Incomplete recovery audit append.');
            }
            $line = json_encode(['recorded_at' => gmdate('Y-m-d\TH:i:s\Z'), 'event' => $event, 'outcome' => $blocked ? 'failure' : $outcome,
                'operator' => $operator, 'receipt_id' => null, 'key_id' => $id], JSON_THROW_ON_ERROR)."\n";
            if (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, $line) !== strlen($line) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Recovery audit append failed.');
            }
        } finally {
            fclose($handle);
        }
        if ($blocked) {
            throw new RuntimeException('Recovery key attempts limited.');
        }
    } finally {
        fclose($lock);
    }
}

/** @var array<string,mixed>|null $secret */
$secret = null;
$input = null;
$passphrase = null;
$status = 0;
$attemptRecorded = false;
try {
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80200 || ! extension_loaded('sodium')) {
        throw new RuntimeException('PHP CLI with Sodium is required.');
    }
    require dirname(__DIR__).'/backend/app/Services/LifecycleOperatorKey.php';
    require dirname(__DIR__).'/backend/app/Services/LifecycleAudit.php';
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        $pieces = explode('=', $argument, 2);
        if (count($pieces) !== 2 || ! in_array($pieces[0], ['--mode', '--key-file', '--public-file', '--key-id', '--expected-source-sha256', '--destination', '--audit-directory', '--operator'], true)
            || isset($options[$pieces[0]])) {
            throw new RuntimeException('Invalid recovery arguments.');
        }
        $options[$pieces[0]] = $pieces[1];
    }
    $mode = $options['--mode'] ?? 'plan';
    $id = $options['--key-id'] ?? '';
    if (! in_array($mode, ['plan', 'create', 'verify'], true) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $id)
        || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|\z)/i', $id)) {
        throw new RuntimeException('Invalid recovery identity.');
    }
    $loaded = recoveryRead($options['--key-file'] ?? '');
    $public = recoveryRead($options['--public-file'] ?? '')['data'];
    if (count($public) !== 2 || array_diff(array_keys($public), ['key_id', 'public_key']) || ($public['key_id'] ?? null) !== $id
        || ($loaded['data']['key_id'] ?? null) !== $id || ! is_string($public['public_key'] ?? null)
        || $public['public_key'] !== ($loaded['data']['public_key'] ?? null) || strlen(base64_decode($public['public_key'], true) ?: '') !== 32) {
        throw new RuntimeException('Public recovery identity differs.');
    }
    $expected = $options['--expected-source-sha256'] ?? '';
    if (($mode !== 'plan' || $expected !== '') && (! preg_match('/\A[a-f0-9]{64}\z/', $expected) || ! hash_equals($expected, $loaded['sha256']))) {
        throw new RuntimeException('Trusted source checksum required.');
    }
    $paths = [];
    if (isset($options['--destination'])) {
        $directory = rtrim($options['--destination'], '/\\');
        recoveryPath($directory);
        $root = realpath($directory);
        $project = realpath(dirname(__DIR__));
        if ($root === false || ! is_dir($root) || ! is_writable($root)
            || str_starts_with(strtolower(str_replace('\\', '/', $root)).'/', strtolower(str_replace('\\', '/', $project)).'/')) {
            throw new RuntimeException('An existing private destination outside the workspace is required.');
        }
        foreach (['key', 'public'] as $suffix) {
            $paths[$suffix] = $root.'/'.$id.'.'.$suffix.'.json';
            if (file_exists($paths[$suffix]) || is_link($paths[$suffix])) {
                throw new RuntimeException('Existing recovery files are never overwritten.');
            }
        }
    }
    $result = ['mode' => $mode, 'key_id' => $id, 'source_sha256' => $loaded['sha256'],
        'public_key_sha256' => hash('sha256', base64_decode($public['public_key'], true)),
        'database_bootstrapped' => false, 'server_public_key_verified' => false, 'physical_medium_verified' => false,
        'separate_computer_verified' => false, 'key_verified' => false, 'files_created' => 0];
    if ($mode !== 'plan') {
        $auditDirectory = $options['--audit-directory'] ?? '';
        $operator = $options['--operator'] ?? '';
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $operator)) {
            throw new RuntimeException('Opaque recovery operator required.');
        }
        $event = $mode === 'create' ? 'key.reprotect' : 'key.verify';
        recoveryAudit($auditDirectory, $id, $operator, $event, 'started');
        $attemptRecorded = true;
        $input = stream_get_contents(STDIN, 16385);
        if (strlen($input) > 16384) {
            throw new RuntimeException('Recovery input too large.');
        }
        $fields = $mode === 'create' ? ['old', 'new'] : ['passphrase'];
        $lines = preg_split('/\r?\n/', rtrim($input, "\r\n"));
        if (count($lines) !== count($fields)) {
            throw new RuntimeException('Invalid private recovery input.');
        }
        $secret = [];
        foreach ($fields as $index => $field) {
            $decoded = base64_decode($lines[$index], true);
            if (! is_string($decoded)) {
                throw new RuntimeException('Invalid private recovery input.');
            }
            $secret[$field] = $decoded;
        }
        sodium_memzero($input);
        foreach ($lines as &$line) {
            sodium_memzero($line);
        }
        unset($line, $decoded);
        $keys = new LifecycleOperatorKey;
        $envelope = $loaded['data'];
        $passphrase = $mode === 'create' ? $secret['new'] : $secret['passphrase'];
        if ($mode === 'create') {
            if ($paths === []) {
                throw new RuntimeException('Explicit portable key destination required.');
            }
            $envelope = $keys->reprotect($envelope, $id, $secret['old'], $secret['new']);
        }
        $keys->withKey($envelope, $id, $passphrase, static function (#[SensitiveParameter] string $pair): void {
            $challenge = random_bytes(32);
            $opened = sodium_crypto_box_seal_open(sodium_crypto_box_seal($challenge, sodium_crypto_box_publickey($pair)), $pair);
            if ($opened === false || ! hash_equals($challenge, $opened)) {
                throw new RuntimeException('Recovery roundtrip failed.');
            }
            sodium_memzero($challenge);
            sodium_memzero($opened);
        });
        sodium_memzero($passphrase);
        $result['key_verified'] = true;
        if ($mode === 'create') {
            $result['backup_sha256'] = recoveryWrite($paths['key'], $envelope);
            $result['public_file_sha256'] = recoveryWrite($paths['public'], $public);
            $result['files_created'] = 2;
        }
        recoveryAudit($auditDirectory, $id, $operator, $event, 'success');
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable) {
    if ($attemptRecorded) {
        try {
            recoveryAudit($auditDirectory, $id, $operator, $event, 'failure');
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, 'Key recovery failed. Preserve originals; incomplete destination files are unverified.'.PHP_EOL);
    $status = 1;
} finally {
    if (is_string($input)) {
        sodium_memzero($input);
    }
    if (is_string($passphrase)) {
        sodium_memzero($passphrase);
    }
    foreach ($secret ?? [] as &$value) {
        if (is_string($value)) {
            sodium_memzero($value);
        }
    }
    foreach ($lines ?? [] as &$line) {
        if (is_string($line)) {
            sodium_memzero($line);
        }
    }
}
exit($status);
