<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Closure;
use Illuminate\Database\Query\Builder;

/**
 * Every column that has an encrypted cast, so one command can convert the stored rows of all of them (`odden:encrypt-existing`).
 * Each package registers its own columns when it boots.
 */
final class EncryptedColumns
{
    /** @var list<array{table: Closure(): string, column: string, field: string, normalize: (Closure(string): string)|null}> */
    private array $columns = [];

    /**
     * @param  Closure(): string  $table  The table name, asked late because it can be changed in the configuration.
     * @param  (Closure(string): string)|null  $normalize  See ColumnConverter::encrypt().
     */
    public function add(Closure $table, string $column, string $field, ?Closure $normalize = null): void
    {
        $this->columns[] = compact('table', 'column', 'field', 'normalize');
    }

    /**
     * Encrypt the plain values in every registered column, with the active tenant's key.
     *
     * @param  (Closure(Builder, string): mixed)|null  $scope  See ColumnConverter::encrypt().
     * @return array<string, int> Values encrypted, by field name.
     */
    public function convert(ColumnConverter $converter, ?Closure $scope = null): array
    {
        $done = [];

        foreach ($this->columns as $entry) {
            $done[$entry['field']] = $converter->encrypt(($entry['table'])(), $entry['column'], $entry['field'], $entry['normalize'], $scope);
        }

        return $done;
    }
}
