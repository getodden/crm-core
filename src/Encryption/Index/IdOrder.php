<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Index;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * An ORDER BY expression that puts rows in the order of a list of ids, the same on every supported database. Ids not in the list
 * come last.
 *
 * A flat `CASE id WHEN 7 THEN 0 WHEN 3 THEN 1 ...` compares every row with every id in turn, which is far too slow for thousands of
 * rows, so the ids are arranged as a balanced tree of range tests (`CASE WHEN id < 5000 THEN (...) ELSE (...) END`) with a few ids
 * in each leaf: each row takes about log2(n) steps. The ids are integers, written into the SQL as such.
 */
final class IdOrder implements Expression
{
    private const int LEAF = 6;

    /** @var list<array{int, int}> [id, position], by id. */
    private readonly array $pairs;

    /**
     * @param  list<int|string>  $ids
     */
    public function __construct(private readonly string $qualifiedKey, array $ids)
    {
        $pairs = [];

        foreach ($ids as $position => $id) {
            $pairs[] = [(int) $id, $position];
        }

        usort($pairs, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $this->pairs = $pairs;
    }

    public function getValue(Grammar $grammar): string
    {
        return $this->branch($grammar->wrap($this->qualifiedKey), $this->pairs);
    }

    /**
     * @param  list<array{int, int}>  $pairs
     */
    private function branch(string $column, array $pairs): string
    {
        if (count($pairs) <= self::LEAF) {
            $cases = '';

            foreach ($pairs as [$id, $position]) {
                $cases .= ' WHEN '.$id.' THEN '.$position;
            }

            return 'CASE '.$column.$cases.' ELSE '.count($this->pairs).' END';
        }

        $half = intdiv(count($pairs), 2);
        $upper = array_slice($pairs, $half);

        return 'CASE WHEN '.$column.' < '.$upper[0][0].' THEN ('.$this->branch($column, array_slice($pairs, 0, $half)).') ELSE ('.$this->branch($column, $upper).') END';
    }
}
