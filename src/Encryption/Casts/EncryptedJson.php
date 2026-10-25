<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Logging\LogScrubber;

/**
 * An array stored encrypted as JSON.
 *
 * @implements CastsAttributes<array<mixed>|null, array<mixed>|null>
 */
class EncryptedJson extends EncryptedCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        $plain = $this->read($model, $key, $value);

        if ($plain === null || $plain === '') {
            return null;
        }

        $decoded = json_decode($plain, true);
        LogScrubber::rememberLeaves($decoded);

        return is_array($decoded) ? $decoded : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $this->write($model, $key, $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
