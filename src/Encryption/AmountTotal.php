<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * A SELECT expression that adds up a money figure in the database for the rows that are stored plain, or counts the rows that are stored
 * encrypted. Column names are wrapped by the connection's grammar when it is compiled, so they are always quoted.
 */
final class AmountTotal implements Expression
{
    /**
     * @param  string  $amount  Qualified plain column.
     * @param  string  $companion  Qualified encrypted companion column.
     * @param  string|null  $factor  Qualified column to multiply each plain figure by.
     */
    private function __construct(private readonly string $kind, private readonly string $amount, private readonly string $companion, private readonly ?string $factor, private readonly string $alias) {}

    public static function plain(string $amount, string $companion, ?string $factor, string $alias): self
    {
        return new self('plain', $amount, $companion, $factor, $alias);
    }

    public static function encryptedRecords(string $amount, string $companion, string $alias): self
    {
        return new self('encrypted', $amount, $companion, null, $alias);
    }

    public function getValue(Grammar $grammar): string
    {
        $companion = $grammar->wrap($this->companion);
        $alias = $grammar->wrap($this->alias);

        if ($this->kind === 'encrypted') {
            return "sum(case when {$companion} is null then 0 else 1 end) as {$alias}";
        }

        $times = $this->factor === null ? '' : ' * '.$grammar->wrap($this->factor);

        return "sum(case when {$companion} is null then ".$grammar->wrap($this->amount)."{$times} else 0 end) as {$alias}";
    }
}
