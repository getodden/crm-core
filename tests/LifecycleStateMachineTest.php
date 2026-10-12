<?php

declare(strict_types=1);

use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;
use Odden\Core\Models\Contact;
use Odden\Core\Support\LifecycleStateMachine;

it('determines standard allowed forward transitions', function () {
    $sm = app(LifecycleStateMachine::class);

    expect($sm->canTransition(LifecycleStage::Lead, LifecycleStage::MarketingQualifiedLead))->toBeTrue()
        ->and($sm->canTransition(LifecycleStage::Lead, LifecycleStage::SalesQualifiedLead))->toBeTrue()
        ->and($sm->canTransition(LifecycleStage::Opportunity, LifecycleStage::Customer))->toBeTrue()
        ->and($sm->canTransition(LifecycleStage::Customer, LifecycleStage::Evangelist))->toBeTrue();
});

it('prevents customer regression to lead without designated churn/recycle source', function () {
    $contact = Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Customer,
    ]);

    $action = app(TransitionLifecycleStageAction::class);

    // Regular manual downgrade without churn/recycle source must fail
    expect(fn () => $action->execute($contact, LifecycleStage::Lead, source: 'manual'))
        ->toThrow(InvalidLifecycleStageTransitionException::class);

    // Regression with valid churn source succeeds
    $transition = $action->execute($contact, LifecycleStage::Lead, source: 'churn');
    expect($transition->from_stage)->toBe(LifecycleStage::Customer)
        ->and($transition->to_stage)->toBe(LifecycleStage::Lead)
        ->and($transition->source)->toBe('churn');
});

it('allows overriding progression when force is true', function () {
    $contact = Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Customer,
    ]);

    $action = app(TransitionLifecycleStageAction::class);

    $transition = $action->execute($contact, LifecycleStage::Subscriber, source: 'manual', force: true);

    expect($transition->to_stage)->toBe(LifecycleStage::Subscriber);
    $contact->refresh();
    expect($contact->lifecycle_stage)->toBe(LifecycleStage::Subscriber);
});

it('enforces strict mode progression preventing invalid stage jumps', function () {
    $sm = app(LifecycleStateMachine::class);
    $sm->setStrict(true);

    $contact = Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Subscriber,
    ]);

    $action = app(TransitionLifecycleStageAction::class);

    // Subscriber -> Customer is an invalid jump in strict mode
    expect(fn () => $action->execute($contact, LifecycleStage::Customer))
        ->toThrow(InvalidLifecycleStageTransitionException::class);

    // Allowed transition: Subscriber -> Lead
    $transition = $action->execute($contact, LifecycleStage::Lead);
    expect($transition->to_stage)->toBe(LifecycleStage::Lead);
});

it('executes custom registered guards on stage transitions', function () {
    $sm = app(LifecycleStateMachine::class);

    // Register a custom guard: Opportunity requires a deal or custom flag
    $sm->registerGuard('has_budget', function ($record, $from, $to, $source) {
        if ($to === LifecycleStage::Opportunity && empty($record->phone)) {
            return false;
        }

        return true;
    });

    $contactWithoutPhone = Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::SalesQualifiedLead,
        'phone' => null,
    ]);

    $action = app(TransitionLifecycleStageAction::class);

    expect(fn () => $action->execute($contactWithoutPhone, LifecycleStage::Opportunity))
        ->toThrow(InvalidLifecycleStageTransitionException::class);

    $contactWithPhone = Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::SalesQualifiedLead,
        'phone' => '+1-555-0199',
    ]);

    $transition = $action->execute($contactWithPhone, LifecycleStage::Opportunity);
    expect($transition->to_stage)->toBe(LifecycleStage::Opportunity);
});

it('prevents evangelist regression to lead without designated churn/recycle source', function () {
    $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Evangelist]);
    $action = app(TransitionLifecycleStageAction::class);

    expect(fn () => $action->execute($contact, LifecycleStage::Lead, source: 'manual'))
        ->toThrow(InvalidLifecycleStageTransitionException::class)
        ->and($action->execute($contact, LifecycleStage::Lead, source: 'churn')->from_stage)->toBe(LifecycleStage::Evangelist);
});
