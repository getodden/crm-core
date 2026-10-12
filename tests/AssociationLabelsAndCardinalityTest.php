<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Odden\Core\Actions\AssociateRecordsAction;
use Odden\Core\Actions\CreateAssociationTypeAction;
use Odden\Core\Actions\CreateCustomObjectDefinitionAction;
use Odden\Core\Actions\CreateCustomObjectRecordAction;
use Odden\Core\Enums\AssociationCardinality;
use Odden\Core\Exceptions\CardinalityViolationException;
use Odden\Core\Exceptions\DuplicateNameException;
use Odden\Core\Models\Association;
use Odden\Core\Models\AssociationType;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CustomObjectDefinition;

it('creates association types with labels and cardinality rules', function () {
    $action = app(CreateAssociationTypeAction::class);

    $type = $action->execute([
        'name' => 'billing_contact',
        'label' => 'Billing Contact',
        'reverse_label' => 'Billed Company',
        'cardinality' => AssociationCardinality::OneToOne,
        'from_record_type' => (new Company)->getMorphClass(),
        'to_record_type' => (new Contact)->getMorphClass(),
    ]);

    expect($type)->toBeInstanceOf(AssociationType::class)
        ->and($type->name)->toBe('billing_contact')
        ->and($type->cardinality)->toBe(AssociationCardinality::OneToOne)
        ->and($type->label)->toBe('Billing Contact')
        ->and($type->reverse_label)->toBe('Billed Company');
});

it('attaches association type and label when associating records', function () {
    $type = AssociationType::create([
        'name' => 'primary_decision_maker',
        'label' => 'Primary Decision Maker',
        'reverse_label' => 'Associated Company',
        'cardinality' => AssociationCardinality::OneToMany,
    ]);

    $company = Company::factory()->create();
    $contact = Contact::factory()->create();

    $association = $company->associateWith($contact, $type);

    expect($association->association_type_id)->toBe($type->id)
        ->and($association->label)->toBe('Primary Decision Maker')
        ->and($association->associationType->id)->toBe($type->id);

    // Retrieve via getAssociatedByLabel
    $decisionMakers = $company->getAssociatedByLabel(Contact::class, 'Primary Decision Maker');
    expect($decisionMakers)->toHaveCount(1)
        ->and($decisionMakers->first()->id)->toBe($contact->id);
});

it('enforces OneToOne cardinality constraints strictly', function () {
    $type = AssociationType::create([
        'name' => 'exclusive_billing_contact',
        'label' => 'Exclusive Billing Contact',
        'cardinality' => AssociationCardinality::OneToOne,
    ]);

    $company = Company::factory()->create();
    $contact1 = Contact::factory()->create();
    $contact2 = Contact::factory()->create();

    $action = app(AssociateRecordsAction::class);

    // First association succeeds
    $action->execute($company, $contact1, $type);

    // Associating same company with another contact of same OneToOne type must fail
    expect(fn () => $action->execute($company, $contact2, $type))
        ->toThrow(CardinalityViolationException::class);
});

it('enforces OneToMany cardinality constraints preventing child re-parenting', function () {
    $type = AssociationType::create([
        'name' => 'parent_subsidiary',
        'label' => 'Parent Company',
        'reverse_label' => 'Subsidiary',
        'cardinality' => AssociationCardinality::OneToMany,
    ]);

    $parent1 = Company::factory()->create(['name' => 'Alphabet']);
    $parent2 = Company::factory()->create(['name' => 'Meta']);
    $subsidiary = Company::factory()->create(['name' => 'DeepMind']);

    $action = app(AssociateRecordsAction::class);

    // Link subsidiary to parent 1 (One-to-Many)
    $action->execute($parent1, $subsidiary, $type);

    // Attempting to link same subsidiary to parent 2 under same OneToMany type must fail
    expect(fn () => $action->execute($parent2, $subsidiary, $type))
        ->toThrow(CardinalityViolationException::class);
});

it('resolves contextual bidirectional labels correctly', function () {
    $type = AssociationType::create([
        'name' => 'partner_referral',
        'label' => 'Referred Partner',
        'reverse_label' => 'Originating Agency',
        'cardinality' => AssociationCardinality::ManyToMany,
        'from_record_type' => (new Company)->getMorphClass(),
        'to_record_type' => (new Contact)->getMorphClass(),
    ]);

    $company = Company::factory()->create();
    $contact = Contact::factory()->create();

    expect($type->getLabelFor($company))->toBe('Referred Partner')
        ->and($type->getLabelFor($contact))->toBe('Originating Agency');
});

it('enforces OneToMany cardinality whichever record the link is made from', function () {
    $type = AssociationType::create([
        'name' => 'employer',
        'label' => 'Employee',
        'reverse_label' => 'Employer',
        'cardinality' => AssociationCardinality::OneToMany,
        'from_record_type' => (new Company)->getMorphClass(),
        'to_record_type' => (new Contact)->getMorphClass(),
    ]);

    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $contact = Contact::factory()->create();

    $companyA->associateWith($contact, 'employer');

    // Re-linking the same pair from the other side is not a second employer.
    expect($contact->associateWith($companyA, 'employer'))->toBeInstanceOf(Association::class)
        ->and(fn () => $contact->associateWith($companyB, 'employer'))->toThrow(CardinalityViolationException::class);

    // A company can still employ several contacts, linked from either side.
    expect(Contact::factory()->create()->associateWith($companyA, $type))->toBeInstanceOf(Association::class);
});

it('enforces OneToOne cardinality whichever record the link is made from', function () {
    AssociationType::create([
        'name' => 'billing_contact',
        'label' => 'Billing contact',
        'cardinality' => AssociationCardinality::OneToOne,
        'from_record_type' => (new Company)->getMorphClass(),
        'to_record_type' => (new Contact)->getMorphClass(),
    ]);

    $company = Company::factory()->create();
    $jane = Contact::factory()->create();
    $sam = Contact::factory()->create();

    $jane->associateWith($company, 'billing_contact');

    expect(fn () => $company->associateWith($sam, 'billing_contact'))->toThrow(CardinalityViolationException::class)
        ->and(fn () => $sam->associateWith($company, 'billing_contact'))->toThrow(CardinalityViolationException::class)
        ->and(fn () => $jane->associateWith(Company::factory()->create(), 'billing_contact'))->toThrow(CardinalityViolationException::class);
});

it('finds labelled records from the side the label describes', function () {
    AssociationType::create([
        'name' => 'parent_subsidiary',
        'label' => 'Subsidiary',
        'reverse_label' => 'Parent Company',
        'cardinality' => AssociationCardinality::OneToMany,
    ]);

    $alphabet = Company::factory()->create(['name' => 'Alphabet']);
    $deepMind = Company::factory()->create(['name' => 'DeepMind']);
    $association = $alphabet->associateWith($deepMind, 'parent_subsidiary');

    expect($alphabet->getAssociatedByLabel(Company::class, 'Subsidiary')->pluck('id')->all())->toBe([$deepMind->id])
        ->and($alphabet->getAssociatedByLabel(Company::class, 'Parent Company'))->toHaveCount(0)
        ->and($deepMind->getAssociatedByLabel(Company::class, 'Parent Company')->pluck('id')->all())->toBe([$alphabet->id])
        ->and($deepMind->getAssociatedByLabel(Company::class, 'Subsidiary'))->toHaveCount(0)
        ->and($association->associationType->getLabelFor($alphabet, $association))->toBe('Subsidiary')
        ->and($association->associationType->getLabelFor($deepMind, $association))->toBe('Parent Company');
});

it('lets each team use the same association type and custom object names', function () {
    $typesTable = 'odden_association_types';
    $now = now();

    // The database itself accepts the same name in two teams.
    DB::table($typesTable)->insert([
        ['name' => 'partner', 'label' => 'Partner', 'team_id' => 1, 'created_at' => $now, 'updated_at' => $now],
        ['name' => 'partner', 'label' => 'Partner', 'team_id' => 2, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $definitions = app(CreateCustomObjectDefinitionAction::class);
    $definitions->execute(['name' => 'License', 'singular_label' => 'License', 'team_id' => 1]);
    $definitions->execute(['name' => 'License', 'singular_label' => 'License', 'team_id' => 2]);

    expect(AssociationType::where('name', 'partner')->count())->toBe(2)
        ->and(CustomObjectDefinition::where('name', 'license')->count())->toBe(2)
        ->and(fn () => AssociationType::create(['name' => 'partner', 'label' => 'Partner', 'team_id' => 1]))->toThrow(DuplicateNameException::class)
        ->and(fn () => $definitions->execute(['name' => 'License', 'singular_label' => 'License', 'team_id' => 2]))->toThrow(DuplicateNameException::class);

    // Without a team, the name is still unique.
    AssociationType::create(['name' => 'shared', 'label' => 'Shared']);
    expect(fn () => AssociationType::create(['name' => 'shared', 'label' => 'Shared']))->toThrow(DuplicateNameException::class);
});

it('only applies association types and custom objects from the record team', function () {
    $teamOneType = AssociationType::create([
        'name' => 'champion',
        'label' => 'Champion',
        'cardinality' => AssociationCardinality::OneToOne,
        'team_id' => 1,
    ]);
    $sharedType = AssociationType::create(['name' => 'advisor', 'label' => 'Advisor']);
    $teamTwoAdvisor = AssociationType::create(['name' => 'advisor', 'label' => 'Team two advisor', 'team_id' => 2]);

    $teamTwoCompany = Company::factory()->create(['team_id' => 2]);
    $teamTwoContact = Contact::factory()->create(['team_id' => 2]);

    // Team one's one-to-one type doesn't apply to team two's records.
    $link = $teamTwoCompany->associateWith($teamTwoContact, 'champion');
    expect($link->association_type_id)->toBeNull()
        ->and(fn () => $teamTwoCompany->associateWith(Contact::factory()->create(['team_id' => 2]), 'champion'))->not->toThrow(CardinalityViolationException::class)
        ->and($teamTwoCompany->associateWith($teamTwoContact, 'advisor')->association_type_id)->toBe($teamTwoAdvisor->id)
        ->and(Company::factory()->create()->associateWith(Contact::factory()->create(), 'advisor')->association_type_id)->toBe($sharedType->id)
        ->and(Company::factory()->create(['team_id' => 1])->associateWith(Contact::factory()->create(['team_id' => 1]), 'champion')->association_type_id)->toBe($teamOneType->id);

    $definitions = app(CreateCustomObjectDefinitionAction::class);
    $definitions->execute(['name' => 'Asset', 'singular_label' => 'Asset', 'team_id' => 1]);

    expect(fn () => app(CreateCustomObjectRecordAction::class)->execute('asset', ['name' => 'Laptop', 'team_id' => 2]))
        ->toThrow(InvalidArgumentException::class);
});
