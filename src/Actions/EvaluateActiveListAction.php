<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        foreach ($list->criteria ?? [] as $rule) {
            $this->applyRule($query, $modelClass, $tableColumns, $rule);
        }

        $memberType = $modelInstance->getMorphClass();
        $keyName = $modelInstance->getKeyName();
        $qualifiedKey = $modelInstance->getQualifiedKeyName();
        $membershipsTable = (new ListMembership)->getTable();

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
            $this->applyRuleToQuery($query, $property, $operator, $value);
        } else {
            $this->applyRuleToQuery($query, "properties->{$property}", $operator, $value, json: true);
        }
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
     */
    protected function applyRuleToQuery(Builder $query, string $column, string $operator, mixed $value, bool $json = false): void
    {
        // JSON values come back as text on PostgreSQL and MySQL, so compare numbers as numbers.
        if ($json && in_array($operator, ['>', '>=', '<', '<='], true) && is_numeric($value)) {
            $query->whereRaw($this->numericJsonExpression($query, $column)." {$operator} ?", [$value + 0]);

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
     * A JSON value as a number, or NULL when it isn't one, so a stray string never fails the cast.
     *
     * @param  Builder<covariant Model>  $query
     */
    protected function numericJsonExpression(Builder $query, string $column): string
    {
        $wrapped = '('.$query->getQuery()->getGrammar()->wrap($column).')';
        $number = "'^ *-{0,1}[0-9]+([.][0-9]+){0,1} *$'";

        return match ($query->getConnection()->getDriverName()) {
            'pgsql' => "(CASE WHEN {$wrapped} ~ {$number} THEN CAST({$wrapped} AS numeric) END)",
            'mysql', 'mariadb' => "(CASE WHEN {$wrapped} REGEXP {$number} THEN CAST({$wrapped} AS DECIMAL(65,10)) END)",
            default => "(CASE WHEN typeof({$wrapped}) IN ('integer', 'real') THEN {$wrapped}"
                ." WHEN typeof({$wrapped}) = 'text' AND trim({$wrapped}) <> '' AND trim({$wrapped}) NOT GLOB '*[^0-9.-]*'"
                ." THEN CAST(trim({$wrapped}) AS REAL) END)",
        };
    }
}
