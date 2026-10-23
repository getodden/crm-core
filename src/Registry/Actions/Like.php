<?php

declare(strict_types=1);

namespace Odden\Core\Registry\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A "contains" search that behaves the same on SQLite, MySQL/MariaDB and PostgreSQL: case-insensitive (PostgreSQL's LIKE is
 * not), with % and _ in the term taken as text, and an explicit escape character (SQLite has none by default).
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
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        return $query->where(function (Builder $q) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $q->orWhereRaw('LOWER('.$q->getGrammar()->wrap($column)." ) LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }
}
