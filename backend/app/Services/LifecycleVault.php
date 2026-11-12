<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use App\Support\LifecycleStoragePath;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class LifecycleVault
{
    /** @param array<string, mixed> $payload
     * @return array{path: string, sha256: string, bytes: int}
     */
    public function store(AccountLifecycleOperation $operation, array $payload): array
    {
        if (! extension_loaded('sodium')) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_crypto_unavailable'));
        }

        $root = LifecycleStoragePath::root('famie.lifecycle_vault_root', 'messages.lifecycle_vault_unavailable');
        $path = $this->operationPath($root, $operation->id);
        if (file_exists($path) || is_link($path)) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_vault_unavailable'));
        }
        $publicKey = base64_decode((string) config('famie.lifecycle_public_key'), true);
        $keyId = config('famie.lifecycle_key_id');
        if (! is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES || ! is_string($keyId) || $keyId === '') {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_crypto_unavailable'));
        }

        $aad = json_encode([
            'operation_id' => $operation->id,
            'retention_until' => $operation->retention_until?->utc()->toIso8601String(),
            'environment' => app()->environment(),
            'key_id' => $keyId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $dataKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        try {
            $envelope = json_encode([
                'format_version' => 1,
                'key_id' => $keyId,
                'environment' => app()->environment(),
                'operation_id' => $operation->id,
                'committed_at' => $operation->committed_at?->utc()->toIso8601String(),
                'retention_until' => $operation->retention_until?->utc()->toIso8601String(),
                'nonce' => base64_encode($nonce),
                'sealed_key' => base64_encode(sodium_crypto_box_seal($dataKey, $publicKey)),
                'ciphertext' => base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $dataKey)),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } finally {
            sodium_memzero($dataKey);
            sodium_memzero($plaintext);
        }

        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_vault_unavailable'));
        }
        try {
            if (! chmod($temporary, 0600) || ! flock($handle, LOCK_EX)
                || fwrite($handle, $envelope) !== strlen($envelope) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('vault_write_failed');
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($temporary);
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_vault_unavailable'), $exception);
        }
        fclose($handle);
        if (! @link($temporary, $path)) {
            @unlink($temporary);
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_vault_unavailable'));
        }
        if (! @unlink($temporary)) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_vault_unavailable'));
        }
        LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_vault_unavailable');
        $sha256 = hash_file('sha256', $path);
        $bytes = filesize($path);
        if ($sha256 === false || $bytes !== strlen($envelope)) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_vault_unavailable'));
        }

        return ['path' => $path, 'sha256' => $sha256, 'bytes' => $bytes];
    }

    public function delete(string $operationId): void
    {
        $root = LifecycleStoragePath::root('famie.lifecycle_vault_root', 'messages.lifecycle_vault_unavailable');
        $path = $this->operationPath($root, $operationId);
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        LifecycleStoragePath::assertRegularFile($path, 'messages.lifecycle_vault_unavailable');
        if (! @unlink($path)) {
            throw new RuntimeException('vault_delete_failed');
        }
    }

    public function exists(string $operationId): bool
    {
        if (! config('famie.lifecycle_vault_root')) {
            return false;
        }
        $root = LifecycleStoragePath::root('famie.lifecycle_vault_root', 'messages.lifecycle_vault_unavailable');
        $path = $this->operationPath($root, $operationId);

        return file_exists($path) || is_link($path);
    }

    private function operationPath(string $root, string $operationId): string
    {
        if (! Str::isUuid($operationId)) {
            throw new ServiceUnavailableHttpException(null, __('messages.lifecycle_vault_unavailable'));
        }

        return $root.DIRECTORY_SEPARATOR.$operationId.'.json';
    }
}
