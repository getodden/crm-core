<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Odden\Core\Contracts\KeyProvider;
use Odden\Core\Logging\LogScrubber;

/**
 * Encrypts and decrypts single values with the active tenant's key, and builds the keyed hashes ("blind indexes") that let an
 * encrypted column be found again by exact value.
 *
 * A stored value looks like `odden1.<key id>.<base64url(nonce + ciphertext)>`. XChaCha20-Poly1305 with a random 192-bit nonce
 * (safe to draw at random for any number of values) authenticates the value and the name of the field it belongs to, so a value
 * copied into another column, or another tenant's value, fails to decrypt instead of being read.
 */
final class FieldEncrypter
{
    private const PREFIX = 'odden1';

    public function __construct(private readonly KeyProvider $keys) {}

    /**
     * @param  string  $field  A stable name for the column, like "contacts.date_of_birth". Changing it makes stored values unreadable.
     */
    public function encrypt(?string $plain, string $field): ?string
    {
        if ($plain === null) {
            return null;
        }

        $key = $this->keys->current();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $this->context($field), $nonce, $key->bytes());

        return self::PREFIX.'.'.$key->id.'.'.rtrim(strtr(base64_encode($nonce.$cipher), '+/', '-_'), '=');
    }

    /**
     * @throws DecryptionException When the value is not one of ours, was altered, belongs elsewhere or its key is unknown.
     */
    public function decrypt(?string $stored, string $field): ?string
    {
        if ($stored === null) {
            return null;
        }

        $parts = explode('.', $stored, 3);

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            throw new DecryptionException('The value is not encrypted in the expected format.');
        }

        $key = $this->keys->find($parts[1]);
        $raw = base64_decode(strtr($parts[2], '-_', '+/'), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($key === null || $raw === false || strlen($raw) <= $nonceLength) {
            throw new DecryptionException('The value cannot be read: its key is unknown or it is damaged.');
        }

        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceLength), $this->context($field), substr($raw, 0, $nonceLength), $key->bytes());

        if ($plain === false) {
            throw new DecryptionException('The value cannot be read: it was changed or belongs to another field or tenant.');
        }

        // Remembered so the logs can be kept free of it (see LogScrubber).
        LogScrubber::remember($plain);

        return $plain;
    }

    /**
     * Whether a stored value is in our format (as opposed to a plain value from before the column was encrypted).
     */
    public function isEncrypted(?string $stored): bool
    {
        return is_string($stored) && str_starts_with($stored, self::PREFIX.'.');
    }

    /**
     * A keyed hash of a value, for finding records by exact value in an encrypted column. The key is the tenant's and the field's
     * own, so the same email gives a different hash in another tenant and in another field. The caller normalizes the value first
     * (lower-case an email, keep only the digits of a phone number) so equal values give equal hashes.
     *
     * @param  string  $kind  Which index of the field, such as "exact" or "word".
     */
    public function blindIndex(string $value, string $field, string $kind = 'exact'): string
    {
        $indexKey = $this->keys->current()->derive('blind-index');

        return hash_hmac('sha256', $kind."\0".$field."\0".$value, $indexKey);
    }

    private function context(string $field): string
    {
        return self::PREFIX.':'.$field;
    }
}
