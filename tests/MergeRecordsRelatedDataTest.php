<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Odden\Core\Actions\MergeCompaniesAction;
use Odden\Core\Actions\MergeContactsAction;
use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Events\CompaniesMerged;
use Odden\Core\Events\ContactsMerged;
use Odden\Core\Models\Association;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\ListMembership;
use Odden\Core\Models\PropertyHistory;
use Odden\Core\Tests\Fixtures\CrossHubRelations;
use Odden\Core\Tests\Fixtures\Deal;
use Odden\Core\Tests\Fixtures\Ticket;

function mergeContacts(Contact $primary, Contact $secondary): Contact
{
    return app(MergeContactsAction::class)->execute($primary, $secondary);
}

function mergeCompanies(Company $primary, Company $secondary): Company
{
    return app(MergeCompaniesAction::class)->execute($primary, $secondary);
}

function listMembers(CrmList $list): array
{
    return ListMembership::query()->where('list_id', $list->id)->orderBy('member_id')->pluck('member_id')->all();
}

// Contacts

test('merging contacts moves activities to the primary', function () {
    [$primary, $secondary] = Contact::factory()->count(2)->create();
    $secondary->logActivity(type: ActivityType::Call, title: 'Discovery call');

    mergeContacts($primary, $secondary);

    expect($primary->activities()->where('title', 'Discovery call')->exists())->toBeTrue()
        ->and($secondary->activities()->count())->toBe(0);
});

test('merging contacts moves list memberships and skips lists the primary is already on', function () {
    [$primary, $secondary] = Contact::factory()->count(2)->create();
    $onlySecondary = CrmList::create(['name' => 'Webinar attendees', 'entity_type' => 'contact']);
    $both = CrmList::create(['name' => 'Newsletter', 'entity_type' => 'contact']);
    $onlySecondary->addMember($secondary);
    $both->addMember($secondary);
    $both->addMember($primary);

    mergeContacts($primary, $secondary);

    expect(listMembers($onlySecondary))->toBe([$primary->id])
        ->and(listMembers($both))->toBe([$primary->id]);
});

test('merging contacts moves property history to the primary', function () {
    [$primary, $secondary] = Contact::factory()->count(2)->create(['job_title' => 'Engineer']);
    $secondary->update(['job_title' => 'Director']);

    mergeContacts($primary, $secondary);

    $history = PropertyHistory::query()->where('property_name', 'job_title')->where('new_value', 'Director')->sole();
    expect($history->auditable_id)->toBe($primary->id)
        ->and($history->auditable_type)->toBe($primary->getMorphClass());
});

test('merging contacts moves lifecycle stage transitions and keeps the earliest stage dates', function () {
    $primary = Contact::factory()->create(['became_lead_at' => now()->subDays(5), 'became_customer_at' => null]);
    $secondary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead, 'became_lead_at' => now()->subDays(40), 'became_customer_at' => now()->subDay()]);
    app(TransitionLifecycleStageAction::class)->execute($secondary, LifecycleStage::Opportunity, force: true);

    $merged = mergeContacts($primary, $secondary);

    expect($merged->lifecycleTransitions()->where('to_stage', LifecycleStage::Opportunity->value)->exists())->toBeTrue()
        ->and($secondary->lifecycleTransitions()->count())->toBe(0)
        ->and($merged->became_lead_at->toDateString())->toBe(now()->subDays(40)->toDateString())
        ->and($merged->became_customer_at?->toDateString())->toBe(now()->subDay()->toDateString());
});

test('merging contacts moves associations in both directions and drops duplicates and self links', function () {
    [$primary, $secondary] = Contact::factory()->count(2)->create();
    [$sharedCompany, $otherCompany] = Company::factory()->count(2)->create();
    $parentContact = Contact::factory()->create();

    $primary->associateWith($sharedCompany);
    $secondary->associateWith($sharedCompany);
    $secondary->associateWith($otherCompany);
    $parentContact->associateWith($secondary, 'referral');
    $secondary->associateWith($primary, 'referral');

    mergeContacts($primary, $secondary);

    expect($primary->companies()->get()->modelKeys())->toEqualCanonicalizing([$sharedCompany->id, $otherCompany->id])
        ->and($parentContact->isAssociatedWith($primary, 'referral'))->toBeTrue()
        ->and(Association::query()->where('parent_id', $primary->id)->where('child_id', $primary->id)->where('child_type', $primary->getMorphClass())->exists())->toBeFalse()
        ->and($secondary->associationsAsParent()->count() + $secondary->associationsAsChild()->count())->toBe(0);
});

test('merging contacts reparents deals through associations', function () {
    CrossHubRelations::install();

    try {
        [$primary, $secondary] = Contact::factory()->count(2)->create();
        $deal = Deal::create(['name' => 'Annual plan']);
        CrossHubRelations::attachDeal($secondary, $deal);

        mergeContacts($primary, $secondary);

        expect($primary->deals()->get()->modelKeys())->toBe([$deal->id])
            ->and($secondary->deals()->count())->toBe(0);
    } finally {
        CrossHubRelations::uninstall();
    }
});

test('merging contacts dispatches ContactsMerged with both records before the secondary is deleted', function () {
    [$primary, $secondary] = Contact::factory()->count(2)->create();
    $seen = null;

    Event::listen(function (ContactsMerged $event) use (&$seen): void {
        $seen = [$event->primary->id, $event->secondary->id, $event->secondary->trashed()];
    });

    mergeContacts($primary, $secondary);

    expect($seen)->toBe([$primary->id, $secondary->id, false]);
});

test('ContactsMerged listeners can move package data, and a failing listener rolls the merge back', function () {
    CrossHubRelations::install();

    try {
        [$primary, $secondary] = Contact::factory()->count(2)->create();
        $ticket = Ticket::create(['subject' => 'Help', 'contact_id' => $secondary->id]);
        $secondary->logActivity(type: ActivityType::Note, title: 'Before merge');

        Event::listen(function (ContactsMerged $event): void {
            Ticket::query()->where('contact_id', $event->secondary->id)->update(['contact_id' => $event->primary->id]);

            throw new RuntimeException('Listener failed');
        });

        expect(fn () => mergeContacts($primary, $secondary))->toThrow(RuntimeException::class, 'Listener failed');

        expect($ticket->fresh()->contact_id)->toBe($secondary->id)
            ->and($secondary->fresh()->trashed())->toBeFalse()
            ->and($secondary->activities()->where('title', 'Before merge')->exists())->toBeTrue();
    } finally {
        CrossHubRelations::uninstall();
    }
});

// Companies

test('merging companies moves list memberships and skips lists the primary is already on', function () {
    [$primary, $secondary] = Company::factory()->count(2)->create();
    $onlySecondary = CrmList::create(['name' => 'Target accounts', 'entity_type' => 'company']);
    $both = CrmList::create(['name' => 'Customers', 'entity_type' => 'company']);
    $onlySecondary->addMember($secondary);
    $both->addMember($secondary);
    $both->addMember($primary);

    mergeCompanies($primary, $secondary);

    expect(listMembers($onlySecondary))->toBe([$primary->id])
        ->and(listMembers($both))->toBe([$primary->id]);
});

test('merging companies moves property history to the primary', function () {
    [$primary, $secondary] = Company::factory()->count(2)->create(['industry' => 'Retail']);
    $secondary->update(['industry' => 'Logistics']);

    mergeCompanies($primary, $secondary);

    expect(PropertyHistory::query()->where('property_name', 'industry')->where('new_value', 'Logistics')->sole()->auditable_id)
        ->toBe($primary->id);
});

test('merging companies moves lifecycle stage transitions to the primary', function () {
    [$primary, $secondary] = Company::factory()->count(2)->create();
    app(TransitionLifecycleStageAction::class)->execute($secondary, LifecycleStage::Customer, force: true);

    mergeCompanies($primary, $secondary);

    expect($primary->lifecycleTransitions()->where('to_stage', LifecycleStage::Customer->value)->exists())->toBeTrue()
        ->and($secondary->lifecycleTransitions()->count())->toBe(0);
});

test('merging companies drops an association between the two records', function () {
    [$primary, $secondary] = Company::factory()->count(2)->create();
    $primary->associateWith($secondary, 'subsidiary');

    mergeCompanies($primary, $secondary);

    expect(Association::query()->count())->toBe(0);
});

test('merging companies dispatches CompaniesMerged before the health score is recalculated', function () {
    CrossHubRelations::install();

    try {
        [$primary, $secondary] = Company::factory()->count(2)->create();
        Ticket::create(['subject' => 'Outage', 'company_id' => $secondary->id, 'priority' => 'urgent', 'is_sla_response_breached' => true]);
        $seen = null;

        // Stands in for a package listener that moves its own records.
        Event::listen(function (CompaniesMerged $event) use (&$seen): void {
            $seen = [$event->primary->id, $event->secondary->id];
            Ticket::query()->where('company_id', $event->secondary->id)->update(['company_id' => $event->primary->id]);
        });

        $merged = mergeCompanies($primary, $secondary);

        // 70 + 15 (merge note) - 10 (no contacts) - 15 (urgent ticket) - 20 (SLA breach)
        expect($seen)->toBe([$primary->id, $secondary->id])
            ->and($merged->health_score)->toBe(40);
    } finally {
        CrossHubRelations::uninstall();
    }
});
