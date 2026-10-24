<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Closure;

/**
 * Every money column that can be kept encrypted (it has an EncryptedAmount cast and a companion `{column}_encrypted` text column).
 * Each package registers its own when it boots; `odden:convert-business-data` converts them all.
 */
final class BusinessDataColumns
{
    /** @var list<array{table: Closure(): string, column: string, field: string}> */
    private array $columns = [];

    /**
     * @param  Closure(): string  $table  Asked late, because the table name can be changed in the configuration.
     * @param  string  $field  The name the cast uses, such as "deals.amount".
     */
    public function add(Closure $table, string $column, string $field): void
    {
        $this->columns[] = compact('table', 'column', 'field');
    }

    /**
     * @return list<array{table: string, column: string, field: string}>
     */
    public function all(): array
    {
        return array_map(fn (array $entry): array => ['table' => ($entry['table'])(), 'column' => $entry['column'], 'field' => $entry['field']], $this->columns);
    }
}
