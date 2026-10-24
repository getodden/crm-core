<?php

declare(strict_types=1);

namespace Odden\Core\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Odden\Core\Encryption\Casts\CustomProperties;
use Odden\Core\Encryption\SensitiveProperties;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Exceptions\SensitivePropertyNotQueryable;
use Odden\Core\Models\PropertyDefinition;

trait HasCustomProperties
{
    /**
     * Initialize custom properties attribute casting.
     */
    public function initializeHasCustomProperties(): void
    {
        $this->mergeCasts([
            'properties' => CustomProperties::class,
        ]);
    }

    /**
     * Retrieve a specific custom property value.
     */
    public function getProperty(string $name, mixed $default = null): mixed
    {
        $properties = $this->properties ?? [];

        return data_get($properties, $name, $default);
    }

    /**
     * Set a custom property value.
     */
    public function setProperty(string $name, mixed $value): static
    {
        $properties = $this->properties ?? [];
        $properties[$name] = $value;
        $this->properties = $properties;

        return $this;
    }

    /**
     * Merge multiple custom properties.
     *
     * Pass `validate: true` to check the merged properties against this record type's
     * PropertyDefinitions first; see validateProperties().
     *
     * @param  array<string, mixed>  $properties
     *
     * @throws ValidationException
     */
    public function setProperties(array $properties, bool $validate = false): static
    {
        $merged = array_merge($this->properties ?? [], $properties);

        if ($validate) {
            $this->validateProperties($merged);
        }

        $this->properties = $merged;

        return $this;
    }

    /**
     * Validate properties (this record's own by default) against its PropertyDefinitions.
     *
     * Definitions match on `entity_type` equal to the record's morph class or its short snake_case
     * class name (`contact`, `company`, `deal`), which is what the Filament panel stores.
     *
     * Only properties that have a definition are checked: `is_required`, then a rule for the
     * definition's `type`, and for select types the keys of `options` (or its `choices` list).
     * Properties without a definition are accepted as they are.
     *
     * @param  array<string, mixed>|null  $properties
     * @return array<string, mixed> The validated properties.
     *
     * @throws ValidationException
     */
    public function validateProperties(?array $properties = null): array
    {
        $properties ??= $this->properties ?? [];

        if (! Schema::hasTable(config('odden-core.tables.properties', 'odden_properties'))) {
            return $properties;
        }

        $rules = [];
        $labels = [];

        $entityTypes = [$this->getMorphClass(), Str::snake(class_basename($this))];

        foreach (PropertyDefinition::query()->whereIn('entity_type', $entityTypes)->orderBy('sort_order')->get() as $definition) {
            $key = "properties.{$definition->name}";
            $rules[$key] = $this->propertyRulesFor($definition);
            $labels[$key] = $definition->label;

            if ($definition->type === PropertyType::MultiSelect && $this->propertyChoices($definition) !== []) {
                $rules["{$key}.*"] = [Rule::in($this->propertyChoices($definition))];
            }
        }

        if ($rules === []) {
            return $properties;
        }

        Validator::make(['properties' => $properties], $rules, [], $labels)->validate();

        return $properties;
    }

    /**
     * The values a select property accepts: the keys of `options`, or its `choices` list.
     *
     * @return list<int|string>
     */
    protected function propertyChoices(PropertyDefinition $definition): array
    {
        $choices = $definition->options['choices'] ?? $definition->options ?? [];

        return array_is_list($choices) ? $choices : array_keys($choices);
    }

    /**
     * @return list<mixed>
     */
    protected function propertyRulesFor(PropertyDefinition $definition): array
    {
        $rules = [$definition->is_required ? 'required' : 'nullable'];

        $allowed = $this->propertyChoices($definition);

        array_push($rules, ...match ($definition->type) {
            PropertyType::Text => ['string'],
            PropertyType::Number => ['numeric'],
            PropertyType::Boolean => ['boolean'],
            PropertyType::Select => $allowed === [] ? [] : [Rule::in($allowed)],
            PropertyType::MultiSelect => ['array'],
            PropertyType::Date, PropertyType::DateTime => ['date'],
            PropertyType::Json => ['array'],
        });

        return $rules;
    }

    /**
     * Scope query to records matching a custom property value.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereProperty(Builder $query, string $name, mixed $value): Builder
    {
        // A sensitive value is stored encrypted, so a match in SQL would find nothing and look like "no such records".
        if (app(SensitiveProperties::class)->isSensitive($this, $name)) {
            throw new SensitivePropertyNotQueryable($name);
        }

        return $query->where("properties->{$name}", $value);
    }
}
