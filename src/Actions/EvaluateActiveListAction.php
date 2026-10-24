<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\Casts\EncryptedCast;
use Odden\Core\Encryption\Index\BlindIndexed;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\SensitiveProperties;
use Odden\Core\Exceptions\InvalidListCriteriaException;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\ListMembership;

class EvaluateActiveListAction
{
    /**
     * Operator aliases, mapped to the operator they mean. The left-hand names are the ones the
     * Filament panel saves; the documented symbols map to themselves.
     *
     * @var array<string, string>
     */
    public const OPERATORS = [
        '=' => '=',
        'equals' => '=',
        '!=' => '!=',
        'not_equals' => '!=',
        '>' => '>',
        'greater_than' => '>',
        '>=' => '>=',
        '<' => '<',
        'less_than' => '<',
        '<=' => '<=',
        'contains' => 'contains',
        'in' => 'in',
        'not_in' => 'not_in',
        'is_null' => 'is_null',
        'is_not_set' => 'is_null',
        'is_not_null' => 'is_not_null',
        'is_set' => 'is_not_null',
    ];

    /**
     * Matching records are added to the list this many at a time.
     */
    private const INSERT_CHUNK = 500;

    /**
     * Rules on encrypted columns that the search index cannot answer, collected while the criteria are applied.
     *
     * @var list<array{column: string, operator: string, value: mixed}>
     */
    private array $deferred = [];

    /**
     * Evaluate filter criteria for an active list and sync its memberships.
     *
     * @throws InvalidListCriteriaException when the entity type, a rule's property, or an operator is not recognised
     */
    public function execute(CrmList $list): int
    {
        $modelClass = $this->modelClass($list->entity_type);

        $modelInstance = new $modelClass;
        $tableColumns = Schema::getColumnListing($modelInstance->getTable());

        $query = $modelClass::query();
        $this->deferred = [];

        foreach ($list->criteria ?? [] as $rule) {
            $this->applyRule($query, $modelClass, $tableColumns, $rule);
        }

        $memberType = $modelInstance->getMorphClass();
        $keyName = $modelInstance->getKeyName();
        $qualifiedKey = $modelInstance->getQualifiedKeyName();
        $membershipsTable = (new ListMembership)->getTable();

        // Rules on encrypted columns that the search index cannot answer are checked in PHP, on the records every other rule left.
        if ($this->deferred !== []) {
            $deferred = $this->deferred;
            $columns = array_values(array_unique(array_column($deferred, 'column')));

            $ids = app(DecryptedFilter::class)->ids($query, $columns, function (array $values) use ($deferred): bool {
                foreach ($deferred as $rule) {
                    if (! $this->matches($values[$rule['column']], $rule['operator'], $rule['value'])) {
                        return false;
                    }
                }

                return true;
            });

            return $this->syncMembers($list, $memberType, $ids);
        }

        // The difference is worked out in SQL so no list of IDs is ever bound into a query,
        // and inserts ignore rows a concurrent sync already added.
        return DB::transaction(function () use ($list, $query, $memberType, $keyName, $qualifiedKey, $membershipsTable): int {
            ListMembership::query()
                ->where('list_id', $list->id)
                ->where('member_type', $memberType)
                ->whereNotIn('member_id', (clone $query)->select($qualifiedKey)->toBase())
                ->delete();

            (clone $query)
                ->select($qualifiedKey)
                ->whereNotExists(function (QueryBuilder $sub) use ($list, $memberType, $membershipsTable, $qualifiedKey): void {
                    $sub->selectRaw('1')
                        ->from($membershipsTable)
                        ->where("{$membershipsTable}.list_id", $list->id)
                        ->where("{$membershipsTable}.member_type", $memberType)
                        ->whereColumn("{$membershipsTable}.member_id", $qualifiedKey);
                })
                ->chunkById(self::INSERT_CHUNK, function (Collection $records) use ($list, $memberType, $keyName): void {
                    $now = now();

                    ListMembership::query()->insertOrIgnore($records->map(fn (Model $record): array => [
                        'list_id' => $list->id,
                        'member_type' => $memberType,
                        'member_id' => $record->getAttribute($keyName),
                        'added_at' => $now,
                    ])->all());
                }, $qualifiedKey, $keyName);

            return (clone $query)->count();
        });
    }

    /**
     * Make the list's members of this type exactly these records, when the matching was done in PHP (so no query can name them).
     *
     * @param  list<int|string>  $ids
     */
    protected function syncMembers(CrmList $list, string $memberType, array $ids): int
    {
        return DB::transaction(function () use ($list, $memberType, $ids): int {
            $wanted = array_flip(array_map('strval', $ids));
            $existing = ListMembership::query()->where('list_id', $list->id)->where('member_type', $memberType)->pluck('member_id')->map(fn ($id): string => (string) $id)->all();

            foreach (array_chunk(array_values(array_filter($existing, fn (string $id): bool => ! isset($wanted[$id]))), self::INSERT_CHUNK) as $gone) {
                ListMembership::query()->where('list_id', $list->id)->where('member_type', $memberType)->whereIn('member_id', $gone)->delete();
            }

            $now = now();
            $present = array_flip($existing);

            foreach (array_chunk(array_values(array_filter(array_map('strval', $ids), fn (string $id): bool => ! isset($present[$id]))), self::INSERT_CHUNK) as $added) {
                ListMembership::query()->insertOrIgnore(array_map(fn (string $id): array => [
                    'list_id' => $list->id,
                    'member_type' => $memberType,
                    'member_id' => $id,
                    'added_at' => $now,
                ], $added));
            }

            return count($ids);
        });
    }

    /**
     * The model an entity type names: `contact`, `company`, or either model's morph class.
     *
     * @return class-string<Contact>|class-string<Company>
     */
    protected function modelClass(?string $entityType): string
    {
        return match (true) {
            in_array($entityType, ['contact', Contact::class, (new Contact)->getMorphClass()], true) => Contact::class,
            in_array($entityType, ['company', Company::class, (new Company)->getMorphClass()], true) => Company::class,
            default => throw new InvalidListCriteriaException("Active lists cannot evaluate entity type [{$entityType}]."),
        };
    }

    /**
     * Apply one rule. Rules may name the property as `property` (documented) or `field` (the
     * Filament panel's shape).
     *
     * @param  Builder<covariant Model>  $query
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $tableColumns
     * @param  array<string, mixed>  $rule
     */
    protected function applyRule(Builder $query, string $modelClass, array $tableColumns, array $rule): void
    {
        $property = trim((string) ($rule['property'] ?? $rule['field'] ?? ''));
        $operatorName = (string) ($rule['operator'] ?? '=');
        $value = $rule['value'] ?? null;

        // A rule that names nothing would otherwise match every record.
        if ($property === '') {
            throw new InvalidListCriteriaException('Every active list rule needs a property.');
        }

        $operator = self::OPERATORS[$operatorName]
            ?? throw new InvalidListCriteriaException("Unknown active list operator [{$operatorName}].");

        // Marketing behavioral cohort criteria
        if ($property === 'has_downloaded_asset' && $modelClass === Contact::class) {
            $this->applyBehavioralRule($query, config('odden-marketing.tables.asset_downloads', 'odden_marketing_asset_downloads'), $value, null);

            return;
        }

        if ($property === 'has_attended_event' && $modelClass === Contact::class) {
            $this->applyBehavioralRule($query, config('odden-marketing.tables.event_registrations', 'odden_marketing_event_registrations'), $value, 'attended');

            return;
        }

        // A real column is matched on the column only; anything else is a custom property.
        if (in_array($property, $tableColumns, true)) {
            if ($this->isEncrypted($query->getModel(), $property)) {
                $this->applyEncryptedRule($query, $property, $operator, $value);

                return;
            }

            $this->applyRuleToQuery($query, $property, $operator, $value);
        } elseif (app(SensitiveProperties::class)->isSensitive($query->getModel(), $property)) {
            // Stored encrypted: the database cannot compare it, so every operator is checked in PHP on the records the other rules left.
            $this->deferred[] = ['column' => "properties->{$property}", 'operator' => $operator, 'value' => $value];
        } else {
            $this->applyRuleToQuery($query, "properties->{$property}", $operator, $value, jsonPath: explode('->', $property));
        }
    }

    /**
     * Rules on an encrypted column. Equals, not equals, in and not in use the search index when the column has an exact index;
     * "is set" and "is not set" ask the database (an empty value is stored as NULL either way); the rest ("contains", and equals
     * on a column without an exact index) are checked in PHP on the records the other rules left. Greater and less than are not
     * available: encrypted values have no order the database can use.
     *
     * @param  Builder<covariant Model>  $query
     *
     * @throws InvalidListCriteriaException
     */
    protected function applyEncryptedRule(Builder $query, string $property, string $operator, mixed $value): void
    {
        if (in_array($operator, ['>', '>=', '<', '<='], true)) {
            throw new InvalidListCriteriaException("The rule on [{$property}] cannot use [{$operator}]: the field is encrypted, so it can only be compared for equality, start of a word, or being set.");
        }

        if ($operator === 'is_null') {
            $query->whereNull($property);

            return;
        }

        if ($operator === 'is_not_null') {
            $query->whereNotNull($property);

            return;
        }

        $model = $query->getModel();
        $indexed = $model instanceof BlindIndexed && $model->hasExactBlindIndex($property);

        if ($indexed) {
            match ($operator) {
                '=' => $query->whereBlindExactIn($property, [(string) $value]),
                '!=' => $query->whereBlindExactNotIn($property, [(string) $value]),
                'in' => $query->whereBlindExactIn($property, array_map('strval', (array) $value)),
                'not_in' => $query->whereBlindExactNotIn($property, array_map('strval', (array) $value)),
                default => $this->deferred[] = ['column' => $property, 'operator' => $operator, 'value' => $value],
            };

            return;
        }

        $this->deferred[] = ['column' => $property, 'operator' => $operator, 'value' => $value];
    }

    /**
     * Whether a decrypted value passes a rule, compared without regard to case.
     */
    protected function matches(mixed $actual, string $operator, mixed $expected): bool
    {
        $text = fn (mixed $v): string => mb_strtolower(trim((string) $v));
        $actual = $actual === null ? null : $text($actual);

        if (in_array($operator, ['>', '>=', '<', '<='], true)) {
            // Only a sensitive property reaches here with an order (an encrypted column refuses one): compare numbers as numbers.
            if ($actual === null || ! is_numeric($actual) || ! is_numeric($expected)) {
                return false;
            }

            return match ($operator) {
                '>' => (float) $actual > (float) $expected,
                '>=' => (float) $actual >= (float) $expected,
                '<' => (float) $actual < (float) $expected,
                default => (float) $actual <= (float) $expected,
            };
        }

        return match ($operator) {
            'is_null' => $actual === null || $actual === '',
            'is_not_null' => $actual !== null && $actual !== '',
            '=' => $actual !== null && $actual === $text($expected),
            '!=' => $actual !== null && $actual !== $text($expected),
            'contains' => $actual !== null && str_contains($actual, $text($expected)),
            'in' => $actual !== null && in_array($actual, array_map($text, (array) $expected), true),
            'not_in' => $actual !== null && ! in_array($actual, array_map($text, (array) $expected), true),
            default => false,
        };
    }

    /**
     * Whether the model stores this column encrypted.
     */
    protected function isEncrypted(Model $model, string $column): bool
    {
        $cast = $model->getCasts()[$column] ?? null;

        return is_string($cast) && is_subclass_of(explode(':', $cast, 2)[0], EncryptedCast::class);
    }

    /**
     * Whether the contact has (or, for a false value, has not) a row in a Marketing table. Without
     * Marketing the table doesn't exist, so no contact has a row.
     *
     * @param  Builder<covariant Model>  $query
     */
    protected function applyBehavioralRule(Builder $query, string $table, mixed $value, ?string $status): void
    {
        $wanted = filter_var($value, FILTER_VALIDATE_BOOLEAN);

        if (! Schema::hasTable($table)) {
            if ($wanted) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $contactsTable = (new Contact)->getTable();
        $subquery = function (QueryBuilder $sub) use ($table, $contactsTable, $status): void {
            $sub->selectRaw('1')
                ->from($table)
                ->whereColumn('contact_id', "{$contactsTable}.id")
                ->when($status !== null, fn (QueryBuilder $q) => $q->where('status', $status));
        };

        if ($wanted) {
            $query->whereExists($subquery);
        } else {
            $query->whereNotExists($subquery);
        }
    }

    /**
     * Apply an individual filter rule to an Eloquent query.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>|null  $jsonPath  the keys inside `properties`, when the column is a custom property
     */
    protected function applyRuleToQuery(Builder $query, string $column, string $operator, mixed $value, ?array $jsonPath = null): void
    {
        // JSON values come back as text on PostgreSQL and MySQL, so compare numbers as numbers.
        if ($jsonPath !== null && in_array($operator, ['>', '>=', '<', '<='], true) && is_numeric($value)) {
            $this->whereNumericProperty($query, $jsonPath, $operator, $value + 0);

            return;
        }

        match ($operator) {
            'contains' => $query->where($column, 'LIKE', '%'.$value.'%'),
            'in' => $query->whereIn($column, (array) $value),
            'not_in' => $query->whereNotIn($column, (array) $value),
            'is_null' => $query->whereNull($column),
            'is_not_null' => $query->whereNotNull($column),
            default => $query->where($column, $operator, $value),
        };
    }

    /**
     * Compare a custom property as a number. A value that isn't a number reads as NULL, so it never
     * matches and a stray string never fails the cast.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $path
     */
    protected function whereNumericProperty(Builder $query, array $path, string $operator, int|float $value): void
    {
        $comparison = match ($operator) {
            '>' => '>',
            '>=' => '>=',
            '<' => '<',
            default => '<=',
        };

        $quoted = array_map(fn (string $key): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $key).'"', $path);
        $number = '^ *-{0,1}[0-9]+([.][0-9]+){0,1} *$';

        switch ($query->getModel()->getConnection()->getDriverName()) {
            case 'pgsql':
                $array = '{'.implode(',', $quoted).'}';
                $query->whereRaw(
                    "(CASE WHEN (properties #>> CAST(? AS text[])) ~ ? THEN CAST(properties #>> CAST(? AS text[]) AS numeric) END) {$comparison} ?",
                    [$array, $number, $array, $value],
                );
                break;

            case 'mysql':
            case 'mariadb':
                $jsonPath = '$.'.implode('.', $quoted);
                $query->whereRaw(
                    "(CASE WHEN json_unquote(json_extract(properties, ?)) REGEXP ? THEN CAST(json_unquote(json_extract(properties, ?)) AS DECIMAL(65,10)) END) {$comparison} ?",
                    [$jsonPath, $number, $jsonPath, $value],
                );
                break;

            default:
                $jsonPath = '$.'.implode('.', $quoted);
                $query->whereRaw(
                    "(CASE WHEN json_type(properties, ?) IN ('integer', 'real') THEN json_extract(properties, ?)"
                    ." WHEN json_type(properties, ?) = 'text' AND trim(json_extract(properties, ?)) <> ''"
                    ." AND trim(json_extract(properties, ?)) NOT GLOB '*[^0-9.-]*'"
                    ." THEN CAST(trim(json_extract(properties, ?)) AS REAL) END) {$comparison} ?",
                    [$jsonPath, $jsonPath, $jsonPath, $jsonPath, $jsonPath, $jsonPath, $value],
                );
        }
    }
}
