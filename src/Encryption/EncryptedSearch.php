<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\TooManyToFilter;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

final class EncryptedSearch
{
    /**
     * @return Closure(Builder<Contact>, string): Builder<Contact>
     */
    public static function contacts(): Closure
    {
        return static fn (Builder $query, string $search): Builder => self::lacks(DataArea::Identity)
            ? $query->whereRaw('1 = 0')
            : (new Contact)->scopeSearchIdentity($query, $search);
    }

    /**
     * @return Closure(Builder<Company>, string): Builder<Company>
     */
    public static function companies(): Closure
    {
        return static fn (Builder $query, string $search): Builder => self::lacks(DataArea::Identity)
            ? $query->whereRaw('1 = 0')
            : self::companyMatches($query, $search);
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public static function companyMatches(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereNameStartsWith($search)
            ->orWhere(fn (Builder $domain): Builder => $domain->whereDomain($search)));
    }

    /**
     * @param  list<string>  $columns
     * @param  (Closure(TooManyToFilter): void)|null  $whenTooMany
     * @return Closure(Builder<covariant Model>, string): Builder<covariant Model>
     */
    public static function sortBy(array $columns, ?Closure $whenTooMany = null): Closure
    {
        return static function (Builder $query, string $direction) use ($columns, $whenTooMany): Builder {
            try {
                $ids = app(DecryptedFilter::class)->sortedIds($query, $columns, $direction === 'desc');
            } catch (TooManyToFilter $e) {
                if ($whenTooMany !== null) {
                    $whenTooMany($e);
                }

                return $query;
            }

            return DecryptedFilter::orderByIds($query, $ids);
        };
    }

    /**
     * @return Closure(Builder<covariant Model>, string): Builder<covariant Model>
     */
    public static function keepsOrder(): Closure
    {
        return static fn (Builder $query): Builder => $query;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Collection<int, TModel>
     */
    public static function limitedAndSorted(Builder $query, array $columns, int $limit): Collection
    {
        $records = $query->orderBy($query->getModel()->getQualifiedKeyName())->limit($limit)->get();

        return $records->sortBy(
            fn (Model $record): string => implode("\0", array_map(fn (string $column): string => mb_strtolower((string) $record->getAttribute($column)), $columns)),
        )->values();
    }

    public static function lacks(DataArea $area, int|string|null $userId = null): bool
    {
        return ! app(Areas::class)->holds($area, $userId);
    }
}
