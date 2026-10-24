<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Odden\Core\Encryption\Casts\EncryptedAmount;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\FallbackLimit;
use Odden\Core\Encryption\Index\TooManyToFilter;
use stdClass;

/**
 * Totals, averages and orders over money figures that a workspace may keep encrypted (see EncryptedAmount).
 *
 * Every figure of the query that is stored plain is added up by the database, in one query. The figures stored encrypted are read,
 * decrypted and added up here, and only when there are any, so a workspace that does not encrypt business data pays one extra
 * `count` column and nothing else. Above the fallback limit (the plan's record cap on Cloud) the query is refused with
 * TooManyToFilter and the caller says so. The query may be filtered and joined as it likes; it must be over a model whose column has the
 * EncryptedAmount cast.
 *
 * Nothing here is cached and nothing keeps a decrypted figure past the call.
 */
final class EncryptedAmounts
{
    private const int CHUNK = 1000;

    public function __construct(private readonly FieldEncrypter $encrypter, private readonly FallbackLimit $limit) {}

    /**
     * The sum of a figure over the records of a query.
     *
     * @param  Builder<covariant Model>  $query
     *
     * @throws TooManyToFilter
     */
    public function sum(Builder $query, string $column): float
    {
        return (float) ($this->totals($query, $column)->first()->total ?? 0.0);
    }

    /**
     * The average of a figure over the records of a query (0.0 when there are none).
     *
     * @param  Builder<covariant Model>  $query
     *
     * @throws TooManyToFilter
     */
    public function average(Builder $query, string $column): float
    {
        $row = $this->totals($query, $column)->first();

        return $row === null || (int) $row->records === 0 ? 0.0 : (float) $row->total / (int) $row->records;
    }

    /**
     * The sum of a figure times a factor held in another column of the same row or of a joined table (a stage's win probability).
     *
     * @param  Builder<covariant Model>  $query  Must already join the table the factor column is on.
     * @param  string  $factor  Qualified column, such as "odden_pipeline_stages.probability".
     *
     * @throws TooManyToFilter
     */
    public function weighted(Builder $query, string $column, string $factor): float
    {
        return (float) ($this->totals($query, $column, factor: $factor)->first()->total ?? 0.0);
    }

    /**
     * Sums and record counts per group.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $groupBy  Qualified columns.
     * @return Collection<int, stdClass> Each row also has the group columns, named as the part after the last dot.
     *
     * @throws TooManyToFilter
     */
    public function grouped(Builder $query, string $column, array $groupBy): Collection
    {
        return $this->totals($query, $column, groupBy: $groupBy);
    }

    /**
     * The ids of the records ordered by a figure, largest first unless told otherwise, optionally only those at or above a minimum,
     * and cut to a limit. Plain figures only are ordered by the database; as soon as one is stored encrypted the figures are read and
     * ordered here.
     *
     * @param  Builder<covariant Model>  $query
     * @return list<int|string>
     *
     * @throws TooManyToFilter
     */
    public function orderedIds(Builder $query, string $column, bool $descending = true, ?float $atLeast = null, ?int $limit = null): array
    {
        $model = $query->getModel();
        $key = $model->getKeyName();
        $rows = [];

        $this->each($query, $column, function (int|string $id, float $amount) use (&$rows, $atLeast): void {
            if ($atLeast === null || $amount >= $atLeast) {
                $rows[] = [$id, $amount];
            }
        });

        usort($rows, fn (array $a, array $b): int => ($descending ? $b[1] <=> $a[1] : $a[1] <=> $b[1]) ?: $a[0] <=> $b[0]);

        $ids = array_column($rows, 0);

        return $limit === null ? $ids : array_slice($ids, 0, $limit);
    }

    /**
     * The query cut to the largest (or smallest) figures: filtered to those at or above a minimum, ordered, and limited. The database
     * does it while every figure is plain; as soon as one is stored encrypted the figures are read and ordered here.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     *
     * @throws TooManyToFilter
     */
    public function top(Builder $query, string $column, int $limit, ?float $atLeast = null, bool $descending = true): Builder
    {
        $model = $query->getModel();
        $qualified = $model->qualifyColumn($column);

        if (! $this->anyEncrypted($query, $column)) {
            return $query
                ->when($atLeast !== null, fn (Builder $q) => $q->where($qualified, '>=', $atLeast))
                ->orderBy($qualified, $descending ? 'desc' : 'asc')
                ->limit($limit);
        }

        $ids = $this->orderedIds($query, $column, $descending, $atLeast, $limit);
        $top = (clone $query)->reorder()->setEagerLoads($query->getEagerLoads());

        return $ids === []
            ? $top->whereRaw('1 = 0')
            : DecryptedFilter::orderByIds($top->whereIn($model->getQualifiedKeyName(), $ids), $ids);
    }

    /**
     * Whether any record of the query keeps this figure encrypted.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function anyEncrypted(Builder $query, string $column): bool
    {
        $model = $query->getModel();

        return (clone $query)->reorder()->whereNotNull($model->qualifyColumn(EncryptedAmount::companion($column)))->exists();
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $groupBy
     * @return Collection<int, stdClass>
     */
    private function totals(Builder $query, string $column, ?string $factor = null, array $groupBy = []): Collection
    {
        $model = $query->getModel();
        $amount = $model->qualifyColumn($column);
        $companion = $model->qualifyColumn(EncryptedAmount::companion($column));

        $base = (clone $query)->reorder()->setEagerLoads([])->toBase();
        $base->columns = null;
        $base->orders = null;

        foreach ($groupBy as $group) {
            $base->addSelect($group);
        }

        $rows = $base
            ->addSelect(AmountTotal::plain($amount, $companion, $factor, 'plain_total'))
            ->addSelect(AmountTotal::encryptedRecords($amount, $companion, 'encrypted_records'))
            ->selectRaw('count(*) as records')
            ->groupBy(...$groupBy)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $row->total = (float) $row->plain_total;
            $row->records = (int) $row->records;
            $out[$this->groupKey($row, $groupBy)] = $row;
        }

        if ($out === [] && $groupBy === []) {
            $out[''] = (object) ['total' => 0.0, 'records' => 0];
        }

        if ($rows->sum('encrypted_records') > 0) {
            $this->each($query, $column, function (int|string $id, float $amount, object $row) use ($out, $groupBy, $factor): void {
                $group = $out[$this->groupKey($row, $groupBy)] ?? null;

                if ($group !== null) {
                    $group->total += $amount * ($factor === null ? 1.0 : (float) ($row->factor ?? 0));
                }
            }, $factor, $groupBy, onlyEncrypted: true);
        }

        foreach ($out as $row) {
            unset($row->plain_total, $row->encrypted_records);
        }

        return new Collection(array_values($out));
    }

    /**
     * Hand each record's figure to the callback: the ones stored encrypted are decrypted, the others are read as they are (with
     * onlyEncrypted, only the encrypted ones are read at all).
     *
     * @param  Builder<covariant Model>  $query
     * @param  callable(int|string, float, object): void  $each
     * @param  list<string>  $groupBy
     *
     * @throws TooManyToFilter
     */
    private function each(Builder $query, string $column, callable $each, ?string $factor = null, array $groupBy = [], bool $onlyEncrypted = false): void
    {
        $model = $query->getModel();
        $field = $this->fieldOf($model, $column);
        $companionColumn = EncryptedAmount::companion($column);
        $limit = $this->limit->amounts();

        $candidates = (clone $query)->reorder()->setEagerLoads([]);

        if ($onlyEncrypted) {
            $candidates->whereNotNull($model->qualifyColumn($companionColumn));
        }

        $count = (clone $candidates)->toBase()->getCountForPagination();

        if ($count > $limit) {
            throw new TooManyToFilter($count, $limit);
        }

        $qualifiedKey = $model->getQualifiedKeyName();
        $select = [$qualifiedKey.' as '.$model->getKeyName(), $model->qualifyColumn($column).' as '.$column, $model->qualifyColumn($companionColumn).' as '.$companionColumn];

        foreach ($groupBy as $group) {
            $select[] = $group;
        }

        if ($factor !== null) {
            $select[] = $factor.' as factor';
        }

        $candidates->toBase()->select($select)->chunkById(self::CHUNK, function (Collection $rows) use ($each, $column, $companionColumn, $field, $model): void {
            foreach ($rows as $row) {
                $stored = $row->{$companionColumn};
                $amount = is_string($stored) && $stored !== ''
                    ? (float) $this->encrypter->decrypt($stored, $field)
                    : (float) $row->{$column};

                $each($row->{$model->getKeyName()}, $amount, $row);
            }
        }, $qualifiedKey, $model->getKeyName());
    }

    /**
     * @param  list<string>  $groupBy
     */
    private function groupKey(object $row, array $groupBy): string
    {
        return implode("\0", array_map(fn (string $group): string => (string) ($row->{$this->short($group)} ?? ''), $groupBy));
    }

    private function short(string $qualified): string
    {
        $parts = explode('.', $qualified);

        return str_replace(['"', '`'], '', end($parts));
    }

    private function fieldOf(Model $model, string $column): string
    {
        $cast = $model->getCasts()[$column] ?? '';

        return str_contains($cast, ':') ? explode(':', $cast, 2)[1] : $model->getTable().'.'.$column;
    }
}
