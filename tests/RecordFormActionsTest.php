<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Odden\Core\Actions\CreateCompanyAction;
use Odden\Core\Actions\CreateContactAction;
use Odden\Core\Actions\CreateCrmListAction;
use Odden\Core\Actions\DeleteActivityAction;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Core\Actions\SaveModelAction;
use Odden\Core\Actions\UpdateActivityAction;
use Odden\Core\Actions\UpdateCompanyAction;
use Odden\Core\Actions\UpdateContactAction;
use Odden\Core\Actions\UpdateCrmListAction;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Events\ModelDeleted;
use Odden\Core\Events\ModelSaved;
use Odden\Core\Events\UserMentioned;
use Odden\Core\Exceptions\CrossTenantException;
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;
use Odden\Core\Exceptions\InvalidListCriteriaException;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\LifecycleStageTransition;
use Odden\Core\Models\PropertyDefinition;
use Odden\Core\Tests\Fixtures\User;

function actingInTenantOne(): void
{
    app()->instance(TenantContext::class, new class implements TenantContext
    {
        public function id(): int|string|null
        {
            return 1;
        }

        public function scopeUsers(Builder $users): Builder
        {
            return $users;
        }
    });
}

it('creates a contact and a company with custom properties', function (): void {
    config(['odden-core.email_validation.enabled' => false]);

    $contact = app(CreateContactAction::class)->execute(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ADA@example.com', 'properties' => ['plan' => 'gold']]);
    $company = app(CreateCompanyAction::class)->execute(['name' => 'Analytical', 'properties' => ['tier' => 'a']]);

    expect($contact->fresh()->properties)->toBe(['plan' => 'gold'])
        ->and($company->fresh()->properties)->toBe(['tier' => 'a']);
});

it('updates a contact and keeps the custom properties the form does not carry', function (): void {
    $contact = Contact::factory()->create(['first_name' => 'Ada', 'properties' => ['plan' => 'gold', 'answer' => 'yes']]);

    app(UpdateContactAction::class)->execute($contact, ['first_name' => 'Grace', 'properties' => ['plan' => 'silver']]);

    expect($contact->fresh()->first_name)->toBe('Grace')
        ->and($contact->fresh()->properties)->toBe(['plan' => 'silver', 'answer' => 'yes']);
});

it('moves a contact through the lifecycle rules and records the transition', function (): void {
    $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead, 'first_name' => 'Ada']);

    app(UpdateContactAction::class)->execute($contact, ['first_name' => 'Grace', 'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead->value], 7);

    $transition = LifecycleStageTransition::query()->where('record_id', $contact->id)->latest('id')->firstOrFail();

    expect($contact->fresh()->lifecycle_stage)->toBe(LifecycleStage::MarketingQualifiedLead)
        ->and($contact->fresh()->first_name)->toBe('Grace')
        ->and($transition->to_stage)->toBe(LifecycleStage::MarketingQualifiedLead);
});

it('refuses a lifecycle jump and saves nothing', function (): void {
    config(['odden-core.lifecycle.strict_transitions' => true]);
    $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Subscriber, 'first_name' => 'Ada']);

    expect(fn () => app(UpdateContactAction::class)->execute($contact, ['first_name' => 'Grace', 'lifecycle_stage' => LifecycleStage::Evangelist->value]))
        ->toThrow(InvalidLifecycleStageTransitionException::class);

    expect($contact->fresh()->first_name)->toBe('Ada')
        ->and($contact->fresh()->lifecycle_stage)->toBe(LifecycleStage::Subscriber);
});

it('updates a company and merges its custom properties', function (): void {
    $company = Company::factory()->create(['name' => 'Old', 'properties' => ['tier' => 'a', 'region' => 'eu']]);

    app(UpdateCompanyAction::class)->execute($company, ['name' => 'New', 'properties' => ['tier' => 'b']]);

    expect($company->fresh()->name)->toBe('New')
        ->and($company->fresh()->properties)->toBe(['tier' => 'b', 'region' => 'eu']);
});

it('saves a model through the generic action, filling only fillable attributes', function (): void {
    $created = app(SaveModelAction::class)->execute(PropertyDefinition::class, [
        'entity_type' => (new Contact)->getMorphClass(),
        'name' => 'favourite_colour',
        'label' => 'Favourite colour',
        'type' => 'text',
        'id' => 999,
    ]);

    expect($created->exists)->toBeTrue()
        ->and($created->id)->not->toBe(999);

    app(SaveModelAction::class)->execute($created, ['label' => 'Colour']);

    expect($created->fresh()->label)->toBe('Colour');
});

it('deletes a model through the generic action', function (): void {
    $list = CrmList::factory()->create();

    expect(app(DeleteModelAction::class)->execute($list))->toBeTrue()
        ->and(CrmList::query()->count())->toBe(0);
});

it('announces generic saves and deletes so a host can audit them', function (): void {
    Event::fake([ModelSaved::class, ModelDeleted::class]);

    $list = app(SaveModelAction::class)->execute(CrmList::class, ['name' => 'Fresh', 'entity_type' => (new Contact)->getMorphClass(), 'type' => 'static']);
    app(SaveModelAction::class)->execute($list, ['name' => 'Renamed']);
    app(DeleteModelAction::class)->execute($list);

    Event::assertDispatched(ModelSaved::class, fn (ModelSaved $event): bool => $event->wasCreated && $event->model->is($list));
    Event::assertDispatched(ModelSaved::class, fn (ModelSaved $event): bool => ! $event->wasCreated);
    Event::assertDispatched(ModelDeleted::class, fn (ModelDeleted $event): bool => $event->model->is($list));
});

it('keeps an activity on its subject and creator when an update tries to move it', function (): void {
    $creator = User::factory()->create();
    $contact = Contact::factory()->create();
    $other = Contact::factory()->create();
    $activity = Activity::factory()->create(['subject_type' => $contact->getMorphClass(), 'subject_id' => $contact->id, 'creator_id' => $creator->id]);

    app(UpdateActivityAction::class)->execute($activity, ['title' => 'Renamed', 'subject_type' => $other->getMorphClass(), 'subject_id' => $other->id, 'creator_id' => 99]);

    $fresh = $activity->fresh();
    expect($fresh->title)->toBe('Renamed')
        ->and($fresh->subject_id)->toBe($contact->id)
        ->and($fresh->creator_id)->toBe($creator->id);
});

it('refuses to save or delete a record of another tenant', function (): void {
    $list = CrmList::factory()->create();
    $list->setRawAttributes([...$list->getAttributes(), 'tenant_id' => 2]);
    actingInTenantOne();

    expect(fn () => app(DeleteModelAction::class)->execute($list))->toThrow(CrossTenantException::class)
        ->and(fn () => app(SaveModelAction::class)->execute($list, ['name' => 'Taken']))->toThrow(CrossTenantException::class)
        ->and(CrmList::query()->count())->toBe(1);
});

it('creates a list for its creator and fills an active list at once', function (): void {
    $creator = User::factory()->create();
    Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer]);

    $static = app(CreateCrmListAction::class)->execute(['name' => 'Static', 'entity_type' => (new Contact)->getMorphClass(), 'type' => 'static'], $creator->id);
    $active = app(CreateCrmListAction::class)->execute([
        'name' => 'Leads',
        'entity_type' => (new Contact)->getMorphClass(),
        'type' => 'active',
        'criteria' => [['field' => 'lifecycle_stage', 'operator' => '=', 'value' => 'lead']],
    ], $creator->id);

    expect($static->created_by_id)->toBe($creator->id)
        ->and($active->memberships()->count())->toBe(1);
});

it('updates an active list and refreshes its members', function (): void {
    $creator = User::factory()->create();
    Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
    Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer]);
    $list = app(CreateCrmListAction::class)->execute([
        'name' => 'Leads',
        'entity_type' => (new Contact)->getMorphClass(),
        'type' => 'active',
        'criteria' => [['field' => 'lifecycle_stage', 'operator' => '=', 'value' => 'lead']],
    ], $creator->id);

    app(UpdateCrmListAction::class)->execute($list, ['name' => 'Customers', 'criteria' => [['field' => 'lifecycle_stage', 'operator' => '=', 'value' => 'customer']]]);

    expect($list->fresh()->name)->toBe('Customers')
        ->and($list->memberships()->count())->toBe(1);
});

it('keeps a list whose rules cannot be evaluated, on create and on update', function (): void {
    $badCriteria = [['field' => 'lifecycle_stage', 'operator' => 'no-such-operator', 'value' => 'lead']];
    $creator = User::factory()->create();
    $attributes = ['name' => 'Broken', 'entity_type' => (new Contact)->getMorphClass(), 'type' => 'active', 'criteria' => $badCriteria];

    expect(fn () => app(CreateCrmListAction::class)->execute($attributes, $creator->id))->toThrow(InvalidListCriteriaException::class)
        ->and(CrmList::query()->where('name', 'Broken')->exists())->toBeTrue();

    $list = CrmList::factory()->create(['name' => 'Fine']);

    expect(fn () => app(UpdateCrmListAction::class)->execute($list, ['name' => 'Renamed', 'type' => 'active', 'criteria' => $badCriteria]))->toThrow(InvalidListCriteriaException::class)
        ->and($list->fresh()->name)->toBe('Renamed');
});

it('records a string creator id on a list', function (): void {
    $creator = User::factory()->create();
    $list = app(CreateCrmListAction::class)->execute(['name' => 'Static', 'entity_type' => (new Contact)->getMorphClass(), 'type' => 'static'], (string) $creator->id);

    expect($list->fresh()->created_by_id)->toEqual($creator->id);
});

it('updates an activity and deletes it', function (): void {
    $contact = Contact::factory()->create();
    $activity = Activity::factory()->note()->create(['subject_type' => $contact->getMorphClass(), 'subject_id' => $contact->id]);

    app(UpdateActivityAction::class)->execute($activity, ['title' => 'Renamed', 'body' => 'Hello'], 3);

    expect($activity->fresh()->title)->toBe('Renamed');

    expect(app(DeleteActivityAction::class)->execute($activity))->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});

it('refuses to update a contact or company of another tenant', function (): void {
    $contact = Contact::factory()->create(['first_name' => 'Ada']);
    $company = Company::factory()->create(['name' => 'Analytical']);
    $contact->setRawAttributes([...$contact->getAttributes(), 'tenant_id' => 2]);
    $company->setRawAttributes([...$company->getAttributes(), 'tenant_id' => 2]);
    actingInTenantOne();

    expect(fn () => app(UpdateContactAction::class)->execute($contact, ['first_name' => 'Grace']))->toThrow(CrossTenantException::class)
        ->and(fn () => app(UpdateCompanyAction::class)->execute($company, ['name' => 'Taken']))->toThrow(CrossTenantException::class)
        ->and(Contact::query()->value('first_name'))->toBe('Ada')
        ->and(Company::query()->value('name'))->toBe('Analytical');
});

it('records the mentions of an activity against the record the activity is on', function (): void {
    Event::fake([UserMentioned::class]);
    [$author, $jane] = User::factory()->count(2)->create();
    $contact = Contact::factory()->create();
    $note = $contact->logNote('Ask Jane');

    app(UpdateActivityAction::class)->execute($note, ['title' => 'Renewal'], $author->id, [$jane->id], [$jane->id => 'Jane']);

    Event::assertDispatched(UserMentioned::class, fn (UserMentioned $event): bool => $event->mentionedUserId === $jane->id && $event->subject->is($contact));
});

it('refuses to update or delete an activity or update a list of another tenant', function (): void {
    $contact = Contact::factory()->create();
    $activity = Activity::factory()->note()->create(['title' => 'Mine', 'subject_type' => $contact->getMorphClass(), 'subject_id' => $contact->id]);
    $list = CrmList::factory()->create(['name' => 'Mine']);
    $activity->setRawAttributes([...$activity->getAttributes(), 'tenant_id' => 2]);
    $list->setRawAttributes([...$list->getAttributes(), 'tenant_id' => 2]);
    actingInTenantOne();

    expect(fn () => app(UpdateActivityAction::class)->execute($activity, ['title' => 'Taken']))->toThrow(CrossTenantException::class)
        ->and(fn () => app(DeleteActivityAction::class)->execute($activity))->toThrow(CrossTenantException::class)
        ->and(fn () => app(UpdateCrmListAction::class)->execute($list, ['name' => 'Taken']))->toThrow(CrossTenantException::class)
        ->and(Activity::query()->value('title'))->toBe('Mine')
        ->and(CrmList::query()->value('name'))->toBe('Mine');
});

it('records the mentions of a note on a contact in the trash', function (): void {
    Event::fake([UserMentioned::class]);
    [$author, $jane] = User::factory()->count(2)->create();
    $contact = Contact::factory()->create();
    $note = $contact->logNote('Ask Jane');
    $contact->delete();

    app(UpdateActivityAction::class)->execute($note->fresh(), ['title' => 'Renewal'], $author->id, [$jane->id], [$jane->id => 'Jane']);

    Event::assertDispatched(UserMentioned::class, fn (UserMentioned $event): bool => $event->mentionedUserId === $jane->id);
});

it('keeps consent proof, tokens and lifecycle stamps off a contact update', function (): void {
    $contact = Contact::factory()->create(['first_name' => 'Ada', 'lifecycle_stage' => LifecycleStage::Lead, 'became_customer_at' => null]);

    app(UpdateContactAction::class)->execute($contact, [
        'first_name' => 'Grace',
        'marketing_email_verified_at' => now(),
        'sms_consent_at' => now(),
        'marketing_confirmation_token' => 'known',
        'became_customer_at' => now(),
    ]);

    $fresh = $contact->fresh();
    expect($fresh->first_name)->toBe('Grace')
        ->and($fresh->marketing_email_verified_at)->toBeNull()
        ->and($fresh->sms_consent_at)->toBeNull()
        ->and($fresh->marketing_confirmation_token)->toBeNull()
        ->and($fresh->became_customer_at)->toBeNull();
});

it('keeps a list with its creator when an update carries another', function (): void {
    $creator = User::factory()->create();
    $list = CrmList::factory()->create(['created_by_id' => $creator->id]);

    app(UpdateCrmListAction::class)->execute($list, ['name' => 'Renamed', 'created_by_id' => 99]);

    expect($list->fresh()->name)->toBe('Renamed')
        ->and($list->fresh()->created_by_id)->toBe($creator->id);
});

it('normalises a company domain on update', function (): void {
    $company = Company::factory()->create();

    app(UpdateCompanyAction::class)->execute($company, ['domain' => 'https://www.Acme.com/about']);

    expect($company->fresh()->domain)->toBe('acme.com');
});

it('announces a generic save or delete only once the transaction has committed', function (): void {
    $levelBefore = DB::transactionLevel();
    $levels = [];

    Event::listen([ModelSaved::class, ModelDeleted::class], function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    $list = app(SaveModelAction::class)->execute(CrmList::class, ['name' => 'Fresh', 'entity_type' => (new Contact)->getMorphClass(), 'type' => 'static']);
    app(DeleteModelAction::class)->execute($list);

    expect($levels)->toBe([$levelBefore, $levelBefore]);
});

it('keeps the consent, email status and engagement state of a contact out of an update', function (): void {
    $contact = Contact::factory()->create(['first_name' => 'Ada', 'email_status' => 'invalid']);
    $before = $contact->fresh();

    app(UpdateContactAction::class)->execute($contact, [
        'first_name' => 'Grace',
        'sms_consent' => true,
        'email_status' => 'valid',
        'is_unengaged' => true,
        'sunset_stage' => 3,
    ]);

    $fresh = $contact->fresh();
    expect($fresh->first_name)->toBe('Grace')
        ->and($fresh->sms_consent)->toBe($before->sms_consent)
        ->and($fresh->email_status)->toBe($before->email_status)
        ->and($fresh->is_unengaged)->toBe($before->is_unengaged)
        ->and($fresh->sunset_stage)->toBe($before->sunset_stage);
});

it('keeps the stage stamps of a company out of an update', function (): void {
    $company = Company::factory()->create(['name' => 'Old', 'became_customer_at' => null, 'last_health_calculated_at' => null]);

    app(UpdateCompanyAction::class)->execute($company, ['name' => 'New', 'became_customer_at' => now(), 'last_health_calculated_at' => now()]);

    expect($company->fresh()->name)->toBe('New')
        ->and($company->fresh()->became_customer_at)->toBeNull()
        ->and($company->fresh()->last_health_calculated_at)->toBeNull();
});

it('announces nothing when a save is cancelled by a listener', function (): void {
    Event::fake([ModelSaved::class]);
    CrmList::saving(fn (): bool => false);

    app(SaveModelAction::class)->execute(CrmList::class, ['name' => 'Fresh', 'entity_type' => (new Contact)->getMorphClass(), 'type' => 'static']);

    Event::assertNotDispatched(ModelSaved::class);
    CrmList::flushEventListeners();
});
