<?php

declare(strict_types=1);

use Odden\Core\Actions\EnrichCompanyAction;
use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\AuditSource;

it('tracks changes to standard properties in audit history', function (): void {
    $contact = Contact::factory()->create([
        'email' => 'original@example.com',
        'lifecycle_stage' => LifecycleStage::Lead,
    ]);

    $contact->update([
        'email' => 'updated@example.com',
        'lifecycle_stage' => LifecycleStage::Customer,
    ]);

    $history = $contact->propertyHistory;

    // The stage change also stamps became_customer_at.
    expect($history->pluck('property_name')->sort()->values()->all())->toBe(['became_customer_at', 'email', 'lifecycle_stage']);

    $emailAudit = $history->firstWhere('property_name', 'email');
    expect($emailAudit)->not->toBeNull()
        ->and($emailAudit->old_value)->toBe('original@example.com')
        ->and($emailAudit->new_value)->toBe('updated@example.com');

    $stageAudit = $history->firstWhere('property_name', 'lifecycle_stage');
    expect($stageAudit)->not->toBeNull()
        ->and($stageAudit->old_value)->toBe('lead')
        ->and($stageAudit->new_value)->toBe('customer');
});

it('tracks changes to dynamic custom properties in audit history', function (): void {
    $contact = Contact::factory()->create([
        'properties' => [
            'lead_score' => 10,
            'plan' => 'starter',
        ],
    ]);

    // Update custom property
    $contact->setProperty('lead_score', 85)->save();

    $history = $contact->propertyHistory;

    $scoreAudit = $history->firstWhere('property_name', 'lead_score');
    expect($scoreAudit)->not->toBeNull()
        ->and($scoreAudit->old_value)->toBe('10')
        ->and($scoreAudit->new_value)->toBe('85');

    // Untouched property 'plan' should not have an audit record
    expect($history->firstWhere('property_name', 'plan'))->toBeNull();
});

it('writes history when markContacted updates last_contacted_at', function (): void {
    $contact = Contact::factory()->create(['last_contacted_at' => null]);

    $contact->markContacted(now()->subDay());

    expect($contact->propertyHistory()->where('property_name', 'last_contacted_at')->count())->toBe(1);
});

it('writes history when a lifecycle stage transition changes the stage', function (): void {
    $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    app(TransitionLifecycleStageAction::class)->execute($contact, LifecycleStage::MarketingQualifiedLead);

    $stageAudit = $contact->propertyHistory()->where('property_name', 'lifecycle_stage')->first();

    expect($stageAudit)->not->toBeNull()
        ->and($stageAudit->old_value)->toBe('lead')
        ->and($stageAudit->new_value)->toBe('marketing_qualified_lead')
        ->and($contact->propertyHistory()->where('property_name', 'became_marketing_qualified_lead_at')->exists())->toBeTrue();
});

it('writes history when company enrichment fills industry and properties', function (): void {
    $company = Company::factory()->create(['domain' => 'deepmind.ai', 'industry' => null, 'properties' => []]);

    app(EnrichCompanyAction::class)->execute($company);

    $names = $company->propertyHistory()->pluck('property_name')->all();

    expect($names)->toContain('industry')
        ->and($names)->toContain('enriched_at');
});

it('skips system timestamps and stores dates the same way on both sides', function (): void {
    $company = Company::factory()->create(['last_health_calculated_at' => now()->subDay(), 'properties' => []]);
    $contact = Contact::factory()->create(['last_contacted_at' => '2026-01-01 09:00:00']);

    $company->update(['last_health_calculated_at' => now()]);
    $contact->update(['last_contacted_at' => '2026-02-01 09:00:00']);

    $dateAudit = $contact->propertyHistory()->where('property_name', 'last_contacted_at')->first();

    expect($company->propertyHistory()->count())->toBe(0)
        ->and($dateAudit->old_value)->toBe('2026-01-01 09:00:00')
        ->and($dateAudit->new_value)->toBe('2026-02-01 09:00:00');
});

it('records the source the server sets, not a request header', function (): void {
    request()->headers->set('X-Odden-Source', 'forged');
    $contact = Contact::factory()->create(['first_name' => 'Ann']);

    $contact->update(['first_name' => 'Anna']);
    AuditSource::using('import', fn () => $contact->update(['first_name' => 'Annie']));

    expect($contact->propertyHistory()->pluck('source')->sort()->values()->all())->toBe(['console', 'import']);
});

it('writes no history when the save fails', function (): void {
    $contact = Contact::factory()->create(['first_name' => 'Ann']);

    Contact::updating(fn (): bool => false);
    $contact->first_name = 'Anna';
    $contact->save();

    expect($contact->propertyHistory()->count())->toBe(0);
});
