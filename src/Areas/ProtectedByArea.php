<?php

declare(strict_types=1);

namespace Odden\Core\Areas;

use Illuminate\Database\Eloquent\Model;

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
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        $areas = app(Areas::class);

        foreach (app(DataAreas::class)->encryptedAttributesOf($this) as $attribute => $area) {
            if (array_key_exists($attribute, $attributes) && ! $areas->canSee($area, $this)) {
                $attributes[$attribute] = null;
            }
        }

        return $attributes;
    }
}
