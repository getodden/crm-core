<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\DecryptionException;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Encryption\SensitiveProperties;

/**
 * The `properties` column of a record: a JSON object of custom property values. Values of properties the workspace marked sensitive
 * are stored encrypted, one by one, as the same `odden1.…` text the encrypted columns use, so the keys and the other values stay
 * readable to the database (and to list rules) while a sensitive value does not. Each is bound to its record type and name, so a value
 * moved to another property fails to decrypt instead of being read.
 *
 * Reading decrypts any value that is stored encrypted, whether or not the property is still marked sensitive, so the time between
 * flipping the switch and converting the stored rows (SensitivePropertyConverter) never shows ciphertext. A plain value in a
 * sensitive property is read as it is for the same reason, and is encrypted the next time the record is saved.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|string|null>
 */
class CustomProperties implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return null;
        }

        $encrypter = app(FieldEncrypter::class);

        foreach ($decoded as $name => $stored) {
            if (is_string($stored) && $encrypter->isEncrypted($stored)) {
                $decoded[$name] = $this->reveal($model, (string) $name, $stored, $encrypter);
            }
        }

        return $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $properties = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($properties)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        $sensitive = app(SensitiveProperties::class)->forEntity($model);

        if ($sensitive !== []) {
            $encrypter = app(FieldEncrypter::class);
            $stored = $this->stored($model, $key);

            foreach ($properties as $name => $plain) {
                if (! isset($sensitive[$name]) || $plain === null) {
                    continue;
                }

                $properties[$name] = $this->seal($model, (string) $name, $plain, $stored[$name] ?? null, $encrypter);
            }
        }

        return json_encode($properties, JSON_THROW_ON_ERROR);
    }

    /**
     * The value as it was stored for this record, key by key, for keeping the stored bytes of a value that has not changed.
     *
     * @return array<string, mixed>
     */
    private function stored(Model $model, string $key): array
    {
        $raw = $model->getRawOriginal($key);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Encrypt one value. Each encryption of the same value gives different bytes, so a value that has not changed keeps the bytes it
     * has (otherwise saving a record would look like a change to every sensitive property, and write a history entry for each).
     */
    private function seal(Model $model, string $name, mixed $plain, mixed $stored, FieldEncrypter $encrypter): string
    {
        $field = SensitiveProperties::fieldOf(SensitiveProperties::entityOf($model), $name);
        $json = json_encode($plain, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (is_string($stored) && $encrypter->isEncrypted($stored)) {
            try {
                if (hash_equals($encrypter->decrypt($stored, $field) ?? '', $json)) {
                    return $stored;
                }
            } catch (DecryptionException) {
                // Unreadable: replace it.
            }
        }

        return (string) $encrypter->encrypt($json, $field);
    }

    /**
     * One stored value in the clear. A value that does not decrypt is read as it is when its property is not sensitive (it is text
     * that happens to look like ours), and is an error when it is (it was altered, or belongs to another property or workspace).
     */
    private function reveal(Model $model, string $name, string $stored, FieldEncrypter $encrypter): mixed
    {
        try {
            $json = $encrypter->decrypt($stored, SensitiveProperties::fieldOf(SensitiveProperties::entityOf($model), $name));
        } catch (DecryptionException $e) {
            if (app(SensitiveProperties::class)->isSensitive($model, $name)) {
                throw new DecryptionException(sprintf('%s #%s: the property [%s] cannot be read.', class_basename($model), $model->getKey() ?? 'new', $name), 0, $e);
            }

            return $stored;
        }

        return json_decode((string) $json, true);
    }
}
