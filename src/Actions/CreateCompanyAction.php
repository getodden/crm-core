<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Odden\Core\Events\CompanyCreated;
use Odden\Core\Models\Company;

class CreateCompanyAction
{
    /**
     * Execute the action to create a company.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, bool $enrich = false): Company
    {
        if (isset($attributes['domain']) && is_string($attributes['domain'])) {
            $attributes['domain'] = Company::normalizeDomain($attributes['domain']);
        }

        $company = Company::create($attributes);

        event(new CompanyCreated($company));

        if ($enrich || (bool) config('odden-core.enrichment.auto_enrich', false)) {
            app(EnrichCompanyAction::class)->execute($company);
        }

        return $company;
    }
}
