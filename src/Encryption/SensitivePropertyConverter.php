<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\PropertyDefinition;
use Odden\Core\Support\ModelRegistry;
use Odden\Core\Traits\HasCustomProperties;

/**
 * Makes the stored values of a custom property match its definition: encrypted while the property is marked sensitive, plain while it is
 * not. It works on the stored JSON of each record (no models, no casts), so it can be run again at any point: a value already in the
 * right form is left alone, and a run that stopped half way is finished by the next. It uses the active tenant's key and sees the active
 * tenant's rows only (the models' own scopes apply), so a multi-tenant host runs it once for each tenant, inside that tenant.
 *
 * A value is "encrypted" for this purpose only when it decrypts under the property's own field name. Text that merely looks like our
 * format is a plain value, and is encrypted like any other.
 */
final class SensitivePropertyConverter
{
    private const int CHUNK = 200;

    public function __construct(private readonly FieldEncrypter $encrypter, private readonly ModelRegistry $models) {}

    /**
     * Bring every record type's values in line with every definition.
     *
     * @return array<string, int> "entity.name" => values changed.
     */
    public function reconcileAll(): array
    {
        $done = [];

        foreach (PropertyDefinition::query()->orderBy('id')->get() as $definition) {
            $done[SensitivePropertyConverter::label($definition)] = $this->reconcile($definition);
        }

        return $done;
    }

    /**
     * Bring the stored values of one property in line with its definition.
     *
     * @return int How many values were changed.
     */
    public function reconcile(PropertyDefinition $definition): int
    {
        return $this->convert(SensitiveProperties::entityOf($definition->entity_type), $definition->name, (bool) $definition->is_sensitive);
    }

    /**
     * @param  bool  $encrypted  The form the values should be in.
     * @return int How many values were changed.
     */
    public function convert(string $entity, string $name, bool $encrypted): int
    {
        $model = $this->modelFor($entity);

        if ($model === null) {
            return 0;
        }

        $changed = 0;
        $field = SensitiveProperties::fieldOf($entity, $name);
        $table = $model->getTable();
        $key = $model->getKeyName();

        $query = $model->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->toBase()
            ->whereNotNull($table.'.properties')
            ->select([$table.'.'.$key]);

        $query->chunkById(self::CHUNK, function ($rows) use ($table, $key, $name, $field, $encrypted, &$changed): void {
            DB::transaction(function () use ($rows, $table, $key, $name, $field, $encrypted, &$changed): void {
                // Locked while read and rewritten, so a save of the same record in between cannot be undone.
                $current = DB::table($table)->whereIn($key, $rows->pluck($key)->all())->lockForUpdate()->get([$key, 'properties']);

                foreach ($current as $row) {
                    $properties = json_decode((string) $row->properties, true);

                    if (! is_array($properties) || ! array_key_exists($name, $properties) || $properties[$name] === null) {
                        continue;
                    }

                    $converted = $encrypted ? $this->seal($properties[$name], $field) : $this->open($properties[$name], $field);

                    if ($converted === null) {
                        continue;
                    }

                    $properties[$name] = $converted[0];
                    DB::table($table)->where($key, $row->{$key})->update(['properties' => json_encode($properties)]);
                    $changed++;
                }
            });
        }, $table.'.'.$key, $key);

        return $changed;
    }

    /**
     * How many stored values of a property are not in the form its definition asks for: what is left to convert.
     */
    public function pending(PropertyDefinition $definition): int
    {
        $entity = SensitiveProperties::entityOf($definition->entity_type);
        $model = $this->modelFor($entity);

        if ($model === null) {
            return 0;
        }

        $field = SensitiveProperties::fieldOf($entity, $definition->name);
        $wantEncrypted = (bool) $definition->is_sensitive;
        $pending = 0;

        $model->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->toBase()
            ->whereNotNull($model->getTable().'.properties')
            ->select([$model->getTable().'.'.$model->getKeyName().' as id', $model->getTable().'.properties'])
            ->chunkById(1000, function ($rows) use ($definition, $field, $wantEncrypted, &$pending): void {
                foreach ($rows as $row) {
                    $properties = json_decode((string) $row->properties, true);
                    $value = is_array($properties) ? ($properties[$definition->name] ?? null) : null;

                    if ($value !== null && $this->isOurs($value, $field) !== $wantEncrypted) {
                        $pending++;
                    }
                }
            }, $model->getTable().'.'.$model->getKeyName(), 'id');

        return $pending;
    }

    public static function label(PropertyDefinition $definition): string
    {
        return SensitiveProperties::entityOf($definition->entity_type).'.'.$definition->name;
    }

    /**
     * @return array{string}|null The value to store, or null when it is already encrypted.
     */
    private function seal(mixed $value, string $field): ?array
    {
        if ($this->isOurs($value, $field)) {
            return null;
        }

        return [(string) $this->encrypter->encrypt(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $field)];
    }

    /**
     * @return array{mixed}|null The value to store, or null when it is already plain.
     */
    private function open(mixed $value, string $field): ?array
    {
        if (! $this->isOurs($value, $field)) {
            return null;
        }

        return [json_decode((string) $this->encrypter->decrypt($value, $field), true)];
    }

    private function isOurs(mixed $value, string $field): bool
    {
        if (! is_string($value) || ! $this->encrypter->isEncrypted($value)) {
            return false;
        }

        try {
            $this->encrypter->decrypt($value, $field);

            return true;
        } catch (DecryptionException) {
            return false;
        }
    }

    private function modelFor(string $entity): ?Model
    {
        foreach ($this->models->models() as $class) {
            if (SensitiveProperties::entityOf($class) === $entity && in_array(HasCustomProperties::class, class_uses_recursive($class), true)) {
                return new $class;
            }
        }

        return null;
    }
}
