<?php

declare(strict_types=1);

namespace Odden\Core\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Models\LifecycleStageTransition;
use Odden\Core\Support\LifecycleStateMachine;

trait HasLifecycleStageTransitions
{
    /**
     * Source and user of the change being saved by TransitionLifecycleStageAction, which has
     * already validated it.
     *
     * @var array{source: string, user_id: int|null}|null
     */
    private ?array $lifecycleTransitionContext = null;

    private ?LifecycleStageTransition $recordedLifecycleTransition = null;

    /**
     * However lifecycle_stage changes (the action, a form, an import), the first time the record
     * reaches a stage is stamped in became_<stage>_at, and each change is recorded as a
     * LifecycleStageTransition with LifecycleStageChanged dispatched. In strict mode a plain save
     * is validated like the action (with source `manual`).
     */
    protected static function bootHasLifecycleStageTransitions(): void
    {
        static::saving(function (self $record): void {
            $toStage = TransitionLifecycleStageAction::stageOf($record->getAttribute('lifecycle_stage'));

            if (! $record->isDirty('lifecycle_stage') || $toStage === null) {
                return;
            }

            $fromStage = TransitionLifecycleStageAction::stageOf($record->getOriginal('lifecycle_stage'));

            if ($record->exists && $fromStage === $toStage) {
                return;
            }

            $stateMachine = app(LifecycleStateMachine::class);

            if ($record->exists && $record->lifecycleTransitionContext === null && $stateMachine->isStrict()) {
                $stateMachine->validate($record, $fromStage, $toStage, 'manual');
            }

            TransitionLifecycleStageAction::stampBecameAt($record, $toStage);
        });

        static::updated(function (self $record): void {
            $toStage = TransitionLifecycleStageAction::stageOf($record->getAttribute('lifecycle_stage'));
            $fromStage = TransitionLifecycleStageAction::stageOf($record->getOriginal('lifecycle_stage'));

            if (! $record->wasChanged('lifecycle_stage') || $toStage === null || $fromStage === $toStage) {
                return;
            }

            $context = $record->lifecycleTransitionContext;
            $userId = auth()->id();

            $record->recordedLifecycleTransition = app(TransitionLifecycleStageAction::class)->record(
                $record,
                $fromStage,
                $toStage,
                $context['source'] ?? 'manual',
                $context === null ? (is_int($userId) ? $userId : null) : $context['user_id'],
            );
        });
    }

    /**
     * Save a lifecycle_stage change made by TransitionLifecycleStageAction and return its transition.
     *
     * @internal
     */
    public function saveLifecycleTransition(string $source, ?int $userId): ?LifecycleStageTransition
    {
        $this->lifecycleTransitionContext = ['source' => $source, 'user_id' => $userId];
        $this->recordedLifecycleTransition = null;

        try {
            $this->save();
        } finally {
            $this->lifecycleTransitionContext = null;
        }

        return $this->recordedLifecycleTransition;
    }

    /**
     * Get all lifecycle stage transitions for this record.
     *
     * @return MorphMany<LifecycleStageTransition, $this>
     */
    public function lifecycleTransitions(): MorphMany
    {
        return $this->morphMany(
            LifecycleStageTransition::class,
            'record'
        )->orderBy('transitioned_at', 'desc');
    }

    /**
     * Get the most recent lifecycle stage transition.
     */
    public function latestLifecycleTransition(): ?LifecycleStageTransition
    {
        return $this->lifecycleTransitions()->first();
    }

    /**
     * Get time spent in the current lifecycle stage in seconds.
     */
    public function timeInCurrentStageSeconds(): int
    {
        $latest = $this->latestLifecycleTransition();

        $referenceTime = $latest !== null
            ? $latest->transitioned_at
            : ($this->created_at ?? now());

        return max(0, abs((int) now()->diffInSeconds($referenceTime)));
    }

    /**
     * Get time spent in the current lifecycle stage in days.
     */
    public function timeInCurrentStageDays(): float
    {
        return round($this->timeInCurrentStageSeconds() / 86400, 2);
    }

    /**
     * Get formatted time spent in the current lifecycle stage.
     */
    public function formattedTimeInCurrentStage(): string
    {
        $seconds = $this->timeInCurrentStageSeconds();

        if ($seconds < 60) {
            return '< 1 min';
        }

        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        if ($days > 0) {
            return $days === 1 ? '1 day' : "{$days} days";
        }

        if ($hours > 0) {
            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        return $minutes <= 1 ? '1 min' : "{$minutes} mins";
    }
}
