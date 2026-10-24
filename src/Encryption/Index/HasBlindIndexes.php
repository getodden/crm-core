<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Index;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * For a model whose columns are encrypted but must still be found by value. Declare the indexed columns in blindIndexedColumns();
 * the trait keeps the search index in step when the model is saved or deleted for good, and adds the scopes that look records up
 * through it.
 *
 * @phpstan-require-extends Model
 */
trait HasBlindIndexes
{
    /**
     * The indexed columns and how each is indexed: `column => [kind => normalizer or true]`. Kinds are "exact" (the whole value,
     * normalized with IndexText::EMAIL, DIGITS or TEXT), "word" and "prefix" (true).
     *
     * @return array<string, array<string, string|true>>
     */
    abstract public static function blindIndexedColumns(): array;

    /**
     * The first part of the index field names ("contacts" gives "contacts.email").
     */
    abstract public static function blindIndexPrefix(): string;

    public static function bootHasBlindIndexes(): void
    {
        static::saved(function ($model): void {
            if ($model->wasRecentlyCreated || $model->wasChanged(array_keys(static::blindIndexedColumns()))) {
                $model->syncBlindIndexes();
            }
        });

        static::forceDeleted(function ($model): void {
            app(SearchIndex::class)->forget(array_map('strval', array_keys($model->blindIndexEntries())), [$model->getKey()]);
        });
    }

    /**
     * The name of an indexed column's field in the index.
     */
    public static function blindIndexField(string $column): string
    {
        return static::blindIndexPrefix().'.'.$column;
    }

    public function hasExactBlindIndex(string $column): bool
    {
        return is_string(static::blindIndexedColumns()[$column]['exact'] ?? null);
    }

    /**
     * What this record is indexed under: index field => kind => the plain tokens. Override to add fields built from several
     * columns, and call the parent.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function blindIndexEntries(): array
    {
        return $this->blindIndexEntriesFromColumns();
    }

    /**
     * The entries the declared columns give.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function blindIndexEntriesFromColumns(): array
    {
        $entries = [];

        foreach (static::blindIndexedColumns() as $column => $kinds) {
            $value = $this->getAttribute($column);
            $text = is_scalar($value) ? (string) $value : '';
            $field = static::blindIndexField($column);
            $entries[$field] = [];

            foreach ($kinds as $kind => $how) {
                $tokens = match ($kind) {
                    'word' => IndexText::words($text),
                    'prefix' => IndexText::prefixes($text),
                    default => [IndexText::normalize((string) $how, $text)],
                };

                $entries[$field][$kind] = array_values(array_filter($tokens, fn (string $token): bool => $token !== ''));
            }
        }

        return $entries;
    }

    /**
     * Write this record's index rows afresh.
     */
    public function syncBlindIndexes(): void
    {
        app(SearchIndex::class)->replace($this->getKey(), $this->blindIndexEntries());
    }

    /**
     * Records whose indexed column equals the value (compared the way the column is normalized: an email ignores case, a phone number
     * its punctuation).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereBlindExact(Builder $query, string $column, string $value): Builder
    {
        return $this->scopeWhereBlindExactIn($query, $column, [$value]);
    }

    /**
     * @param  Builder<$this>  $query
     * @param  list<string>  $values
     * @return Builder<$this>
     */
    public function scopeWhereBlindExactIn(Builder $query, string $column, array $values): Builder
    {
        $tokens = $this->exactTokens($column, $values);

        return $tokens === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($this->getQualifiedKeyName(), app(SearchIndex::class)->matching(static::blindIndexField($column), 'exact', $tokens));
    }

    /**
     * Records that have a value in the column and whose value is none of the given ones.
     *
     * @param  Builder<$this>  $query
     * @param  list<string>  $values
     * @return Builder<$this>
     */
    public function scopeWhereBlindExactNotIn(Builder $query, string $column, array $values): Builder
    {
        $tokens = $this->exactTokens($column, $values);

        $query->whereNotNull($this->qualifyColumn($column));

        return $tokens === []
            ? $query
            : $query->whereNotIn($this->getQualifiedKeyName(), app(SearchIndex::class)->matching(static::blindIndexField($column), 'exact', $tokens));
    }

    /**
     * Records where a word of one of the columns starts with each word of the text ("ann le" finds "Annie Lee"). Words of two to 24
     * letters match the start of a word; others match a whole word.
     *
     * @param  Builder<$this>  $query
     * @param  list<string>  $columns
     * @return Builder<$this>
     */
    public function scopeWhereBlindWords(Builder $query, array $columns, string $text): Builder
    {
        $words = IndexText::words($text);

        if ($words === []) {
            return $query->whereRaw('1 = 0');
        }

        $fields = array_map(static::blindIndexField(...), $columns);

        foreach ($words as $word) {
            [$kind, $value] = IndexText::searchTerm($word);
            $query->whereIn($this->getQualifiedKeyName(), app(SearchIndex::class)->matching($fields, $kind, [$value]));
        }

        return $query;
    }

    /**
     * Records where a word of the column is the given word (whole word, any case).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereBlindWord(Builder $query, string $column, string $word): Builder
    {
        $words = IndexText::words($word);

        return count($words) !== 1
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($this->getQualifiedKeyName(), app(SearchIndex::class)->matching(static::blindIndexField($column), 'word', $words));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function exactTokens(string $column, array $values): array
    {
        $how = static::blindIndexedColumns()[$column]['exact'] ?? null;

        if (! is_string($how)) {
            throw new \InvalidArgumentException(sprintf('%s [%s] has no exact index.', static::class, $column));
        }

        return array_values(array_unique(array_filter(
            array_map(fn (string $value): string => IndexText::normalize($how, $value), $values),
            fn (string $token): bool => $token !== '',
        )));
    }
}
