<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Index;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Odden\Core\Encryption\Casts\EncryptedCast;
use Odden\Core\Encryption\DecryptionException;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Encryption\SensitiveProperties;

/**
 * The fallback for what the search index cannot answer: read the candidate records, decrypt them in PHP and filter or sort there.
 * It is bounded (see FallbackLimit), and the candidates should be narrowed by every plain filter and index lookup first.
 */
final class DecryptedFilter
{
    private const int CHUNK = 1000;

    public function __construct(private readonly FallbackLimit $limit, private readonly FieldEncrypter $encrypter) {}

    /**
     * The ids of the candidates the test accepts.
     *
     * @param  Builder<covariant Model>  $candidates
     * @param  list<string>  $columns  The columns the test reads. `properties->name` reads a custom property (decrypted when the property is sensitive).
     * @param  Closure(array<string, string|null>): bool  $accept  Given the decrypted values by column name.
     * @return list<int|string>
     *
     * @throws TooManyToFilter
     */
    public function ids(Builder $candidates, array $columns, Closure $accept): array
    {
        $accepted = [];

        $this->each($candidates, $columns, function (int|string $id, array $values) use ($accept, &$accepted): void {
            if ($accept($values)) {
                $accepted[] = $id;
            }
        });

        return $accepted;
    }

    /**
     * How many candidates have each decrypted value of a column (null counts under the empty string), most frequent first.
     *
     * @param  Builder<covariant Model>  $candidates
     * @return array<string, int>
     *
     * @throws TooManyToFilter
     */
    public function tally(Builder $candidates, string $column): array
    {
        $counts = [];

        $this->each($candidates, [$column], function (int|string $id, array $values) use ($column, &$counts): void {
            $value = (string) $values[$column];
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        });

        uksort($counts, fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return $counts;
    }

    /**
     * The candidates' ids ordered by decrypted columns (case-insensitive, nulls last), for ordering a query with orderByIds().
     *
     * @param  Builder<covariant Model>  $candidates
     * @param  list<string>  $columns  Sort by these in turn.
     * @return list<int|string>
     *
     * @throws TooManyToFilter
     */
    public function sortedIds(Builder $candidates, array $columns, bool $descending = false): array
    {
        $rows = [];

        $this->each($candidates, $columns, function (int|string $id, array $values) use ($columns, &$rows): void {
            $rows[] = [
                'id' => $id,
                'key' => array_map(fn (string $column): ?string => ($value = $values[$column]) === null ? null : mb_strtolower($value), $columns),
            ];
        });

        usort($rows, function (array $a, array $b) use ($descending): int {
            foreach ($a['key'] as $i => $left) {
                $right = $b['key'][$i];

                if ($left === $right) {
                    continue;
                }

                if ($left === null || $right === null) {
                    return $left === null ? 1 : -1;
                }

                $order = strnatcasecmp($left, $right);

                if ($order !== 0) {
                    return $descending ? -$order : $order;
                }
            }

            return $a['id'] <=> $b['id'];
        });

        return array_column($rows, 'id');
    }

    /**
     * Order a query by a list of ids (the order of the list), in SQL that runs on every supported database. Fine for a few thousand
     * ids; a table that pages through more should page the sorted ids itself instead of sending them all to the database.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int|string>  $ids
     * @return Builder<TModel>
     */
    public static function orderByIds(Builder $query, array $ids): Builder
    {
        if ($ids === []) {
            return $query;
        }

        return $query->orderBy(new IdOrder($query->getModel()->getQualifiedKeyName(), $ids));
    }

    /**
     * One custom property of a record, as text: a value stored encrypted is decrypted (it is the property's own field name that
     * authenticates it); anything else is the plain value. An array or object value is its JSON.
     */
    private function property(mixed $stored, string $entity, string $name, Model $model, int|string $id): ?string
    {
        if ($stored !== null && is_string($stored) && $this->encrypter->isEncrypted($stored)) {
            try {
                $stored = json_decode((string) $this->encrypter->decrypt($stored, SensitiveProperties::fieldOf($entity, $name)), true);
            } catch (DecryptionException) {
                // Text that only looks like ours, in a property that is not sensitive, is a plain value.
                if (app(SensitiveProperties::class)->isSensitive($entity, $name)) {
                    throw new DecryptionException(sprintf('%s #%s: the property [%s] cannot be read.', class_basename($model), $id, $name));
                }
            }
        }

        return match (true) {
            $stored === null => null,
            is_bool($stored) => $stored ? '1' : '0',
            is_scalar($stored) => (string) $stored,
            default => (string) json_encode($stored),
        };
    }

    /**
     * Reads the candidates' stored values (not models: hydrating tens of thousands of models costs more than decrypting them) and
     * hands each record's decrypted values to the callback.
     *
     * @param  Builder<covariant Model>  $candidates
     * @param  list<string>  $columns
     * @param  Closure(int|string, array<string, string|null>): void  $each
     */
    private function each(Builder $candidates, array $columns, Closure $each): void
    {
        $limit = $this->limit->records();
        $query = (clone $candidates)->setEagerLoads([])->reorder();
        $count = (clone $query)->toBase()->getCountForPagination();

        if ($count > $limit) {
            throw new TooManyToFilter($count, $limit);
        }

        $model = $query->getModel();
        $fields = [];

        $properties = [];

        foreach ($columns as $column) {
            if (str_starts_with($column, 'properties->')) {
                $properties[$column] = substr($column, strlen('properties->'));

                continue;
            }

            $cast = $model->getCasts()[$column] ?? null;
            $fields[$column] = is_string($cast) && is_subclass_of(explode(':', $cast, 2)[0], EncryptedCast::class)
                ? (explode(':', $cast, 2)[1] ?? $model->getTable().'.'.$column)
                : null;
        }

        $allowPlain = (bool) config('odden-core.encryption.allow_plaintext', true);
        $key = $model->getQualifiedKeyName();

        $entity = SensitiveProperties::entityOf($model);
        $selected = [$key.' as '.$model->getKeyName(), ...array_map(fn (string $column): string => $model->qualifyColumn($column).' as '.$column, array_keys($fields))];

        if ($properties !== []) {
            $selected[] = $model->qualifyColumn('properties').' as properties';
        }

        $query->toBase()
            ->select($selected)
            ->chunkById(self::CHUNK, function (Collection $rows) use ($each, $fields, $properties, $entity, $allowPlain, $model): void {
                foreach ($rows as $row) {
                    $values = [];

                    if ($properties !== []) {
                        $stored = json_decode((string) ($row->properties ?? ''), true);

                        foreach ($properties as $column => $name) {
                            $values[$column] = $this->property(is_array($stored) ? ($stored[$name] ?? null) : null, $entity, $name, $model, $row->{$model->getKeyName()});
                        }
                    }

                    foreach ($fields as $column => $field) {
                        $stored = $row->{$column};

                        if ($stored === null || $field === null || ($allowPlain && ! $this->encrypter->isEncrypted((string) $stored))) {
                            $values[$column] = $stored === null ? null : (string) $stored;

                            continue;
                        }

                        try {
                            $values[$column] = $this->encrypter->decrypt((string) $stored, $field);
                        } catch (DecryptionException) {
                            throw new DecryptionException(sprintf('%s #%s: [%s] cannot be read.', class_basename($model), $row->{$model->getKeyName()}, $column));
                        }
                    }

                    $each($row->{$model->getKeyName()}, $values);
                }
            }, $key, $model->getKeyName());
    }
}
