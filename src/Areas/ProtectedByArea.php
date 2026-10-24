<?php

declare(strict_types=1);

namespace Odden\Core\Areas;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\SensitiveProperties;

/**
 * For a model with encrypted attributes: what leaves it as an array or JSON (an API response, an export, a Livewire form's initial
 * state, a model handed to the assistant) leaves out the attributes of areas the signed-in person cannot see, and the person cannot
 * change those attributes either. Reading an attribute directly (`$contact->email`) is not masked, so code that works on a record
 * (sending mail, a job, matching) keeps working; what a person sees is masked by the panel.
 *
 * @mixin Model
 */
trait ProtectedByArea
{
    public static function bootProtectedByArea(): void
    {
        // A person who cannot see an area cannot change it: their change to those attributes is dropped, the rest is saved.
        static::saving(function (Model $model): void {
            if (method_exists($model, 'guardsAreaWrites') && ! $model->guardsAreaWrites()) {
                return;
            }

            $areas = app(Areas::class);

            foreach (app(DataAreas::class)->encryptedAttributesOf($model) as $attribute => $area) {
                if ($areas->canSee($area, $model->exists ? $model : null)) {
                    continue;
                }

                if ($model->exists && $model->isDirty($attribute)) {
                    $model->setAttribute($attribute, $model->getOriginal($attribute));
                } elseif (! $model->exists) {
                    $model->setAttribute($attribute, null);
                }
            }

            self::keepHiddenProperties($model, $areas);
        });
    }

    /**
     * The same for custom properties marked sensitive: a person who cannot see a property's area cannot change its value. Their
     * change to it is put back, and the other properties are saved.
     */
    private static function keepHiddenProperties(Model $model, Areas $areas): void
    {
        if (! array_key_exists('properties', $model->getCasts()) || ! $model->isDirty('properties')) {
            return;
        }

        $sensitive = app(SensitiveProperties::class)->forEntity($model);
        $properties = $model->getAttribute('properties');

        if ($sensitive === [] || ! is_array($properties)) {
            return;
        }

        $original = $model->exists ? ($model->getOriginal('properties') ?? []) : [];

        foreach ($sensitive as $name => $area) {
            if ($areas->canSee($area, $model->exists ? $model : null)) {
                continue;
            }

            if (is_array($original) && array_key_exists($name, $original)) {
                $properties[$name] = $original[$name];
            } else {
                unset($properties[$name]);
            }
        }

        $model->setAttribute('properties', $properties);
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        $areas = app(Areas::class);

        if (isset($attributes['properties']) && is_array($attributes['properties'])) {
            foreach (array_keys(app(SensitiveProperties::class)->forEntity($this)) as $name) {
                if (array_key_exists($name, $attributes['properties']) && ! $areas->canSeeProperty($this, $name)) {
                    $attributes['properties'][$name] = null;
                }
            }
        }

        foreach (app(DataAreas::class)->encryptedAttributesOf($this) as $attribute => $area) {
            if (array_key_exists($attribute, $attributes) && ! $areas->canSee($area, $this)) {
                $attributes[$attribute] = null;
            }
        }

        return $attributes;
    }
}
