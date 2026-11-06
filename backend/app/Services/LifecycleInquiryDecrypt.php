<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class LifecycleInquiryDecrypt
{
    public function __construct(private LifecycleOperatorFiles $files, private LifecycleOperatorKey $keys,
        private LifecycleLedgerInspector $ledger, private LifecycleAudit $audit) {}

    /**
     * @param  array{receipt_id:string,package_id:string,key_id:string,source_url:string,environment:string,package_sha256:string,ledger_sha256:string}  $expected
     */
    public function decrypt(array $expected, #[SensitiveParameter] string $code, #[SensitiveParameter] string $passphrase, string $operator, Closure $consume): void
    {
        $fields = ['receipt_id', 'package_id', 'key_id', 'source_url', 'environment', 'package_sha256', 'ledger_sha256'];
        if (! config('famie.lifecycle_operator_enabled') || ! app()->environment(['local', 'testing'])
            || count($expected) !== count($fields) || array_diff(array_keys($expected), $fields)) {
            throw new RuntimeException('Local inquiry decryption unavailable.');
        }
        foreach ($fields as $field) {
            if (! is_string($expected[$field] ?? null)) {
                throw new RuntimeException('Invalid decryption context.');
            }
        }
        if (! Str::isUuid($expected['receipt_id']) || ! Str::isUuid($expected['package_id'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', $expected['package_sha256']) || ! preg_match('/\A[a-f0-9]{64}\z/', $expected['ledger_sha256'])) {
            throw new RuntimeException('Invalid decryption reference.');
        }
        $receipt = $expected['receipt_id'];
        $keyId = $expected['key_id'];
        $this->files->path($keyId);
        $this->audit->record('decrypt', 'started', $operator, $receipt, $keyId);
        try {
            $loaded = $this->files->readPackage($expected['package_id']);
            if (! hash_equals($expected['package_sha256'], $loaded['sha256'])) {
                throw new RuntimeException('Package checksum mismatch.');
            }
            $package = $loaded['data'];
            $packageFields = ['format_version', 'package_id', 'receipt_id', 'source_url', 'environment', 'key_id', 'group_id', 'kind', 'committed_at',
                'retention_until', 'confirmation_code_hash', 'ledger_sha256', 'vault_sha256', 'vault'];
            if (count($package) !== count($packageFields) || array_diff(array_keys($package), $packageFields) || ($package['format_version'] ?? null) !== 1
                || ! is_int($package['group_id'] ?? null) || $package['group_id'] <= 0 || ! in_array($package['kind'] ?? null, ['member_delete', 'group_withdraw'], true)) {
                throw new RuntimeException('Invalid package format.');
            }
            foreach (['receipt_id', 'package_id', 'key_id', 'source_url', 'environment', 'ledger_sha256'] as $field) {
                if (($package[$field] ?? null) !== $expected[$field]) {
                    throw new RuntimeException('Package identity mismatch.');
                }
            }
            $committed = $this->instant($package['committed_at'] ?? null);
            $until = $this->instant($package['retention_until'] ?? null);
            if ($committed->isFuture() || $until->lessThanOrEqualTo(now()) || ! $committed->addDays(30)->equalTo($until)
                || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code) || ! is_string($package['confirmation_code_hash'] ?? null)
                || ! hash_equals($package['confirmation_code_hash'], hash('sha256', $code))) {
                throw new RuntimeException('Inquiry expired or unconfirmed.');
            }
            $evidence = $this->ledger->inspect($expected['ledger_sha256'], $receipt)['operation'];
            if (! $evidence || $evidence['state'] !== 'committed' || $evidence['group_id'] !== $package['group_id']
                || $evidence['kind'] !== $package['kind'] || $evidence['committed_at'] !== $package['committed_at']
                || $evidence['vault_sha256'] !== ($package['vault_sha256'] ?? null)) {
                throw new RuntimeException('Ledger does not confirm this package.');
            }
            $bytes = is_string($package['vault'] ?? null) ? base64_decode($package['vault'], true) : false;
            if ($bytes === false || strlen($bytes) > 1048576 || ! hash_equals($evidence['vault_sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('Vault checksum mismatch.');
            }
            $vault = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($vault) || count($vault) !== 9 || array_diff(array_keys($vault), ['format_version', 'key_id', 'environment', 'operation_id', 'committed_at', 'retention_until', 'nonce', 'sealed_key', 'ciphertext'])
                || ($vault['format_version'] ?? null) !== 1 || ($vault['operation_id'] ?? null) !== $receipt) {
                throw new RuntimeException('Invalid vault format.');
            }
            foreach (['key_id', 'environment', 'committed_at', 'retention_until'] as $field) {
                if (($vault[$field] ?? null) !== $package[$field]) {
                    throw new RuntimeException('Vault identity mismatch.');
                }
            }
            $aad = json_encode(['operation_id' => $receipt, 'retention_until' => $vault['retention_until'],
                'environment' => $vault['environment'], 'key_id' => $keyId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $nonce = $this->decode($vault['nonce'] ?? null, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $sealed = $this->decode($vault['sealed_key'] ?? null, SODIUM_CRYPTO_BOX_SEALBYTES + 32);
            $ciphertext = $this->decode($vault['ciphertext'] ?? null);
            $this->keys->withKey($this->files->read($keyId), $keyId, $passphrase,
                function (#[SensitiveParameter] string $pair) use ($sealed, $ciphertext, $aad, $nonce, $package, $evidence, $operator, $receipt, $keyId, $consume): void {
                    $dataKey = sodium_crypto_box_seal_open($sealed, $pair);
                    if ($dataKey === false || strlen($dataKey) !== 32) {
                        throw new RuntimeException('Vault key unavailable.');
                    }
                    $plaintext = null;
                    try {
                        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $dataKey);
                        if ($plaintext === false) {
                            throw new RuntimeException('Vault authentication failed.');
                        }
                        $payload = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
                        $this->validatePayload($payload, $package, $evidence);
                        if ($this->instant($package['retention_until'])->lessThanOrEqualTo(now())) {
                            throw new RuntimeException('Inquiry expired while decrypting.');
                        }
                        $this->audit->record('decrypt', 'success', $operator, $receipt, $keyId);
                        $consume($payload);
                        unset($payload);
                    } finally {
                        sodium_memzero($dataKey);
                        if (is_string($plaintext)) {
                            sodium_memzero($plaintext);
                        }
                    }
                });
        } catch (Throwable) {
            $this->audit->record('decrypt', 'failure', $operator, $receipt, $keyId);
            throw new RuntimeException('Inquiry decryption unavailable.');
        } finally {
            sodium_memzero($code);
            sodium_memzero($passphrase);
        }
    }

    private function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)\z/', $value)) {
            throw new RuntimeException('Invalid inquiry timestamp.');
        }
        $time = CarbonImmutable::parse($value);
        if ($time->utc()->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            throw new RuntimeException('Invalid inquiry date.');
        }

        return $time;
    }

    private function decode(mixed $value, ?int $length = null): string
    {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if ($decoded === false || ($length !== null && strlen($decoded) !== $length)
            || ($length === null && strlen($decoded) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES)) {
            throw new RuntimeException('Invalid vault encoding.');
        }

        return $decoded;
    }

    private function validatePayload(mixed $payload, array $package, array $evidence): void
    {
        if (! is_array($payload) || count($payload) !== 6 || array_diff(array_keys($payload), ['operation_id', 'kind', 'group_id', 'group_name', 'committed_at', 'subjects'])
            || ($payload['operation_id'] ?? null) !== $package['receipt_id'] || ($payload['kind'] ?? null) !== $package['kind']
            || ($payload['group_id'] ?? null) !== $package['group_id'] || ($payload['committed_at'] ?? null) !== $package['committed_at']
            || ! is_array($payload['subjects'] ?? null) || ! array_is_list($payload['subjects'])
            || ! array_key_exists('group_name', $payload)
            || ($package['kind'] === 'group_withdraw' ? ! is_string($payload['group_name']) : $payload['group_name'] !== null)
            || ($package['kind'] === 'member_delete' && count($payload['subjects']) !== 1)) {
            throw new RuntimeException('Decrypted payload identity mismatch.');
        }
        $ids = [];
        $memberships = [];
        foreach ($payload['subjects'] as $subject) {
            if (! is_array($subject) || count($subject) !== 5 || array_diff(array_keys($subject), ['user_id', 'membership_id', 'username', 'display_name', 'email'])
                || ! is_int($subject['user_id'] ?? null) || $subject['user_id'] <= 0 || ! is_int($subject['membership_id'] ?? null) || $subject['membership_id'] <= 0
                || ! is_string($subject['username'] ?? null) || ! is_string($subject['display_name'] ?? null)
                || ! array_key_exists('email', $subject) || ($subject['email'] !== null && ! is_string($subject['email']))) {
                throw new RuntimeException('Invalid decrypted subject.');
            }
            $ids[] = $subject['user_id'];
            $memberships[] = $subject['membership_id'];
        }
        $ledgerIds = $evidence['subject_ids'];
        sort($ids);
        sort($ledgerIds);
        if ($ids !== $ledgerIds || count(array_unique($ids)) !== count($ids) || count(array_unique($memberships)) !== count($memberships)) {
            throw new RuntimeException('Decrypted subjects do not match the ledger.');
        }
    }
}
