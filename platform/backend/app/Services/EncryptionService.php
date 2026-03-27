<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * AES-256-GCM encryption/decryption for sensitive data.
 *
 * Uses the APP_KEY environment variable for key derivation via
 * HKDF (SHA-256). Each ciphertext gets a unique nonce.
 */
final class EncryptionService
{
    private const CIPHER  = 'aes-256-gcm';
    private const TAG_LEN = 16;

    private string $key;

    public function __construct()
    {
        $appKey = env('ENCRYPTION_KEY', '') ?: env('APP_KEY', '');
        if ($appKey === '') {
            throw new RuntimeException('Encryption key is not configured. Set ENCRYPTION_KEY or APP_KEY.');
        }

        // Derive a 256-bit key using HKDF
        $this->key = hash_hkdf('sha256', $appKey, 32, 'synccu-encryption');
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Encrypt a plaintext string.
     *
     * Returns a base64-encoded payload containing nonce + tag + ciphertext.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '', // additional authenticated data
            self::TAG_LEN,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        // Pack: nonce (12 bytes) + tag (16 bytes) + ciphertext
        return base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypt a previously encrypted payload.
     */
    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false) {
            throw new RuntimeException('Decryption failed: invalid base64.');
        }

        $nonceLen = openssl_cipher_iv_length(self::CIPHER);
        $minLen   = $nonceLen + self::TAG_LEN + 1;

        if (strlen($raw) < $minLen) {
            throw new RuntimeException('Decryption failed: payload too short.');
        }

        $nonce      = substr($raw, 0, $nonceLen);
        $tag        = substr($raw, $nonceLen, self::TAG_LEN);
        $ciphertext = substr($raw, $nonceLen + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: authentication tag mismatch.');
        }

        return $plaintext;
    }

    /**
     * Encrypt an array by JSON-encoding then encrypting.
     */
    public function encryptArray(array $data): string
    {
        return $this->encrypt(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Decrypt back to an array.
     */
    public function decryptArray(string $payload): array
    {
        $json = $this->decrypt($payload);
        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * Hash a value for indexing (non-reversible).
     */
    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }
}
