<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\Index\SearchIndex;
use Odden\Core\Models\Company;

class FindDuplicateCompaniesAction
{
    /**
     * Find groups of duplicate companies based on domain or name (compared without regard to case or surrounding spaces).
     *
     * The values are encrypted, so the groups come from the search index (records sharing a keyed hash); nothing is decrypted
     * to find them.
     *
     * `$visible` limits the search to companies in the given query (for example the companies a panel
     * user may see), so duplicates are never reported across a scope boundary.
     *
     * @param  Builder<covariant Model>|null  $visible
     * @return array<int, array{
     *     match_field: string,
     *     match_value: string,
     *     companies: Collection<int, Company>
     * }>
     */
    public function execute(?Builder $visible = null): array
    {
        $key = (new Company)->getQualifiedKeyName();
        $scope = static fn (): Builder => $visible === null
            ? Company::query()
            : Company::query()->whereIn($key, (clone $visible)->select($key));

        $index = app(SearchIndex::class);
        $among = $scope()->select($key)->toBase();

        $duplicates = [];
        $reported = [];

        foreach (['domain', 'name'] as $field) {
            foreach ($index->sharedTokens(Company::blindIndexField($field), $among) as $ids) {
                sort($ids);
                $signature = implode(',', $ids);

                if (isset($reported[$signature])) {
                    continue;
                }

                $reported[$signature] = true;
                $companies = $scope()->whereIn($key, $ids)->orderBy('id')->get();

                if ($companies->count() < 2) {
                    continue;
                }

                $duplicates[] = [
                    'match_field' => $field,
                    'match_value' => (string) $companies->first()?->getAttribute($field),
                    'companies' => $companies,
                ];
            }
        }

        return $duplicates;
    }
}
