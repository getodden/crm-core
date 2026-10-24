<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Odden\Core\Areas\DataArea;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Models\PropertyDefinition;

/**
 * Which custom properties the active tenant has marked sensitive, and the data area each belongs to.
 *
 * A sensitive property's value is stored encrypted inside the record's `properties` JSON (see the CustomProperties cast), is masked
 * from people who do not hold its area, and never goes to an AI provider when its area is Sensitive. Definitions are read once per
 * tenant and request (the service is scoped), and forgotten when a definition is saved or deleted.
 */
final class SensitiveProperties
{
    /** @var array<string, array<string, array<string, DataArea>>> Tenant => entity => property name => area. */
    private array $loaded = [];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * The key a record type goes by in definitions and in the field name of its encrypted values: `contact`, `company`, `deal`.
     * A definition's entity type may be the short name or the model's class or morph name.
     */
    public static function entityOf(Model|string $model): string
    {
        if ($model instanceof Model) {
            return Str::snake(class_basename($model));
        }

        $class = class_exists($model) ? $model : Relation::getMorphedModel($model);

        return $class !== null ? Str::snake(class_basename($class)) : $model;
    }

    /**
     * The name an encrypted value is bound to, so a value cannot be moved to another property or record type and still be read.
     */
    public static function fieldOf(string $entity, string $property): string
    {
        return "properties.{$entity}.{$property}";
    }

    /**
     * The area of a custom property, or null when it is not sensitive (or has no definition).
     */
    public function areaOf(Model|string $entity, string $property): ?DataArea
    {
        return $this->forEntity(self::entityOf($entity))[$property] ?? null;
    }

    public function isSensitive(Model|string $entity, string $property): bool
    {
        return $this->areaOf($entity, $property) !== null;
    }

    /**
     * The sensitive properties of one record type: name => area.
     *
     * @return array<string, DataArea>
     */
    public function forEntity(Model|string $entity): array
    {
        $entity = self::entityOf($entity);

        return $this->tenantDefinitions()[$entity] ?? [];
    }

    /**
     * The strictest area a property of this name has on any record type, for matching by name alone (a key in an AI result, a
     * property history entry). Null when no record type has a sensitive property of this name.
     */
    public function areaOfName(string $property): ?DataArea
    {
        $found = null;

        foreach ($this->tenantDefinitions() as $properties) {
            $area = $properties[$property] ?? null;

            if ($area === DataArea::Sensitive) {
                return $area;
            }

            $found ??= $area;
        }

        return $found;
    }

    /**
     * Every sensitive property name with its area, across record types (Sensitive wins when two record types disagree).
     *
     * @return array<string, DataArea>
     */
    public function namedAreas(): array
    {
        $names = [];

        foreach ($this->tenantDefinitions() as $properties) {
            foreach ($properties as $name => $area) {
                $names[$name] = ($names[$name] ?? null) === DataArea::Sensitive ? DataArea::Sensitive : $area;
            }
        }

        return $names;
    }

    /**
     * The areas of the sensitive properties this record has a value for.
     *
     * @return list<DataArea>
     */
    public function areasOn(Model $record): array
    {
        $values = $record->getAttribute('properties');
        $areas = [];

        if (! is_array($values)) {
            return $areas;
        }

        foreach ($this->forEntity($record) as $name => $area) {
            if (array_key_exists($name, $values) && $values[$name] !== null && ! in_array($area, $areas, true)) {
                $areas[] = $area;
            }
        }

        return $areas;
    }

    public function forget(): void
    {
        $this->loaded = [];
    }

    /**
     * @return array<string, array<string, DataArea>>
     */
    private function tenantDefinitions(): array
    {
        $tenant = (string) ($this->tenant->id() ?? 'install');

        if (isset($this->loaded[$tenant])) {
            return $this->loaded[$tenant];
        }

        $table = config('odden-core.tables.properties', 'odden_properties');

        // Before the migration that adds the switch has run, nothing is sensitive. Not remembered, so it is asked again afterwards.
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'is_sensitive')) {
            return [];
        }

        $found = [];

        foreach (PropertyDefinition::query()->where('is_sensitive', true)->get(['entity_type', 'name', 'data_area']) as $definition) {
            $found[self::entityOf($definition->entity_type)][$definition->name] = DataArea::tryFrom((string) $definition->data_area) ?? DataArea::Sensitive;
        }

        return $this->loaded[$tenant] = $found;
    }
}
