<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\Casts\EncryptedAmount;

/**
 * Moves the stored money figures between their plain column and their encrypted companion, in either direction.
 *
 * It works on the rows as stored (no models), a chunk at a time and each chunk in one transaction with the rows locked, so a save of the
 * same record in between cannot be undone. Rows already in the wanted form are skipped, so it can be run again at any point and a run
 * that stopped half way is finished by the next. It uses the active tenant's key, and a host that keeps several tenants in one table
 * passes the tenant's rows in `$scope`.
 */
final class BusinessDataConverter
{
    private const int CHUNK = 500;

    public function __construct(private readonly FieldEncrypter $encrypter, private readonly BusinessDataColumns $columns) {}

    /**
     * Bring every registered figure into the wanted form.
     *
     * @param  bool  $encrypted  Whether the figures should be stored encrypted.
     * @param  (Closure(Builder, string): mixed)|null  $scope  Narrow the rows (given the query and the table name).
     * @return array<string, int> Figures converted, by field name.
     */
    public function convert(bool $encrypted, ?Closure $scope = null): array
    {
        $done = [];

        foreach ($this->columns->all() as $entry) {
            $done[$entry['field']] = $this->convertColumn($entry['table'], $entry['column'], $entry['field'], $encrypted, $scope);
        }

        return $done;
    }

    /**
     * How many stored figures are not in the wanted form.
     *
     * @return array<string, int> By field name.
     */
    public function pending(bool $encrypted, ?Closure $scope = null): array
    {
        $pending = [];

        foreach ($this->columns->all() as $entry) {
            if (! $this->exists($entry['table'], $entry['column'])) {
                $pending[$entry['field']] = 0;

                continue;
            }

            $query = DB::table($entry['table']);
            $scope === null || $scope($query, $entry['table']);

            $pending[$entry['field']] = $encrypted
                ? $query->whereNull(EncryptedAmount::companion($entry['column']))->count()
                : $query->whereNotNull(EncryptedAmount::companion($entry['column']))->count();
        }

        return $pending;
    }

    private function convertColumn(string $table, string $column, string $field, bool $encrypted, ?Closure $scope): int
    {
        if (! $this->exists($table, $column)) {
            return 0;
        }

        $companion = EncryptedAmount::companion($column);
        $converted = 0;

        $query = DB::table($table)->select(['id']);
        $scope === null || $scope($query, $table);
        $encrypted ? $query->whereNull($companion) : $query->whereNotNull($companion);

        $query->chunkById(self::CHUNK, function ($rows) use ($table, $column, $companion, $field, $encrypted, $scope, &$converted): void {
            DB::transaction(function () use ($rows, $table, $column, $companion, $field, $encrypted, $scope, &$converted): void {
                $current = DB::table($table)->whereIn('id', $rows->pluck('id')->all());
                $scope === null || $scope($current, $table);

                foreach ($current->lockForUpdate()->get(['id', $column, $companion]) as $row) {
                    if ($encrypted && $row->{$companion} === null) {
                        DB::table($table)->where('id', $row->id)->update([
                            $column => number_format(0, 2, '.', ''),
                            $companion => $this->encrypter->encrypt(number_format((float) $row->{$column}, 2, '.', ''), $field),
                        ]);
                        $converted++;
                    } elseif (! $encrypted && $row->{$companion} !== null) {
                        DB::table($table)->where('id', $row->id)->update([
                            $column => number_format((float) $this->encrypter->decrypt((string) $row->{$companion}, $field), 2, '.', ''),
                            $companion => null,
                        ]);
                        $converted++;
                    }
                }
            });
        }, 'id');

        return $converted;
    }

    private function exists(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column) && Schema::hasColumn($table, EncryptedAmount::companion($column));
    }
}
