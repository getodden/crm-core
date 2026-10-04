<?php

declare(strict_types=1);

use Odden\Core\Actions\FindDuplicateCompaniesAction;
use Odden\Core\Actions\FindDuplicateContactsAction;
use Odden\Core\Actions\MergeCompaniesAction;
use Odden\Core\Actions\MergeContactsAction;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

test('finds duplicate contacts by email and merges them preserving activities and associations', function () {
    $primary = Contact::factory()->create([
        'first_name' => 'Alice',
        'last_name' => 'Smith',
        'email' => 'alice@enterprise.com',
        'phone' => null,
        'lead_score' => 20,
    ]);

    $secondary = Contact::factory()->create([
        'first_name' => 'Alice',
        'last_name' => 'Smith',
        'email' => 'alice@enterprise.com',
        'phone' => '+1-555-987-6543',
        'lead_score' => 50,
    ]);

    // Add activity to secondary
    $secondary->logActivity(
        type: ActivityType::Call,
        title: 'Initial Discovery Call with secondary lead'
    );

    // Find duplicates
    $duplicates = app(FindDuplicateContactsAction::class)->execute();
    expect($duplicates)->not->toBeEmpty()
        ->and($duplicates[0]['match_value'])->toBe('alice@enterprise.com')
        ->and($duplicates[0]['contacts']->count())->toBe(2);

    // Merge secondary into primary
    $merged = app(MergeContactsAction::class)->execute($primary, $secondary);

    expect($merged->id)->toBe($primary->id)
        ->and($merged->phone)->toBe('+1-555-987-6543') // filled from secondary
        ->and($merged->lead_score)->toBe(50) // took max score
        ->and($secondary->fresh()->trashed())->toBeTrue(); // secondary soft deleted

    // Activity moved to primary
    expect($primary->activities()->where('title', 'Initial Discovery Call with secondary lead')->exists())->toBeTrue()
        ->and($primary->activities()->where('title', 'Contact Merged')->exists())->toBeTrue();
});

test('finds duplicate companies by domain and merges them', function () {
    $c1 = Company::factory()->create([
        'name' => 'Acme Inc',
        'domain' => 'acme.org',
        'industry' => null,
        'account_tier' => 'tier_3',
    ]);

    $c2 = Company::factory()->create([
        'name' => 'Acme Corporation',
        'domain' => 'acme.org',
        'industry' => 'SaaS',
        'account_tier' => 'tier_1',
    ]);

    $duplicates = app(FindDuplicateCompaniesAction::class)->execute();
    expect($duplicates)->not->toBeEmpty();

    $merged = app(MergeCompaniesAction::class)->execute($c1, $c2, ['account_tier' => 'tier_1']);

    expect($merged->id)->toBe($c1->id)
        ->and($merged->industry)->toBe('SaaS')
        ->and($merged->account_tier)->toBe('tier_1')
        ->and($c2->fresh()->trashed())->toBeTrue();
});

test('refuses to merge a contact or a company into itself and leaves its data alone', function () {
    $contact = Contact::factory()->create(['email' => 'solo@example.com']);
    $company = Company::factory()->create(['name' => 'Solo Ltd', 'domain' => 'solo.test']);
    $company->associateWith($contact);

    expect(fn () => app(MergeContactsAction::class)->execute($contact, Contact::find($contact->id)))
        ->toThrow(InvalidArgumentException::class, 'cannot be merged into itself')
        ->and(fn () => app(MergeCompaniesAction::class)->execute($company, Company::find($company->id)))
        ->toThrow(InvalidArgumentException::class, 'cannot be merged into itself');

    expect($contact->fresh()->trashed())->toBeFalse()
        ->and($company->fresh()->trashed())->toBeFalse()
        ->and($contact->getAssociated(Company::class))->toHaveCount(1);
});
