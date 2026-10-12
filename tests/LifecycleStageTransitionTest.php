<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Odden\Core\Actions\CalculateFunnelVelocityAction;
use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Events\LifecycleStageChanged;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;
use Odden\Core\Models\LifecycleStageTransition;
use Odden\Core\Support\LifecycleStateMachine;

it('transitions contact lifecycle stage and records transition history', function () {
    Event::fake([LifecycleStageChanged::class]);

    $contact = Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Lead,
    ]);
    $contact->created_at = now()->subDays(5);
    $contact->saveQuietly();

    $action = app(TransitionLifecycleStageAction::class);
    $transition = $action->execute($contact, LifecycleStage::MarketingQualifiedLead, source: 'inbound_form');

    expect($transition)->toBeInstanceOf(LifecycleStageTransition::class)
        ->and($transition->from_stage)->toBe(LifecycleStage::Lead)
        ->and($transition->to_stage)->toBe(LifecycleStage::MarketingQualifiedLead)
        ->and($transition->source)->toBe('inbound_form')
        ->and($transition->duration_seconds)->toBeGreaterThanOrEqual(430000) // approx 5 days in seconds
        ->and($transition->durationInDays())->toBeGreaterThanOrEqual(4.9);

    $contact->refresh();
    expect($contact->lifecycle_stage)->toBe(LifecycleStage::MarketingQualifiedLead)
        ->and($contact->became_marketing_qualified_lead_at)->not->toBeNull()
        ->and($contact->became_mql_at)->not->toBeNull();

    Event::assertDispatched(LifecycleStageChanged::class, function ($event) use ($contact) {
        return $event->record->id === $contact->id
            && $event->transition->to_stage === LifecycleStage::MarketingQualifiedLead;
    });
});

it('calculates duration between consecutive transitions accurately', function () {
    $baseTime = now();
    $contact = Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Lead,
    ]);
    $contact->created_at = $baseTime->copy()->subDays(10);
    $contact->saveQuietly();

    $action = app(TransitionLifecycleStageAction::class);

    // First transition: Lead -> MQL 7 days ago
    $this->travelTo($baseTime->copy()->subDays(7));
    $action->execute($contact, LifecycleStage::MarketingQualifiedLead);

    // Second transition: MQL -> SQL 3 days ago (duration in MQL was 4 days)
    $this->travelTo($baseTime->copy()->subDays(3));
    $t2 = $action->execute($contact, LifecycleStage::SalesQualifiedLead);

    expect($t2->from_stage)->toBe(LifecycleStage::MarketingQualifiedLead)
        ->and($t2->to_stage)->toBe(LifecycleStage::SalesQualifiedLead)
        ->and($t2->durationInDays())->toEqual(4.0)
        ->and($t2->formattedDuration())->toBe('4 days');

    $this->travelBack();

    $contact->refresh();
    expect($contact->lifecycleTransitions)->toHaveCount(2)
        ->and($contact->timeInCurrentStageDays())->toBeGreaterThanOrEqual(2.9);
});

it('transitions company lifecycle stage and tracks company stage history', function () {
    $company = Company::factory()->create([
        'lifecycle_stage' => LifecycleStage::Opportunity,
        'created_at' => now()->subDays(12),
    ]);

    $action = app(TransitionLifecycleStageAction::class);
    $transition = $action->execute($company, LifecycleStage::Customer, source: 'deal_won');

    expect($transition->from_stage)->toBe(LifecycleStage::Opportunity)
        ->and($transition->to_stage)->toBe(LifecycleStage::Customer)
        ->and($transition->source)->toBe('deal_won')
        ->and($transition->record_type)->toBe($company->getMorphClass())
        ->and($transition->record_id)->toBe($company->id);

    $company->refresh();
    expect($company->lifecycle_stage)->toBe(LifecycleStage::Customer)
        ->and($company->became_customer_at)->not->toBeNull();
});

it('calculates cohort funnel velocity metrics across transitions', function () {
    $c1 = Contact::factory()->create();
    $c2 = Contact::factory()->create();
    $c3 = Contact::factory()->create();

    LifecycleStageTransition::create([
        'record_type' => $c1->getMorphClass(),
        'record_id' => $c1->id,
        'from_stage' => LifecycleStage::Lead,
        'to_stage' => LifecycleStage::MarketingQualifiedLead,
        'duration_seconds' => 86400 * 2, // 2 days
        'source' => 'manual',
        'transitioned_at' => now()->subDays(8),
    ]);

    LifecycleStageTransition::create([
        'record_type' => $c2->getMorphClass(),
        'record_id' => $c2->id,
        'from_stage' => LifecycleStage::Lead,
        'to_stage' => LifecycleStage::MarketingQualifiedLead,
        'duration_seconds' => 86400 * 4, // 4 days
        'source' => 'manual',
        'transitioned_at' => now()->subDays(6),
    ]);

    LifecycleStageTransition::create([
        'record_type' => $c3->getMorphClass(),
        'record_id' => $c3->id,
        'from_stage' => LifecycleStage::MarketingQualifiedLead,
        'to_stage' => LifecycleStage::SalesQualifiedLead,
        'duration_seconds' => 86400 * 6, // 6 days
        'source' => 'manual',
        'transitioned_at' => now()->subDays(2),
    ]);

    $action = app(CalculateFunnelVelocityAction::class);

    // Global contact velocity
    $stats = $action->execute(Contact::class);
    expect($stats['total_transitions'])->toBe(3)
        ->and($stats['average_duration_days'])->toBe(4.0)
        ->and($stats['median_duration_days'])->toBe(4.0)
        ->and($stats['min_duration_days'])->toBe(2.0)
        ->and($stats['max_duration_days'])->toBe(6.0)
        ->and($stats['transitions_by_stage'])->toHaveCount(2);

    // Filtered by specific transition: Lead -> MQL
    $mqlStats = $action->execute(
        Contact::class,
        fromStage: LifecycleStage::Lead,
        toStage: LifecycleStage::MarketingQualifiedLead
    );

    expect($mqlStats['total_transitions'])->toBe(2)
        ->and($mqlStats['average_duration_days'])->toBe(3.0)
        ->and($mqlStats['min_duration_days'])->toBe(2.0)
        ->and($mqlStats['max_duration_days'])->toBe(4.0);
});

it('records transitions and stamps timestamps when lifecycle_stage is saved directly', function (): void {
    Event::fake([LifecycleStageChanged::class]);

    $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead, 'became_lead_at' => null]);

    expect($contact->fresh()->became_lead_at)->not->toBeNull();

    // As the panel's edit form or an import does.
    $contact->update(['lifecycle_stage' => LifecycleStage::Customer]);

    $transition = $contact->latestLifecycleTransition();

    expect($contact->fresh()->became_customer_at)->not->toBeNull()
        ->and($contact->lifecycleTransitions()->count())->toBe(1)
        ->and($transition->from_stage)->toBe(LifecycleStage::Lead)
        ->and($transition->to_stage)->toBe(LifecycleStage::Customer)
        ->and($transition->source)->toBe('manual');

    Event::assertDispatchedTimes(LifecycleStageChanged::class, 1);

    // Saving other changes doesn't record a transition.
    $contact->update(['first_name' => 'Renamed']);
    expect($contact->lifecycleTransitions()->count())->toBe(1);
});

it('records one transition when the action changes the stage', function (): void {
    Event::fake([LifecycleStageChanged::class]);
    $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);

    $transition = app(TransitionLifecycleStageAction::class)->execute($contact, LifecycleStage::MarketingQualifiedLead, source: 'form', userId: 7);

    expect($contact->lifecycleTransitions()->count())->toBe(1)
        ->and($transition->source)->toBe('form')
        ->and($transition->user_id)->toBe(7);

    Event::assertDispatchedTimes(LifecycleStageChanged::class, 1);
});

it('does nothing when transitioning to the current stage', function (): void {
    Event::fake([LifecycleStageChanged::class]);
    $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer]);

    expect(app(TransitionLifecycleStageAction::class)->execute($contact, LifecycleStage::Customer))->toBeNull()
        ->and($contact->lifecycleTransitions()->count())->toBe(0);

    Event::assertNotDispatched(LifecycleStageChanged::class);
});

it('validates direct saves in strict mode', function (): void {
    app(LifecycleStateMachine::class)->setStrict(true);
    $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Subscriber]);

    expect(fn () => $contact->update(['lifecycle_stage' => LifecycleStage::Customer]))
        ->toThrow(InvalidLifecycleStageTransitionException::class)
        ->and($contact->fresh()->lifecycle_stage)->toBe(LifecycleStage::Subscriber)
        ->and($contact->lifecycleTransitions()->count())->toBe(0);
});
