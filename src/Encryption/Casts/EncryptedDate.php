<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Casts;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * A date (no time of day) stored encrypted. Read as a Carbon date at midnight; written as Y-m-d.
 *
 * @implements CastsAttributes<CarbonInterface|null, mixed>
 */
class EncryptedDate extends EncryptedCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonInterface
    {
        $plain = $this->read($model, $key, $value);

        return $plain === null || $plain === '' ? null : Date::parse($plain)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->write($model, $key, Date::parse($value)->toDateString());
    }
}
