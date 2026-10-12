<?php

declare(strict_types=1);

namespace Odden\Core\Support\Enrichment;

use Odden\Core\Actions\ExtractCorporateDomainAction;
use Odden\Core\Contracts\EnrichmentDriver;

class HeuristicEnrichmentDriver implements EnrichmentDriver
{
    public function __construct(
        protected ExtractCorporateDomainAction $extractDomain
    ) {}

    /**
     * Enrich company metadata from what the domain itself tells us. Nothing is guessed: industry,
     * size and tech stack can't be known from a domain name, so this driver never returns them.
     * A logo URL is only returned when `odden-core.enrichment.logo_url` names a logo service,
     * because using one sends the company's domain to that service.
     *
     * @return array{
     *     name?: string,
     *     industry?: string,
     *     employee_count_range?: string,
     *     logo_url?: string,
     *     tech_stack?: list<string>,
     *     description?: string,
     *     city?: string,
     *     country?: string,
     *     linkedin_url?: string
     * }|null
     */
    public function enrich(string $domain): ?array
    {
        $clean = preg_replace('#^https?://#', '', strtolower(trim($domain))) ?? '';
        $clean = preg_replace('#^www\.#', '', $clean) ?? '';
        $clean = explode('/', $clean)[0];

        if (empty($clean) || ! str_contains($clean, '.')) {
            return null;
        }

        $data = ['name' => $this->extractDomain->deriveCompanyName($clean)];

        $logoUrl = config('odden-core.enrichment.logo_url');

        if (is_string($logoUrl) && $logoUrl !== '') {
            $data['logo_url'] = str_replace('{domain}', rawurlencode($clean), $logoUrl);
        }

        return $data;
    }
}
