<?php

namespace App\Services;

use App\Support\LifecycleStoragePath;
use Illuminate\Support\Str;
use RuntimeException;

class LifecycleOperatorFiles
{
    public function path(string $keyId, bool $public = false): string
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $keyId)
            || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|\z)/i', $keyId)) {
            throw new RuntimeException('Invalid key identifier.');
        }
        $root = LifecycleStoragePath::root('famie.lifecycle_operator_root', 'messages.lifecycle_vault_unavailable');
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($root) & 0077) !== 0) {
            throw new RuntimeException('Operator directory must be private.');
        }

        return $root.'/'.$keyId.($public ? '.public.json' : '.key.json');
    }

    /** @param array<string, mixed> $data */
    public function create(string $keyId, array $data, bool $public = false): void
    {
        $this->createAt($this->path($keyId, $public), $data);
    }

    public function packagePath(string $packageId, bool $incoming = false): string
    {
        if (! Str::isUuid($packageId)) {
            throw new RuntimeException('Invalid package identifier.');
        }
        $root = LifecycleStoragePath::root($incoming ? 'famie.lifecycle_operator_root' : 'famie.lifecycle_export_root', 'messages.lifecycle_vault_unavailable');
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($root) & 0077) !== 0) {
            throw new RuntimeException('Package directory must be private.');
        }

        return $root.'/'.$packageId.'.inquiry.json';
    }

    /** @param array<string, mixed> $data */
    public function createPackage(string $id, array $data): void
    {
        $this->createAt($this->packagePath($id), $data);
    }

    /** @param array<string, mixed> $data */
    private function createAt(string $path, array $data, ?int $limit = null): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Existing operator files are never overwritten.');
        }
        $contents = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        if ($limit !== null && strlen($contents) > $limit) {
            throw new RuntimeException('Operator evidence exceeds its publication size limit.');
        }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot create operator file.');
        }
        try {
            if (! chmod($temporary, 0600) || fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Cannot save operator file.');
            }
        } finally {
            fclose($handle);
        }
        if (! link($temporary, $path)) {
            throw new RuntimeException('Operator file publication refused.');
        }
        if (! unlink($temporary)) {
            throw new RuntimeException('Operator file publication incomplete.');
        }
        LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_vault_unavailable');
    }

    /** @return array<string, mixed> */
    public function read(string $keyId): array
    {
        $data = json_decode($this->readBytes($this->path($keyId), 16384), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Invalid operator key.');
        }

        return $data;
    }

    /** @return array{data: array<string, mixed>, sha256: string} */
    public function readPackage(string $id, bool $incoming = true): array
    {
        $bytes = $this->readBytes($this->packagePath($id, $incoming), 2097152);
        $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Invalid inquiry package.');
        }

        return ['data' => $data, 'sha256' => hash('sha256', $bytes)];
    }

    public function deletePackage(string $id, string $expectedSha256, bool $incoming): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256)) {
            throw new RuntimeException('Trusted package checksum required.');
        }
        $path = $this->packagePath($id, $incoming);
        LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_vault_unavailable');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Package unavailable.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Package busy.');
            }
            $opened = fstat($handle);
            $named = lstat($path);
            if ($opened === false || $named === false || $opened['size'] > 2097152 || $opened['nlink'] !== 1
                || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
                throw new RuntimeException('Unsafe package.');
            }
            $bytes = stream_get_contents($handle, 2097153);
            if (! is_string($bytes) || strlen($bytes) > 2097152 || ! hash_equals($expectedSha256, hash('sha256', $bytes))) {
                throw new RuntimeException('Package changed before expiry.');
            }
            LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_vault_unavailable');
            if (! unlink($path)) {
                throw new RuntimeException('Package expiry failed.');
            }
        } finally {
            fclose($handle);
        }
    }

    public function readVault(string $receipt): string
    {
        if (! Str::isUuid($receipt)) {
            throw new RuntimeException('Invalid vault receipt.');
        }
        $root = LifecycleStoragePath::root('famie.lifecycle_vault_root', 'messages.lifecycle_vault_unavailable');

        return $this->readBytes($root.'/'.$receipt.'.json', 1048576);
    }

    public function imageEvidencePath(string $receipt): string
    {
        if (! Str::isUuid($receipt)) {
            throw new RuntimeException('Invalid image evidence receipt.');
        }
        $root = LifecycleStoragePath::root('famie.lifecycle_ledger_root', 'messages.lifecycle_ledger_unavailable');

        return $root.'/'.$receipt.'.images.json';
    }

    /** @param array<string,mixed> $data */
    public function createImageEvidence(string $receipt, array $data): void
    {
        $this->createAt($this->imageEvidencePath($receipt), $data, 4194304);
    }

    /** @return array{data:array<string,mixed>,sha256:string} */
    public function readImageEvidence(string $receipt): array
    {
        $bytes = $this->readBytes($this->imageEvidencePath($receipt), 4194304);
        $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Invalid image evidence.');
        }

        return ['data' => $data, 'sha256' => hash('sha256', $bytes)];
    }

    public function copiedEvidenceDirectory(string $sync): string
    {
        $root = dirname($this->packagePath($sync, true));
        $path = $root.'/'.$sync.'.evidence';
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($path) || is_link($path)
            || strcasecmp(str_replace('\\', '/', $resolved), str_replace('\\', '/', $path)) !== 0) {
            throw new RuntimeException('Copied evidence directory is unavailable.');
        }

        return $path;
    }

    /** @return list<string> */
    public function copiedEvidenceFiles(string $sync): array
    {
        $files = [];
        foreach (new \DirectoryIterator($this->copiedEvidenceDirectory($sync)) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if (count($files) >= 1001) {
                throw new RuntimeException('Copied evidence limit exceeded.');
            }
            LifecycleStoragePath::assertRegularFile($entry->getPathname(), 'messages.lifecycle_ledger_unavailable');
            $files[] = $entry->getFilename();
        }
        sort($files);

        return $files;
    }

    public function copiedLedgerHash(string $sync): string
    {
        return hash('sha256', $this->readBytes($this->copiedEvidenceDirectory($sync).'/account-lifecycle.jsonl', 16777216));
    }

    /** @return array{data:array<string,mixed>,sha256:string} */
    public function readCopiedImageEvidence(string $sync, string $receipt): array
    {
        if (! Str::isUuid($receipt)) {
            throw new RuntimeException('Invalid copied image evidence receipt.');
        }
        $bytes = $this->readBytes($this->copiedEvidenceDirectory($sync).'/'.$receipt.'.images.json', 4194304);
        $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Invalid copied image evidence.');
        }

        return ['data' => $data, 'sha256' => hash('sha256', $bytes)];
    }

    /** @return array{data:array<string,mixed>,sha256:string} */
    public function readControlRecord(string $id, string $kind): array
    {
        if (! in_array($kind, ['backup-inventory', 'ledger-sync', 'restore-receipt', 'backup-catalogue', 'backup-coverage'], true)) {
            throw new RuntimeException('Unknown operator control record.');
        }
        $root = dirname($this->packagePath($id, true));
        $bytes = $this->readBytes($root.'/'.$id.'.'.$kind.'.json', 1048576);
        $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Invalid operator control record.');
        }

        return ['data' => $data, 'sha256' => hash('sha256', $bytes)];
    }

    public function restoreBackupHash(string $id): string
    {
        $root = dirname($this->packagePath($id, true));

        return hash('sha256', $this->readBytes($root.'/'.$id.'.restore.dump', 16777216));
    }

    private function readBytes(string $path, int $limit): string
    {
        LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_vault_unavailable');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Operator file unavailable.');
        }
        try {
            $opened = fstat($handle);
            $named = lstat($path);
            if (! flock($handle, LOCK_SH | LOCK_NB) || $opened === false || $named === false || $opened['size'] > $limit
                || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev'] || $opened['nlink'] !== 1) {
                throw new RuntimeException('Unsafe operator file.');
            }
            $bytes = stream_get_contents($handle, $limit + 1);
            if (! is_string($bytes) || strlen($bytes) > $limit) {
                throw new RuntimeException('Invalid operator file size.');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }
}
