<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Odden\Core\Encryption\Index\BlindIndexed;
use Odden\Core\Encryption\Index\SearchIndex;

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
     * The encrypted columns of a table: column => the field name its key context uses. For a host that reads rows without the models
     * (an export), to decrypt them with FieldEncrypter.
     *
     * @return array<string, string>
     */
    public function columnsOf(string $table): array
    {
        $found = [];

        foreach ($this->columns as $entry) {
            if (($entry['table'])() === $table) {
                $found[$entry['column']] = $entry['field'];
            }
        }

        return $found;
    }

    /**
     * The field name of every registered column ("contacts.email"), for the check that each belongs to a data area.
     *
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_values(array_unique(array_column($this->columns, 'field')));
    }

    /** @var list<class-string<Model&BlindIndexed>> */
    private array $indexed = [];

    /**
     * Register a model that keeps a search index (HasBlindIndexes), so the same command writes its index rows.
     *
     * @param  class-string<Model&BlindIndexed>  $model
     */
    public function addIndexed(string $model): void
    {
        $this->indexed[] = $model;
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

        // The index is written from the stored values, after they are all converted, and replaces what was there.
        foreach ($this->indexed as $model) {
            $done['index:'.(new $model)->getTable()] = app(SearchIndex::class)->rebuild($model, $scope);
        }

        $this->analyze();

        return $done;
    }

    /**
     * PostgreSQL plans from table statistics, which a bulk conversion has not updated yet (autovacuum comes a minute later): until then a
     * lookup through the index can pick a plan that reads the whole table. Update them once the rows are in.
     */
    private function analyze(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = [app(SearchIndex::class)->table(), ...array_map(fn (string $model): string => (new $model)->getTable(), $this->indexed)];

        foreach (array_unique($tables) as $table) {
            DB::statement('ANALYZE '.DB::getQueryGrammar()->wrapTable($table));
        }
    }
}
