<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Actions\EvaluateActiveListAction;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Enums\ListType;
use Odden\Core\Exceptions\InvalidListCriteriaException;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;

it('can manage static list members', function (): void {
    $list = CrmList::create([
        'name' => 'Q4 Event Invitees',
        'entity_type' => 'contact',
        'type' => ListType::Static,
    ]);

    $contact1 = Contact::factory()->create();
    $contact2 = Contact::factory()->create();

    $list->addMember($contact1);

    expect($list->hasMember($contact1))->toBeTrue()
        ->and($list->hasMember($contact2))->toBeFalse()
        ->and($list->contacts)->toHaveCount(1);

    $list->removeMember($contact1);

    expect($list->hasMember($contact1))->toBeFalse();
});

it('evaluates active smart list criteria dynamically', function (): void {
    $activeList = CrmList::create([
        'name' => 'High Value Leads',
        'entity_type' => 'contact',
        'type' => ListType::Active,
        'criteria' => [
            [
                'property' => 'lifecycle_stage',
                'operator' => '=',
                'value' => 'lead',
            ],
            [
                'property' => 'lead_score',
                'operator' => '>=',
                'value' => 50,
            ],
        ],
    ]);

    // Matching contact: lead with score 80
    $match = Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Lead,
        'lead_score' => 80,
    ]);

    // Non-matching 1: lead with score 30
    Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Lead,
        'lead_score' => 30,
    ]);

    // Non-matching 2: customer with score 90
    Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Customer,
        'lead_score' => 90,
    ]);

    $syncedCount = $activeList->syncActiveMembers();

    expect($syncedCount)->toBe(1)
        ->and($activeList->contacts)->toHaveCount(1)
        ->and($activeList->contacts->first()->id)->toBe($match->id);
});

it('evaluates the rule shape the Filament panel saves', function (): void {
    // The panel stores the morph class, `field`, named operators, and text values.
    $list = CrmList::create([
        'name' => 'Engaged leads',
        'entity_type' => (new Contact)->getMorphClass(),
        'type' => ListType::Active,
        'criteria' => [
            ['field' => 'lifecycle_stage', 'operator' => 'equals', 'value' => 'lead'],
            ['field' => 'lead_score', 'operator' => 'greater_than', 'value' => '50'],
            ['field' => 'region', 'operator' => 'not_equals', 'value' => 'US'],
            ['field' => 'phone', 'operator' => 'is_set', 'value' => null],
        ],
    ]);

    $match = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead, 'lead_score' => 80, 'properties' => ['region' => 'EU']]);
    Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer, 'lead_score' => 80, 'properties' => ['region' => 'EU']]);
    Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead, 'lead_score' => 20, 'properties' => ['region' => 'EU']]);
    Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead, 'lead_score' => 80, 'properties' => ['region' => 'US']]);
    Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead, 'lead_score' => 80, 'phone' => null, 'properties' => ['region' => 'EU']]);

    expect($list->syncActiveMembers())->toBe(1)
        ->and($list->contacts()->pluck('odden_contacts.id')->all())->toBe([$match->id]);
});

it('evaluates the factory default active list', function (): void {
    $list = CrmList::factory()->active()->create();
    $lead = Contact::factory()->lead()->create();
    Contact::factory()->customer()->create();

    expect($list->syncActiveMembers())->toBe(1)
        ->and($list->hasMember($lead))->toBeTrue();
});

it('evaluates company lists saved with the company morph class', function (string $entityType): void {
    $list = CrmList::create([
        'name' => 'Tier 1 accounts',
        'entity_type' => $entityType,
        'type' => ListType::Active,
        'criteria' => [['field' => 'name', 'operator' => 'equals', 'value' => 'Acme']],
    ]);

    $acme = Company::create(['name' => 'Acme', 'domain' => 'acme.test']);
    Company::create(['name' => 'Globex', 'domain' => 'globex.test']);
    Contact::factory()->create();

    expect($list->syncActiveMembers())->toBe(1)
        ->and($list->companies()->pluck('odden_companies.id')->all())->toBe([$acme->id])
        ->and($list->contacts()->count())->toBe(0);
})->with([
    'short name' => 'company',
    'morph class' => fn (): string => (new Company)->getMorphClass(),
]);

it('refuses entity types, operators, and rules it does not understand', function (array $attributes): void {
    Contact::factory()->create();

    $list = CrmList::create(array_merge([
        'name' => 'Broken',
        'entity_type' => 'contact',
        'type' => ListType::Active,
        'criteria' => [],
    ], $attributes));

    expect(fn () => $list->syncActiveMembers())->toThrow(InvalidListCriteriaException::class)
        ->and($list->memberships()->count())->toBe(0);
})->with([
    'unknown entity type' => [['entity_type' => 'deal']],
    'unknown operator' => [['criteria' => [['field' => 'lifecycle_stage', 'operator' => 'starts_with', 'value' => 'l']]]],
    'rule without a property' => [['criteria' => [['operator' => 'equals', 'value' => 'lead']]]],
]);

it('matches is_null on a real column against the column only', function (): void {
    $list = CrmList::create([
        'name' => 'No phone',
        'entity_type' => 'contact',
        'type' => ListType::Active,
        'criteria' => [['property' => 'phone', 'operator' => 'is_null']],
    ]);

    $withoutPhone = Contact::factory()->create(['phone' => null]);
    Contact::factory()->create(['phone' => '+1 555 0100']);

    // A custom property that shares a column's name doesn't count either.
    Contact::factory()->create(['phone' => '+1 555 0101', 'lead_score' => 0, 'properties' => ['lead_score' => 99]]);

    $highScore = CrmList::create([
        'name' => 'High score',
        'entity_type' => 'contact',
        'type' => ListType::Active,
        'criteria' => [['property' => 'lead_score', 'operator' => '>=', 'value' => 50]],
    ]);

    expect($list->syncActiveMembers())->toBe(1)
        ->and($list->hasMember($withoutPhone))->toBeTrue()
        ->and($highScore->syncActiveMembers())->toBe(0);
});

it('compares custom properties numerically', function (): void {
    $list = CrmList::create([
        'name' => 'Big budgets',
        'entity_type' => 'contact',
        'type' => ListType::Active,
        'criteria' => [['field' => 'budget', 'operator' => 'greater_than', 'value' => '50']],
    ]);

    $number = Contact::factory()->create(['properties' => ['budget' => 100]]);
    $numericText = Contact::factory()->create(['properties' => ['budget' => '200']]);
    Contact::factory()->create(['properties' => ['budget' => 30]]);
    Contact::factory()->create(['properties' => ['budget' => '9']]);
    Contact::factory()->create(['properties' => ['budget' => 'unknown']]);
    Contact::factory()->create(['properties' => []]);

    $list->syncActiveMembers();

    expect($list->contacts()->pluck('odden_contacts.id')->sort()->values()->all())
        ->toBe([$number->id, $numericText->id]);
});

it('syncs memberships without binding every matching id', function (): void {
    // More rows than SQLite allows placeholders in one statement (32,766).
    $now = now();
    foreach (array_chunk(range(1, 33_000), 1_000) as $chunk) {
        DB::table('odden_contacts')->insert(array_map(fn (int $i): array => [
            'email' => "bulk{$i}@example.test",
            'lifecycle_stage' => 'lead',
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    $list = CrmList::create([
        'name' => 'Everyone who is a lead',
        'entity_type' => 'contact',
        'type' => ListType::Active,
        'criteria' => [['field' => 'lifecycle_stage', 'operator' => 'equals', 'value' => 'lead']],
    ]);

    expect($list->syncActiveMembers())->toBe(33_000)
        ->and($list->memberships()->count())->toBe(33_000);

    $firstTen = DB::table('odden_contacts')->orderBy('id')->limit(10)->pluck('id');
    DB::table('odden_contacts')->whereIn('id', $firstTen)->update(['lifecycle_stage' => 'customer']);

    expect($list->syncActiveMembers())->toBe(32_990)
        ->and($list->memberships()->count())->toBe(32_990);
});

it('keeps memberships another sync already added', function (): void {
    $lead = Contact::factory()->lead()->create();
    $customer = Contact::factory()->customer()->create();
    $list = CrmList::factory()->active()->create();

    $list->addMember($lead);
    $list->addMember($customer);

    expect($list->syncActiveMembers())->toBe(1)
        ->and($list->hasMember($lead))->toBeTrue()
        ->and($list->hasMember($customer))->toBeFalse()
        ->and($list->memberships()->count())->toBe(1);
});

it('treats Marketing rules as unmatched when Marketing is not installed', function (): void {
    $contact = Contact::factory()->create();

    $evaluate = function (string $property, mixed $value): array {
        $list = CrmList::create([
            'name' => $property,
            'entity_type' => 'contact',
            'type' => ListType::Active,
            'criteria' => [['field' => $property, 'operator' => 'equals', 'value' => $value]],
        ]);

        $list->syncActiveMembers();

        return $list->contacts()->pluck('odden_contacts.id')->all();
    };

    expect($evaluate('has_downloaded_asset', true))->toBe([])
        ->and($evaluate('has_attended_event', '1'))->toBe([])
        ->and($evaluate('has_downloaded_asset', 'false'))->toBe([$contact->id]);
});

it('evaluates behavioral list rules against a renamed contacts table', function (): void {
    Schema::create('custom_asset_downloads', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('contact_id');
    });
    Schema::create('custom_event_registrations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('contact_id');
        $table->string('status');
    });

    $downloaded = Contact::factory()->create();
    $attended = Contact::factory()->create();
    $neither = Contact::factory()->create();

    DB::table('custom_asset_downloads')->insert(['contact_id' => $downloaded->id]);
    DB::table('custom_event_registrations')->insert(['contact_id' => $attended->id, 'status' => 'attended']);

    Schema::rename('odden_contacts', 'renamed_contacts');
    config([
        'odden-core.tables.contacts' => 'renamed_contacts',
        'odden-marketing.tables.asset_downloads' => 'custom_asset_downloads',
        'odden-marketing.tables.event_registrations' => 'custom_event_registrations',
    ]);

    $evaluate = function (string $property): array {
        $list = CrmList::create([
            'name' => $property,
            'entity_type' => 'contact',
            'type' => ListType::Active,
            'criteria' => [['property' => $property, 'operator' => '=', 'value' => true]],
        ]);

        app(EvaluateActiveListAction::class)->execute($list);

        return $list->contacts()->pluck('renamed_contacts.id')->all();
    };

    expect($evaluate('has_downloaded_asset'))->toBe([$downloaded->id])
        ->and($evaluate('has_attended_event'))->toBe([$attended->id]);
});
