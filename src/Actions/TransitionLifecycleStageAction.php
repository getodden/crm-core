<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Events\LifecycleStageChanged;
use Odden\Core\Models\LifecycleStageTransition;
use Odden\Core\Support\LifecycleStateMachine;

class TransitionLifecycleStageAction
{
    public function __construct(
        protected LifecycleStateMachine $stateMachine
    ) {}

    /**
     * Transition a CRM record's lifecycle stage, validating state machine guards and recording history.
     *
     * Returns null, and changes nothing, when the record is already in that stage.
     */
    public function execute(
        Model $record,
        LifecycleStage $toStage,
        string $source = 'manual',
        ?int $userId = null,
        bool $force = false
    ): ?LifecycleStageTransition {
        // Validate against state machine guards and progression rules
        $this->stateMachine->validateTransition($record, $toStage, $source, $force);

        $fromStage = self::stageOf($record->getAttribute('lifecycle_stage'));

        if ($fromStage === $toStage) {
            return null;
        }

        return DB::transaction(function () use ($record, $fromStage, $toStage, $source, $userId): LifecycleStageTransition {
            $record->setAttribute('lifecycle_stage', $toStage);

            // Records with the trait record the transition from their own model event.
            if (method_exists($record, 'saveLifecycleTransition')) {
                /** @var LifecycleStageTransition $transition */
                $transition = $record->saveLifecycleTransition($source, $userId);

                return $transition;
            }

            self::stampBecameAt($record, $toStage);
            $record->save();

            return $this->record($record, $fromStage, $toStage, $source, $userId);
        });
    }

    /**
     * Write the transition row for a change that has been saved, and dispatch LifecycleStageChanged.
     */
    public function record(
        Model $record,
        ?LifecycleStage $fromStage,
        LifecycleStage $toStage,
        string $source = 'manual',
        ?int $userId = null
    ): LifecycleStageTransition {
        // Determine elapsed duration in the from_stage
        /** @var LifecycleStageTransition|null $lastTransition */
        $lastTransition = LifecycleStageTransition::query()
            ->where('record_type', $record->getMorphClass())
            ->where('record_id', $record->getKey())
            ->orderBy('transitioned_at', 'desc')
            ->first();

        $referenceTime = $lastTransition !== null
            ? $lastTransition->transitioned_at
            : $record->getAttribute('created_at');

        if ($referenceTime !== null && ! ($referenceTime instanceof CarbonInterface)) {
            $referenceTime = Carbon::parse($referenceTime);
        }
        $referenceTime ??= now();

        $durationSeconds = max(0, abs((int) now()->diffInSeconds($referenceTime)));

        /** @var int|null $teamId */
        $teamId = $record->getAttribute('team_id');

        /** @var LifecycleStageTransition $transition */
        $transition = LifecycleStageTransition::create([
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'duration_seconds' => $durationSeconds,
            'source' => $source,
            'user_id' => $userId,
            'team_id' => $teamId,
            'transitioned_at' => now(),
        ]);

        event(new LifecycleStageChanged($record, $transition));

        return $transition;
    }

    /**
     * Set became_<stage>_at to now if it is still null, so it keeps the first time the record
     * reached that stage.
     */
    public static function stampBecameAt(Model $record, LifecycleStage $stage): void
    {
        $becameColumn = "became_{$stage->value}_at";

        if ($record->getAttribute($becameColumn) === null) {
            $record->setAttribute($becameColumn, now());
        }
    }

    /**
     * A lifecycle_stage attribute as an enum, or null when unset or unknown.
     */
    public static function stageOf(mixed $value): ?LifecycleStage
    {
        return match (true) {
            $value instanceof LifecycleStage => $value,
            is_string($value) && $value !== '' => LifecycleStage::tryFrom($value),
            default => null,
        };
    }
}
