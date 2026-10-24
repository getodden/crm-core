<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Casts;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\FieldEncrypter;

/**
 * What the encrypted casts share. Name the field in the cast, so the stored values do not depend on the table name:
 * `'date_of_birth' => EncryptedDate::class.':contacts.date_of_birth'`.
 *
 * A value that is not in the encrypted format (written before the column was encrypted) is read as it is while
 * `odden-core.encryption.allow_plaintext` is on, and is encrypted the next time the record is saved; the setting is for the
 * time between turning encryption on and converting the stored rows, and should be turned off after.
 */
abstract class EncryptedCast
{
    public function __construct(protected ?string $field = null) {}

    protected function read(Model $model, string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encrypter = app(FieldEncrypter::class);

        if (! $encrypter->isEncrypted((string) $value) && config('odden-core.encryption.allow_plaintext', true)) {
            return (string) $value;
        }

        return $encrypter->decrypt((string) $value, $this->field($model, $key));
    }

    protected function write(Model $model, string $key, ?string $plain): ?string
    {
        return $plain === null ? null : app(FieldEncrypter::class)->encrypt($plain, $this->field($model, $key));
    }

    private function field(Model $model, string $key): string
    {
        return $this->field ?? $model->getTable().'.'.$key;
    }
}
