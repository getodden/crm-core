<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Company;

class FindDuplicateCompaniesAction
{
    /**
     * Find groups of duplicate companies based on domain or name.
     *
     * `$visible` limits the search to companies in the given query (for example the companies a panel
     * user may see), so duplicates are never reported across a scope boundary.
     *
     * @param  Builder<Company>|null  $visible
     * @return array<int, array{
     *     match_field: string,
     *     match_value: string,
     *     companies: Collection<int, Company>
     * }>
     */
    public function execute(?Builder $visible = null): array
    {
        $scope = static fn (): Builder => $visible === null
            ? Company::query()
            : Company::query()->whereIn((new Company)->getQualifiedKeyName(), (clone $visible)->select((new Company)->getQualifiedKeyName()));

        $duplicates = [];

        // 1. Duplicate Domains
        $duplicateDomains = $scope()
            ->select('domain', DB::raw('count(*) as count'))
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->groupBy('domain')
            ->havingRaw('count(*) > ?', [1])
            ->pluck('domain');

        foreach ($duplicateDomains as $domain) {
            $companies = $scope()->where('domain', $domain)->orderBy('id')->get();
            if ($companies->count() > 1) {
                $duplicates[] = [
                    'match_field' => 'domain',
                    'match_value' => (string) $domain,
                    'companies' => $companies,
                ];
            }
        }

        // 2. Duplicate Names
        $duplicateNames = $scope()
            ->select('name', DB::raw('count(*) as count'))
            ->groupBy('name')
            ->havingRaw('count(*) > ?', [1])
            ->pluck('name');

        foreach ($duplicateNames as $name) {
            $companies = $scope()->where('name', $name)->orderBy('id')->get();

            $ids = $companies->pluck('id')->sort()->values()->all();
            $alreadyMatched = false;
            foreach ($duplicates as $existing) {
                $existingIds = $existing['companies']->pluck('id')->sort()->values()->all();
                if ($ids === $existingIds) {
                    $alreadyMatched = true;
                    break;
                }
            }

            if (! $alreadyMatched && $companies->count() > 1) {
                $duplicates[] = [
                    'match_field' => 'name',
                    'match_value' => (string) $name,
                    'companies' => $companies,
                ];
            }
        }

        return $duplicates;
    }
}
