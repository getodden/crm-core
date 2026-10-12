<?php

declare(strict_types=1);

namespace Odden\Core\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Odden\Core\Actions\CreateCompanyAction;
use Odden\Core\Actions\EnrichCompanyAction;
use Odden\Core\Contracts\EnrichmentDriver;
use Odden\Core\Events\CompanyEnriched;
use Odden\Core\Models\Company;
use Odden\Core\Support\Enrichment\EnrichmentManager;
use Odden\Core\Support\Enrichment\HeuristicEnrichmentDriver;

class CompanyEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_heuristic_driver_returns_only_what_the_domain_tells_it(): void
    {
        /** @var HeuristicEnrichmentDriver $driver */
        $driver = app(HeuristicEnrichmentDriver::class);

        $aiData = $driver->enrich('https://www.anthropic.ai/');
        $this->assertSame(['name'], array_keys($aiData ?? []));

        // "media" is not healthcare, and nothing here knows a company's size or tech stack.
        $this->assertArrayNotHasKey('industry', $driver->enrich('socialmedia.com') ?? []);

        $this->assertNull($driver->enrich('invalid-no-tld'));
    }

    public function test_heuristic_driver_only_builds_a_logo_url_when_a_logo_service_is_configured(): void
    {
        config(['odden-core.enrichment.logo_url' => 'https://logos.example.test/{domain}.png']);

        $data = app(HeuristicEnrichmentDriver::class)->enrich('anthropic.ai');

        $this->assertSame('https://logos.example.test/anthropic.ai.png', $data['logo_url'] ?? null);
    }

    public function test_heuristic_enrichment_writes_no_guesses_into_the_company(): void
    {
        Event::fake([CompanyEnriched::class]);

        $company = Company::factory()->create(['domain' => 'deepmind.ai', 'industry' => null, 'properties' => []]);

        app(EnrichCompanyAction::class)->execute($company);

        $fresh = $company->fresh();
        $this->assertNull($fresh->industry);
        $this->assertNull($fresh->getProperty('employee_count_range'));
        $this->assertNull($fresh->getProperty('tech_stack'));
        $this->assertNull($fresh->getProperty('logo_url'));
        Event::assertNotDispatched(CompanyEnriched::class);
    }

    public function test_enrich_company_action_enriches_company_and_dispatches_event(): void
    {
        Event::fake([CompanyEnriched::class]);
        $this->fakeDriver();

        $company = Company::factory()->create([
            'domain' => 'deepmind.ai',
            'industry' => null,
            'properties' => ['custom_notes' => 'Existing note'],
        ]);

        /** @var EnrichCompanyAction $action */
        $action = app(EnrichCompanyAction::class);
        $enriched = $action->execute($company);

        $this->assertSame('Research', $enriched->industry);
        $this->assertSame('Existing note', $enriched->getProperty('custom_notes'));
        $this->assertNotNull($enriched->getProperty('logo_url'));
        $this->assertNotNull($enriched->getProperty('tech_stack'));
        $this->assertNotNull($enriched->getProperty('enriched_at'));

        Event::assertDispatched(CompanyEnriched::class, function (CompanyEnriched $event) use ($company): bool {
            return $event->company->id === $company->id;
        });
    }

    public function test_can_register_and_use_custom_enrichment_driver(): void
    {
        /** @var EnrichmentManager $manager */
        $manager = app(EnrichmentManager::class);

        $customDriver = new class implements EnrichmentDriver
        {
            public function enrich(string $domain): ?array
            {
                return [
                    'industry' => 'Custom Aerospace',
                    'tech_stack' => ['Rust', 'WebAssembly'],
                    'logo_url' => 'https://example.com/custom-logo.png',
                ];
            }
        };

        $manager->extend('custom_driver', $customDriver);

        $company = Company::factory()->create([
            'domain' => 'spacex.com',
            'industry' => null,
        ]);

        /** @var EnrichCompanyAction $action */
        $action = app(EnrichCompanyAction::class);
        $action->execute($company, 'custom_driver');

        $this->assertSame('Custom Aerospace', $company->fresh()->industry);
        $this->assertSame(['Rust', 'WebAssembly'], $company->fresh()->getProperty('tech_stack'));
        $this->assertSame('https://example.com/custom-logo.png', $company->fresh()->getProperty('logo_url'));
    }

    public function test_create_company_action_with_enrich_flag_auto_enriches(): void
    {
        Event::fake([CompanyEnriched::class]);
        $this->fakeDriver();

        /** @var CreateCompanyAction $action */
        $action = app(CreateCompanyAction::class);

        $company = $action->execute([
            'name' => 'Scale AI',
            'domain' => 'scale.ai',
        ], enrich: true);

        $this->assertSame('scale.ai', $company->domain);
        $this->assertSame('Research', $company->industry);
        $this->assertNotNull($company->getProperty('logo_url'));

        Event::assertDispatched(CompanyEnriched::class);
    }

    /**
     * Make the default driver one that returns fixed data.
     */
    private function fakeDriver(): void
    {
        app(EnrichmentManager::class)->extend('fake', new class implements EnrichmentDriver
        {
            public function enrich(string $domain): ?array
            {
                return [
                    'industry' => 'Research',
                    'tech_stack' => ['Python'],
                    'logo_url' => "https://logos.example.test/{$domain}.png",
                ];
            }
        });

        config(['odden-core.enrichment.driver' => 'fake']);
    }
}
