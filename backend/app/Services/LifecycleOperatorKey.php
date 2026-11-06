<?php

namespace App\Services;

use Closure;
use RuntimeException;
use SensitiveParameter;

class LifecycleOperatorKey
{
    /** @return array<string, mixed> */
    public function create(string $keyId, #[SensitiveParameter] string $passphrase): array
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $keyId) || strlen($passphrase) < 16 || strlen($passphrase) > 4096) {
            throw new RuntimeException('Invalid key identifier or passphrase length.');
        }
        $pair = sodium_crypto_box_keypair();
        try {
            return $this->protect($pair, $keyId, $passphrase);
        } finally {
            sodium_memzero($pair);
            sodium_memzero($passphrase);
        }
    }

    /** @param array<string,mixed> $envelope @return array<string,mixed> */
    public function reprotect(array $envelope, string $expectedId, #[SensitiveParameter] string $oldPassphrase, #[SensitiveParameter] string $newPassphrase): array
    {
        if (strlen($newPassphrase) < 16 || strlen($newPassphrase) > 4096) {
            throw new RuntimeException('Invalid new passphrase length.');
        }
        $result = null;
        try {
            $this->withKey($envelope, $expectedId, $oldPassphrase, function (#[SensitiveParameter] string $pair) use ($expectedId, $newPassphrase, &$result): void {
                $result = $this->protect($pair, $expectedId, $newPassphrase);
            });

            return $result;
        } finally {
            sodium_memzero($oldPassphrase);
            sodium_memzero($newPassphrase);
        }
    }

    /** @return array<string,mixed> */
    private function protect(#[SensitiveParameter] string $pair, string $keyId, #[SensitiveParameter] string $passphrase): array
    {
        $private = sodium_crypto_box_secretkey($pair);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = null;
        try {
            $key = $this->derive($passphrase, $salt);

            return [
                'format_version' => 1,
                'key_id' => $keyId,
                'public_key' => base64_encode(sodium_crypto_box_publickey($pair)),
                'kdf' => ['algorithm' => 'argon2id13', 'opslimit' => 2, 'memlimit' => 67108864, 'salt' => base64_encode($salt)],
                'cipher' => ['algorithm' => 'xchacha20poly1305-ietf', 'nonce' => base64_encode($nonce),
                    'ciphertext' => base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($private, $keyId, $nonce, $key))],
            ];
        } finally {
            sodium_memzero($private);
            if (is_string($key)) {
                sodium_memzero($key);
            }
            sodium_memzero($passphrase);
        }
    }

    /** @param array<string, mixed> $envelope */
    public function verify(array $envelope, string $expectedId, #[SensitiveParameter] string $passphrase): void
    {
        $this->withKey($envelope, $expectedId, $passphrase, static fn (#[SensitiveParameter] string $pair) => null);
    }

    /** @param array<string, mixed> $envelope */
    public function withKey(array $envelope, string $expectedId, #[SensitiveParameter] string $passphrase, Closure $callback): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $expectedId)
            || count($envelope) !== 5 || array_diff(array_keys($envelope), ['format_version', 'key_id', 'public_key', 'kdf', 'cipher'])
            || ! is_array($envelope['kdf'] ?? null) || ! is_array($envelope['cipher'] ?? null)
            || count($envelope['kdf']) !== 4 || array_diff(array_keys($envelope['kdf']), ['algorithm', 'opslimit', 'memlimit', 'salt'])
            || count($envelope['cipher']) !== 3 || array_diff(array_keys($envelope['cipher']), ['algorithm', 'nonce', 'ciphertext'])
            || ($envelope['format_version'] ?? null) !== 1 || ($envelope['key_id'] ?? null) !== $expectedId
            || ($envelope['kdf']['algorithm'] ?? null) !== 'argon2id13' || ($envelope['kdf']['opslimit'] ?? null) !== 2
            || ($envelope['kdf']['memlimit'] ?? null) !== 67108864 || ($envelope['cipher']['algorithm'] ?? null) !== 'xchacha20poly1305-ietf'
            || strlen($passphrase) < 16 || strlen($passphrase) > 4096) {
            throw new RuntimeException('Invalid encrypted key format.');
        }
        $public = $this->decode($envelope['public_key'] ?? null, SODIUM_CRYPTO_BOX_PUBLICKEYBYTES);
        $salt = $this->decode($envelope['kdf']['salt'] ?? null, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = $this->decode($envelope['cipher']['nonce'] ?? null, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = $this->decode($envelope['cipher']['ciphertext'] ?? null, SODIUM_CRYPTO_BOX_SECRETKEYBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES);
        $key = $this->derive($passphrase, $salt);
        $private = null;
        $pair = null;
        try {
            $private = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $expectedId, $nonce, $key);
            if ($private === false || ! hash_equals($public, sodium_crypto_box_publickey_from_secretkey($private))) {
                throw new RuntimeException('Encrypted key verification failed.');
            }
            $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($private, $public);

            $callback($pair);
        } finally {
            if (is_string($pair)) {
                sodium_memzero($pair);
            }
            if (is_string($private)) {
                sodium_memzero($private);
            }
            sodium_memzero($key);
            sodium_memzero($passphrase);
        }
    }

    private function derive(#[SensitiveParameter] string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(32, $passphrase, $salt, 2, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private function decode(mixed $value, int $length): string
    {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if ($decoded === false || strlen($decoded) !== $length) {
            throw new RuntimeException('Invalid encrypted key encoding.');
        }

        return $decoded;
    }
}
