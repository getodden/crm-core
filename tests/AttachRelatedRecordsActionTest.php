<?php

declare(strict_types=1);

use Odden\Core\Actions\AttachRelatedRecordsAction;
use Odden\Core\Enums\AssociationCardinality;
use Odden\Core\Exceptions\CardinalityViolationException;
use Odden\Core\Exceptions\CrossTenantException;
use Odden\Core\Models\Association;
use Odden\Core\Models\AssociationType;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

function oneToOneType(): AssociationType
{
    return AssociationType::create([
        'name' => 'sponsor',
        'label' => 'Sponsor',
        'reverse_label' => 'Sponsored',
        'cardinality' => AssociationCardinality::OneToOne,
    ]);
}

it('links the owner as the parent of every related record', function (): void {
    $company = Company::factory()->create();
    $contacts = Contact::factory()->count(2)->create();

    app(AttachRelatedRecordsAction::class)->execute($company, $contacts, 'default', true);

    expect(Association::query()->where('parent_id', $company->id)->where('parent_type', $company->getMorphClass())->count())->toBe(2)
        ->and(Association::query()->whereIn('child_id', $contacts->modelKeys())->where('child_type', $contacts->first()->getMorphClass())->count())->toBe(2);
});

it('links the owner as the child of every related record, with the given type', function (): void {
    $contact = Contact::factory()->create();
    $companies = Company::factory()->count(2)->create();
    oneToOneType();

    app(AttachRelatedRecordsAction::class)->execute($contact, $companies->take(1), 'sponsor', false);

    $link = Association::query()->where('child_id', $contact->id)->where('child_type', $contact->getMorphClass())->sole();

    expect($link->parent_id)->toBe($companies->first()->id)
        ->and($link->type)->toBe('sponsor');
});

it('keeps the links made before a cardinality violation', function (): void {
    $company = Company::factory()->create();
    $first = Contact::factory()->create();
    $second = Contact::factory()->create();
    oneToOneType();

    expect(fn () => app(AttachRelatedRecordsAction::class)->execute($company, [$first, $second], 'sponsor'))
        ->toThrow(CardinalityViolationException::class);

    expect(Association::query()->where('parent_id', $company->id)->pluck('child_id')->all())->toBe([$first->id]);
});

it('refuses records of another team', function (): void {
    $company = Company::factory()->create()->setAttribute('tenant_id', 1);
    $contact = Contact::factory()->create()->setAttribute('tenant_id', 2);

    expect(fn () => app(AttachRelatedRecordsAction::class)->execute($company, [$contact]))
        ->toThrow(CrossTenantException::class)
        ->and(Association::query()->count())->toBe(0);
});
