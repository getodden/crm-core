<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Company;
use Odden\Core\Support\PropertyMerge;
use Odden\Core\Support\TenantGuard;

class UpdateCompanyAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Company $company, array $attributes): Company
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($company);

        $attributes = Arr::except($attributes, Company::SYSTEM_ATTRIBUTES);

        if (isset($attributes['domain']) && is_string($attributes['domain'])) {
            $attributes['domain'] = Company::normalizeDomain($attributes['domain']);
        }

        return DB::transaction(function () use ($company, $attributes): Company {
            $company->fill(PropertyMerge::onto($company, $attributes))->save();

            return $company;
        });
    }
}
