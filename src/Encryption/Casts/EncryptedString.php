<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A text column stored encrypted with the active tenant's key.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class EncryptedString extends EncryptedCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $this->read($model, $key, $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $this->write($model, $key, $value === null ? null : (string) $value);
    }
}
