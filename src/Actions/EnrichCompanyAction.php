<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Odden\Core\Events\CompanyEnriched;
use Odden\Core\Models\Company;
use Odden\Core\Support\Enrichment\EnrichmentManager;

class EnrichCompanyAction
{
    public function __construct(
        protected EnrichmentManager $manager
    ) {}

    /**
     * Enrich a company profile with firmographic intelligence and tech stack data.
     */
    public function execute(Company $company, ?string $driverName = null): Company
    {
        if (empty($company->domain)) {
            return $company;
        }

        $driver = $this->manager->driver($driverName);
        $data = $driver->enrich($company->domain);

        if ($data === null) {
            return $company;
        }

        $enrichedFields = array_filter([
            'logo_url' => $data['logo_url'] ?? null,
            'tech_stack' => $data['tech_stack'] ?? null,
            'employee_count_range' => $data['employee_count_range'] ?? null,
            'description' => $data['description'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
        ], fn ($val) => $val !== null);

        // A driver that learned nothing about the company leaves it untouched.
        if ($enrichedFields === [] && empty($data['industry'])) {
            return $company;
        }

        // Fill industry if not already set
        if (empty($company->industry) && isset($data['industry'])) {
            $company->industry = $data['industry'];
        }

        // Merge properties
        $currentProperties = is_array($company->properties) ? $company->properties : [];

        $company->properties = array_merge($currentProperties, $enrichedFields, ['enriched_at' => now()->toIso8601String()]);
        $company->save();

        event(new CompanyEnriched($company, $data));

        return $company;
    }
}
