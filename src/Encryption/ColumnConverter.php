<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\Index\IndexText;

/**
 * Converts the stored values of a column between plain and encrypted, for a column that has just been given an encrypted cast.
 *
 * It works on the rows as stored (no models, no casts), skips values already in the encrypted format, and can be run again
 * safely. It uses the active tenant's key, so a multi-tenant host runs it once for each tenant, inside that tenant.
 */
final class ColumnConverter
{
    public function __construct(private readonly FieldEncrypter $encrypter) {}

    /**
     * Encrypt the plain values in a column.
     *
     * @param  string  $field  The name the column's cast uses, like "contacts.date_of_birth".
     * @param  (Closure(string): string)|null  $normalize  Put a plain value in the form the cast writes, before it is encrypted.
     * @param  (Closure(Builder, string): mixed)|null  $scope  Narrow the rows to convert (given the query and the table name). A host that keeps
     *                                                         several tenants in one table passes the tenant's rows only, because the active key is that tenant's.
     * @return int How many values were encrypted.
     */
    public function encrypt(string $table, string $column, string $field, ?Closure $normalize = null, ?Closure $scope = null): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $converted = 0;

        $query = DB::table($table)->whereNotNull($column);

        if ($scope !== null) {
            $scope($query, $table);
        }

        $query->chunkById(500, function ($rows) use ($table, $column, $field, $normalize, &$converted): void {
            foreach ($rows as $row) {
                $value = (string) $row->{$column};

                if ($this->encrypter->isEncrypted($value)) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([
                    $column => $this->encrypter->encrypt($normalize === null ? $value : $normalize($value), $field),
                ]);
                $converted++;
            }
        });

        return $converted;
    }

    /**
     * Fill a hash column (a keyed hash of the normalized value, see FieldEncrypter::blindIndex) for the rows that have none, from the
     * column as stored (plain or encrypted). A host looks a record up by this hash when the value itself is encrypted.
     *
     * @param  string  $field  The name the value's cast uses; it also keys the hash.
     * @param  string  $normalizer  An IndexText kind (EMAIL, DIGITS, TEXT).
     * @param  (Closure(Builder, string): mixed)|null  $scope  See encrypt().
     * @return int How many hashes were written.
     */
    public function hash(string $table, string $column, string $hashColumn, string $field, string $normalizer, ?Closure $scope = null): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, $hashColumn)) {
            return 0;
        }

        $done = 0;
        $query = DB::table($table)->whereNotNull($column)->whereNull($hashColumn);

        if ($scope !== null) {
            $scope($query, $table);
        }

        $query->chunkById(500, function ($rows) use ($table, $column, $hashColumn, $field, $normalizer, &$done): void {
            foreach ($rows as $row) {
                $stored = (string) $row->{$column};
                $plain = $this->encrypter->isEncrypted($stored) ? (string) $this->encrypter->decrypt($stored, $field) : $stored;

                DB::table($table)->where('id', $row->id)->update([
                    $hashColumn => $this->encrypter->blindIndex(IndexText::normalize($normalizer, $plain), $field),
                ]);
                $done++;
            }
        });

        return $done;
    }

    /**
     * The reverse of encrypt(), for rolling a migration back. Values that are not encrypted are left as they are.
     *
     * @return int How many values were decrypted.
     */
    public function decrypt(string $table, string $column, string $field): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $converted = 0;

        DB::table($table)->whereNotNull($column)->chunkById(500, function ($rows) use ($table, $column, $field, &$converted): void {
            foreach ($rows as $row) {
                $value = (string) $row->{$column};

                if (! $this->encrypter->isEncrypted($value)) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $this->encrypter->decrypt($value, $field)]);
                $converted++;
            }
        });

        return $converted;
    }
}
