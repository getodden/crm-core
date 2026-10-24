<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * @return int How many values were encrypted.
     */
    public function encrypt(string $table, string $column, string $field, ?Closure $normalize = null): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $converted = 0;

        DB::table($table)->whereNotNull($column)->chunkById(500, function ($rows) use ($table, $column, $field, $normalize, &$converted): void {
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
