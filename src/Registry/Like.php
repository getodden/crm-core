<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A "contains" search that finds the same rows on SQLite, MySQL/MariaDB and PostgreSQL: case-insensitive (a plain LIKE is
 * case-sensitive on PostgreSQL). A % or _ in the term acts as a wildcard, which only makes the search fuzzier.
 */
final class Like
{
    /**
     * Narrow the query to rows where any of the columns contains the term.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    public static function any(Builder $query, array $columns, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($columns, $term): void {
            foreach ($columns as $column) {
                $q->orWhereLike($column, '%'.$term.'%');
            }
        });
    }
}
