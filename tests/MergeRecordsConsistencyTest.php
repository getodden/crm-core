<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\Core\Actions\MergeCompaniesAction;
use Odden\Core\Actions\MergeContactsAction;
use Odden\Core\Enums\AssociationCardinality;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;
use Odden\Core\Models\Association;
use Odden\Core\Models\AssociationType;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\LifecycleStageTransition;
use Odden\Core\Support\LifecycleStateMachine;

function linksBetween(Model $a, Model $b): int
{
    return Association::query()
        ->where(fn ($q) => $q
            ->where(fn ($l) => $l->where('parent_type', $a->getMorphClass())->where('parent_id', $a->getKey())
                ->where('child_type', $b->getMorphClass())->where('child_id', $b->getKey()))
            ->orWhere(fn ($l) => $l->where('parent_type', $b->getMorphClass())->where('parent_id', $b->getKey())
                ->where('child_type', $a->getMorphClass())->where('child_id', $a->getKey())))
        ->count();
}

it('moves the merged contact to the further-along lifecycle stage', function (LifecycleStage $primaryStage, LifecycleStage $secondaryStage, LifecycleStage $expected): void {
    $primary = Contact::factory()->create(['lifecycle_stage' => $primaryStage]);
    $secondary = Contact::factory()->create(['lifecycle_stage' => $secondaryStage]);

    $merged = app(MergeContactsAction::class)->execute($primary, $secondary);

    expect($merged->lifecycle_stage)->toBe($expected)
        ->and($merged->getAttribute("became_{$expected->value}_at"))->not->toBeNull();
})->with([
    'lead into customer' => [LifecycleStage::Lead, LifecycleStage::Customer, LifecycleStage::Customer],
    'customer keeps its stage over a lead' => [LifecycleStage::Customer, LifecycleStage::Lead, LifecycleStage::Customer],
    'customer keeps its stage over other' => [LifecycleStage::Customer, LifecycleStage::Other, LifecycleStage::Customer],
    'customer to evangelist' => [LifecycleStage::Customer, LifecycleStage::Evangelist, LifecycleStage::Evangelist],
    'evangelist keeps its stage over a customer' => [LifecycleStage::Evangelist, LifecycleStage::Customer, LifecycleStage::Evangelist],
    'other to a funnel stage' => [LifecycleStage::Other, LifecycleStage::Lead, LifecycleStage::Lead],
    'subscriber to opportunity' => [LifecycleStage::Subscriber, LifecycleStage::Opportunity, LifecycleStage::Opportunity],
]);

it('keeps became_customer_at consistent when a lead is merged with a customer', function (): void {
    $this->travelTo(now()->subDays(30));
    $secondary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer]);
    $customerSince = $secondary->became_customer_at;
    $this->travelBack();

    $primary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    $merged = app(MergeContactsAction::class)->execute($primary, $secondary);

    expect($merged->lifecycle_stage)->toBe(LifecycleStage::Customer)
        ->and($merged->became_customer_at->toDateTimeString())->toBe($customerSince->toDateTimeString())
        ->and(LifecycleStageTransition::query()
            ->where('record_type', $primary->getMorphClass())
            ->where('record_id', $primary->id)
            ->where('source', 'merge')
            ->where('to_stage', LifecycleStage::Customer->value)
            ->exists())->toBeTrue();
});

it('takes the further-along stage in strict mode too', function (): void {
    app(LifecycleStateMachine::class)->setStrict(true);

    $primary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    $secondary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer]);

    expect(app(MergeContactsAction::class)->execute($primary, $secondary)->lifecycle_stage)->toBe(LifecycleStage::Customer);
});

it('validates a chosen lifecycle stage, so a customer is not moved backwards by a merge', function (): void {
    $primary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer]);
    $secondary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    expect(fn () => app(MergeContactsAction::class)->execute($primary, $secondary, ['lifecycle_stage' => 'lead']))
        ->toThrow(InvalidLifecycleStageTransitionException::class);

    expect($primary->fresh()->lifecycle_stage)->toBe(LifecycleStage::Customer)
        ->and($secondary->fresh()->trashed())->toBeFalse();
});

it('applies a chosen lifecycle stage the state machine allows', function (): void {
    $primary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    $secondary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Opportunity]);

    $merged = app(MergeContactsAction::class)->execute($primary, $secondary, ['lifecycle_stage' => LifecycleStage::SalesQualifiedLead]);

    expect($merged->lifecycle_stage)->toBe(LifecycleStage::SalesQualifiedLead);
});

it('moves a merged company to the further-along lifecycle stage', function (): void {
    $primary = Company::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    $secondary = Company::factory()->create(['lifecycle_stage' => LifecycleStage::Customer]);

    $merged = app(MergeCompaniesAction::class)->execute($primary, $secondary);

    expect($merged->lifecycle_stage)->toBe(LifecycleStage::Customer)
        ->and($merged->became_customer_at)->not->toBeNull();
});

it('does not leave a link and its reverse after a merge', function (): void {
    $company = Company::factory()->create();
    $primary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    $secondary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    $primary->associateWith($company);
    $company->associateWith($secondary);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect(linksBetween($primary, $company))->toBe(1);
});

it('does not move both of the secondary\'s links to the same record twice', function (): void {
    $company = Company::factory()->create();
    $primary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    $secondary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    $secondary->associateWith($company);
    $company->associateWith($secondary);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect(linksBetween($primary, $company))->toBe(1)
        ->and(linksBetween($secondary, $company))->toBe(0);
});

it('keeps the primary\'s link and drops a moved one that would break One-to-Many cardinality', function (): void {
    $type = AssociationType::create([
        'name' => 'employer',
        'label' => 'Employer',
        'cardinality' => AssociationCardinality::OneToMany,
        'from_record_type' => (new Company)->getMorphClass(),
        'to_record_type' => (new Contact)->getMorphClass(),
    ]);

    $primaryEmployer = Company::factory()->create();
    $secondaryEmployer = Company::factory()->create();
    $primary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    $secondary = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    $primaryEmployer->associateWith($primary, $type);
    // Linked from the contact's side: the record type still makes the contact the child.
    $secondary->associateWith($secondaryEmployer, $type);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect(linksBetween($primary, $primaryEmployer))->toBe(1)
        ->and(linksBetween($primary, $secondaryEmployer))->toBe(0)
        ->and(Association::query()->where('association_type_id', $type->id)->count())->toBe(1);
});

it('moves every child of a One-to-Many parent, which may have many', function (): void {
    $type = AssociationType::create([
        'name' => 'employer',
        'label' => 'Employer',
        'cardinality' => AssociationCardinality::OneToMany,
        'from_record_type' => (new Company)->getMorphClass(),
        'to_record_type' => (new Contact)->getMorphClass(),
    ]);

    $primary = Company::factory()->create();
    $secondary = Company::factory()->create();
    $dana = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    $sam = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    $primary->associateWith($dana, $type);
    $secondary->associateWith($sam, $type);

    app(MergeCompaniesAction::class)->execute($primary, $secondary);

    expect(linksBetween($primary, $dana))->toBe(1)
        ->and(linksBetween($primary, $sam))->toBe(1);
});

it('drops a moved One-to-One link when the primary already has one', function (): void {
    $type = AssociationType::create([
        'name' => 'billing_contact',
        'label' => 'Billing Contact',
        'cardinality' => AssociationCardinality::OneToOne,
    ]);

    $primary = Company::factory()->create();
    $secondary = Company::factory()->create();
    $dana = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    $sam = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    $alex = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    $primary->associateWith($dana, $type);
    $secondary->associateWith($sam, $type);
    $secondary->associateWith($alex); // many-to-many default links still move

    app(MergeCompaniesAction::class)->execute($primary, $secondary);

    expect(linksBetween($primary, $dana))->toBe(1)
        ->and(linksBetween($primary, $sam))->toBe(0)
        ->and(linksBetween($primary, $alex))->toBe(1)
        ->and(DB::table('odden_associations')->where('association_type_id', $type->id)->count())->toBe(1);
});

it('moves a One-to-One link when the primary has none of that type', function (): void {
    $type = AssociationType::create([
        'name' => 'billing_contact',
        'label' => 'Billing Contact',
        'cardinality' => AssociationCardinality::OneToOne,
    ]);

    $primary = Company::factory()->create();
    $secondary = Company::factory()->create();
    $sam = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    $secondary->associateWith($sam, $type);

    app(MergeCompaniesAction::class)->execute($primary, $secondary);

    expect(linksBetween($primary, $sam))->toBe(1);
});
