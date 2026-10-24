<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Index;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\FieldEncrypter;

/**
 * The side table that lets encrypted columns be found again: one row per (field, keyed hash, record). The hashes are HMACs made
 * with a key derived from the tenant's data key, the index field and the kind of match ("exact", "word", "prefix"), so a copy of
 * the table shows only how many records share a value, and nothing can be compared across tenants.
 *
 * A model keeps its rows up to date through HasBlindIndexes; code that writes the columns without the model (a bulk UPDATE, a raw
 * insert) must call replace() itself or the record cannot be found by those values.
 */
class SearchIndex
{
    private const int INSERT_CHUNK = 500;

    public function __construct(private readonly FieldEncrypter $encrypter) {}

    public function table(): string
    {
        return (string) config('odden-core.tables.search_index', 'odden_search_index');
    }

    public function hash(string $field, string $kind, string $value): string
    {
        return $this->encrypter->blindIndex($value, $field, $kind);
    }

    /**
     * Replace everything indexed for one record in the given fields.
     *
     * @param  array<string, array<string, list<string>>>  $entries  Index field => kind => the plain tokens (already normalized).
     */
    public function replace(int|string $recordId, array $entries): void
    {
        $this->replaceMany([$recordId => $entries]);
    }

    /**
     * @param  array<int|string, array<string, array<string, list<string>>>>  $byRecord
     */
    public function replaceMany(array $byRecord): void
    {
        if ($byRecord === []) {
            return;
        }

        $rows = [];
        $fields = [];

        foreach ($byRecord as $recordId => $entries) {
            foreach ($entries as $field => $kinds) {
                $fields[$field] = true;

                foreach ($kinds as $kind => $tokens) {
                    foreach (array_unique($tokens) as $token) {
                        if ($token === '') {
                            continue;
                        }

                        $hash = $this->hash($field, (string) $kind, $token);
                        $rows[$field."\0".$hash."\0".$recordId] = [...$this->rowDefaults(), 'field' => $field, 'token' => $hash, 'record_id' => $recordId];
                    }
                }
            }
        }

        DB::transaction(function () use ($byRecord, $fields, $rows): void {
            foreach (array_chunk(array_keys($byRecord), self::INSERT_CHUNK) as $ids) {
                DB::table($this->table())->whereIn('field', array_keys($fields))->whereIn('record_id', $ids)->delete();
            }

            foreach (array_chunk(array_values($rows), self::INSERT_CHUNK) as $chunk) {
                DB::table($this->table())->insertOrIgnore($chunk);
            }
        });
    }

    /**
     * Columns a host has added to the index table, and the value each new row gets (a multi-tenant host puts the workspace here, so its
     * rows can be found and removed with the workspace's). None by default.
     *
     * @return array<string, mixed>
     */
    protected function rowDefaults(): array
    {
        return [];
    }

    /**
     * Write the index rows of every stored record of a model, from the stored values (plain or encrypted). Safe to run again: each
     * record's rows are replaced. Uses the active tenant's key, so a multi-tenant host passes the tenant's rows only.
     *
     * @param  class-string<Model&BlindIndexed>  $modelClass
     * @param  (Closure(Builder, string): mixed)|null  $scope  Narrows the rows (given the query and the table name).
     * @return int How many records were indexed.
     */
    public function rebuild(string $modelClass, ?Closure $scope = null): int
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $done = 0;

        if (! Schema::hasTable($table) || ! Schema::hasTable($this->table())) {
            return 0;
        }

        $query = DB::table($table);

        if ($scope !== null) {
            $scope($query, $table);
        }

        $query->chunkById(self::INSERT_CHUNK, function ($rows) use ($model, &$done): void {
            $byRecord = [];

            foreach ($rows as $row) {
                $byRecord[$row->{$model->getKeyName()}] = $model->newFromBuilder((array) $row)->blindIndexEntries();
            }

            $this->replaceMany($byRecord);
            $done += count($byRecord);
        }, $model->getKeyName());

        return $done;
    }

    /**
     * Remove everything indexed for records (when they are deleted for good).
     *
     * @param  list<string>  $fields
     * @param  list<int|string>  $recordIds
     */
    public function forget(array $fields, array $recordIds): void
    {
        foreach (array_chunk($recordIds, self::INSERT_CHUNK) as $ids) {
            DB::table($this->table())->whereIn('field', $fields)->whereIn('record_id', $ids)->delete();
        }
    }

    /**
     * A sub-query of the ids of records indexed under any of the values, for `whereIn('id', $index->matching(...))` (or whereNotIn).
     *
     * @param  list<string>  $values  Normalized plain tokens.
     * @param  list<string>|string  $fields  One index field, or several (a match in any of them counts).
     * @return Closure(Builder): void
     */
    public function matching(array|string $fields, string $kind, array $values): Closure
    {
        $fields = (array) $fields;

        return function (Builder $sub) use ($fields, $kind, $values): void {
            $hashes = [];

            foreach ($fields as $field) {
                foreach ($values as $value) {
                    $hashes[] = $this->hash($field, $kind, $value);
                }
            }

            $sub->select('record_id')->from($this->table())->whereIn('field', $fields)->whereIn('token', $hashes ?: ['']);
        };
    }

    /**
     * Groups of records that share an exact value, for finding duplicates without decrypting anything.
     *
     * @param  Closure(Builder): void|Builder  $among  A sub-query selecting the ids of the records considered.
     * @return array<string, list<int>> Record ids by the shared hash.
     */
    public function sharedTokens(string $field, Closure|Builder $among): array
    {
        $in = fn (Builder $q) => $q->where('field', $field)->whereIn('record_id', $among);

        $tokens = DB::table($this->table())->tap($in)->select('token')->groupBy('token')->havingRaw('count(*) > 1')->pluck('token');

        $groups = [];

        foreach ($tokens->chunk(self::INSERT_CHUNK) as $chunk) {
            foreach (DB::table($this->table())->tap($in)->whereIn('token', $chunk->all())->orderBy('record_id')->get(['token', 'record_id']) as $row) {
                $groups[$row->token][] = (int) $row->record_id;
            }
        }

        return $groups;
    }
}
