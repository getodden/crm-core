<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;

class LifecycleStateMachine
{
    /**
     * Standard allowable forward and recycling stage transitions.
     *
     * @var array<string, list<LifecycleStage>>
     */
    protected array $transitions = [];

    /**
     * Registered validation guards for stage transitions.
     *
     * @var array<string, callable(Model, LifecycleStage|null, LifecycleStage, string): (bool|void)>
     */
    protected array $guards = [];

    /**
     * Whether strict transition validation is globally enforced.
     */
    protected ?bool $strict = null;

    public function __construct()
    {
        $this->bootDefaultTransitions();
    }

    /**
     * Set up default lifecycle stage transitions.
     */
    protected function bootDefaultTransitions(): void
    {
        $this->transitions = [
            LifecycleStage::Subscriber->value => [
                LifecycleStage::Lead,
                LifecycleStage::MarketingQualifiedLead,
                LifecycleStage::Other,
            ],
            LifecycleStage::Lead->value => [
                LifecycleStage::MarketingQualifiedLead,
                LifecycleStage::SalesQualifiedLead,
                LifecycleStage::Other,
            ],
            LifecycleStage::MarketingQualifiedLead->value => [
                LifecycleStage::SalesQualifiedLead,
                LifecycleStage::Opportunity,
                LifecycleStage::Lead, // Recycling
                LifecycleStage::Other,
            ],
            LifecycleStage::SalesQualifiedLead->value => [
                LifecycleStage::Opportunity,
                LifecycleStage::MarketingQualifiedLead, // Recycling
                LifecycleStage::Lead, // Disqualification
                LifecycleStage::Other,
            ],
            LifecycleStage::Opportunity->value => [
                LifecycleStage::Customer,
                LifecycleStage::SalesQualifiedLead,
                LifecycleStage::Lead, // Recycling
                LifecycleStage::Other,
            ],
            LifecycleStage::Customer->value => [
                LifecycleStage::Evangelist,
                LifecycleStage::Other,
            ],
            LifecycleStage::Evangelist->value => [
                LifecycleStage::Customer,
                LifecycleStage::Other,
            ],
            LifecycleStage::Other->value => [
                LifecycleStage::Subscriber,
                LifecycleStage::Lead,
                LifecycleStage::MarketingQualifiedLead,
                LifecycleStage::SalesQualifiedLead,
                LifecycleStage::Opportunity,
                LifecycleStage::Customer,
                LifecycleStage::Evangelist,
            ],
        ];
    }

    /**
     * Determine if a transition from one stage to another is allowed.
     */
    public function canTransition(LifecycleStage $from, LifecycleStage $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $allowed = $this->transitions[$from->value] ?? [];

        return in_array($to, $allowed, true);
    }

    /**
     * Get allowed destination stages for a given current stage.
     *
     * @return list<LifecycleStage>
     */
    public function allowedTransitions(LifecycleStage $from): array
    {
        return $this->transitions[$from->value] ?? [];
    }

    /**
     * Dynamically allow an additional transition between stages.
     */
    public function allowTransition(LifecycleStage $from, LifecycleStage $to): self
    {
        $existing = $this->transitions[$from->value] ?? [];
        if (! in_array($to, $existing, true)) {
            $existing[] = $to;
            $this->transitions[$from->value] = $existing;
        }

        return $this;
    }

    /**
     * Register a custom validation guard for stage transitions.
     *
     * @param  callable(Model, LifecycleStage|null, LifecycleStage, string): (bool|void)  $guard
     */
    public function registerGuard(string $name, callable $guard): self
    {
        $this->guards[$name] = $guard;

        return $this;
    }

    /**
     * Set strict enforcement mode.
     */
    public function setStrict(bool $strict): self
    {
        $this->strict = $strict;

        return $this;
    }

    /**
     * Determine if strict transitions are enabled.
     */
    public function isStrict(): bool
    {
        return $this->strict ?? (bool) config('odden-core.lifecycle.strict_transitions', false);
    }

    /**
     * Validate a transition for a record. Throws exception if invalid and not forced.
     *
     * @throws InvalidLifecycleStageTransitionException
     */
    public function validateTransition(
        Model $record,
        LifecycleStage $toStage,
        string $source = 'manual',
        bool $force = false
    ): void {
        if ($force) {
            return;
        }

        $this->validate($record, TransitionLifecycleStageAction::stageOf($record->getAttribute('lifecycle_stage')), $toStage, $source);
    }

    /**
     * Validate a change from one stage to another for a record. Throws exception if invalid.
     *
     * @throws InvalidLifecycleStageTransitionException
     */
    public function validate(Model $record, ?LifecycleStage $fromStage, LifecycleStage $toStage, string $source = 'manual'): void
    {
        // Initial stage assignment on new records is always allowed
        if ($fromStage === null || $fromStage === $toStage) {
            return;
        }

        // Strict mode check against allowable transition graph
        if ($this->isStrict() && ! $this->canTransition($fromStage, $toStage)) {
            throw new InvalidLifecycleStageTransitionException(
                "Lifecycle transition from [{$fromStage->label()}] to [{$toStage->label()}] is not allowed in strict mode."
            );
        }

        // Data hygiene guard: Prevent unintended customer regression without designated source
        if (in_array($fromStage, [LifecycleStage::Customer, LifecycleStage::Evangelist], true) && in_array($toStage, [
            LifecycleStage::Subscriber,
            LifecycleStage::Lead,
            LifecycleStage::MarketingQualifiedLead,
            LifecycleStage::SalesQualifiedLead,
            LifecycleStage::Opportunity,
        ], true)) {
            $allowedSources = ['churn', 'recycle', 'downgrade', 'disqualified', 'refund'];
            if (! in_array(strtolower($source), $allowedSources, true)) {
                throw new InvalidLifecycleStageTransitionException(
                    "{$fromStage->label()} records cannot be reverted to [{$toStage->label()}] with source [{$source}]. Use a designated churn or recycling source or force the transition."
                );
            }
        }

        // Execute custom registered guards
        foreach ($this->guards as $name => $guard) {
            $result = $guard($record, $fromStage, $toStage, $source);
            if ($result === false) {
                throw new InvalidLifecycleStageTransitionException(
                    "Lifecycle stage transition guard [{$name}] failed for record [{$record->getKey()}]."
                );
            }
        }
    }
}
