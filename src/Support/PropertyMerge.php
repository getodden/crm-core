<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Database\Eloquent\Model;

final class PropertyMerge
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function onto(Model $record, array $attributes): array
    {
        if (! array_key_exists('properties', $attributes) || ! is_array($attributes['properties'])) {
            return $attributes;
        }

        $existing = $record->getAttribute('properties');

        $attributes['properties'] = array_merge(is_array($existing) ? $existing : [], $attributes['properties']);

        return $attributes;
    }
}
