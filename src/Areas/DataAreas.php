<?php

declare(strict_types=1);

namespace Odden\Core\Areas;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Odden\Core\Encryption\Casts\EncryptedCast;

/**
 * The classification: which area every encrypted field, and every Odden table, belongs to. Each package registers its own when it
 * boots. A test fails when an encrypted field or a table has no area, so a new one cannot be added without deciding who may see it.
 */
final class DataAreas
{
    /** @var array<string, DataArea> Field name (as in the encrypted cast, "contacts.email") => area. */
    private array $fields = [];

    /** @var array<string, DataArea> Table (without a prefix configuration, as named in the classification test) => area. */
    private array $tables = [];

    /**
     * @param  array<string, DataArea>  $fields
     */
    public function fields(array $fields): void
    {
        $this->fields = [...$this->fields, ...$fields];
    }

    /**
     * @param  array<string, DataArea>  $tables
     */
    public function tables(array $tables): void
    {
        $this->tables = [...$this->tables, ...$tables];
    }

    public function areaOfField(string $field): DataArea
    {
        return $this->fields[$field] ?? throw new InvalidArgumentException("The encrypted field [{$field}] has no data area.");
    }

    public function knowsField(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function areaOfTable(string $table): DataArea
    {
        return $this->tables[$table] ?? throw new InvalidArgumentException("The table [{$table}] has no data area.");
    }

    /**
     * @return array<string, DataArea>
     */
    public function allFields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<string, DataArea>
     */
    public function allTables(): array
    {
        return $this->tables;
    }

    /**
     * The area of one attribute of a model, read from its encrypted cast, or null when the attribute is not encrypted.
     * A property change in the history belongs to the area of the property it records.
     */
    public function areaOfAttribute(Model $model, string $attribute): ?DataArea
    {
        $cast = $model->getCasts()[$attribute] ?? null;

        if (! is_string($cast) || ! str_contains($cast, ':') || ! is_subclass_of(explode(':', $cast, 2)[0], EncryptedCast::class)) {
            return null;
        }

        $field = explode(':', $cast, 2)[1];

        if (str_starts_with($field, 'property_history.')) {
            return $this->areaOfProperty((string) $model->getAttribute('property_name'));
        }

        return $this->knowsField($field) ? $this->areaOfField($field) : null;
    }

    /**
     * @return array<string, DataArea> Attribute => area, for the encrypted attributes of a model.
     */
    public function encryptedAttributesOf(Model $model): array
    {
        $found = [];

        foreach (array_keys($model->getCasts()) as $attribute) {
            $area = $this->areaOfAttribute($model, $attribute);

            if ($area !== null) {
                $found[$attribute] = $area;
            }
        }

        return $found;
    }

    /**
     * The area a built-in property belongs to; anything else (a custom property) is Identity until a workspace marks it otherwise.
     */
    public function areaOfProperty(string $property): DataArea
    {
        return match ($property) {
            'date_of_birth' => DataArea::Sensitive,
            default => DataArea::Identity,
        };
    }
}
