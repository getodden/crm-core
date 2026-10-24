<?php

declare(strict_types=1);

namespace Odden\Core\Registry;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Odden\Core\Areas\AreaRedactor;
use Odden\Core\Areas\Areas;
use Odden\Core\Models\ActionLogEntry;
use Odden\Core\Models\ProposedAction;
use Throwable;

/**
 * The one way to run a registry action. Every caller (panel, assistant, worker, MCP server) goes through run(), so the steps are
 * always the same: find the action, validate the input, ask the gate and the action whether the actor may, hold a risky action
 * an AI asked for until a person approves it, run it, and write the log.
 */
final class ActionExecutor
{
    /** How long a proposal can be approved. */
    public const int PROPOSAL_HOURS = 24;

    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly ActionGate $gate,
        private readonly Areas $areas,
        private readonly AreaRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  string  $via  Where the request came from, for the log: `ui`, `assistant`, `worker`, `mcp`.
     * @param  string|null  $idempotencyKey  A repeat of a completed run with the same key returns that run instead of doing it again.
     */
    public function run(string $name, Actor $actor, array $input, string $via = 'ui', ?string $idempotencyKey = null): ActionResult
    {
        if (! $this->registry->has($name)) {
            return ActionResult::failed("There is no action named {$name}.");
        }

        $action = $this->registry->get($name);

        if ($idempotencyKey !== null && ($earlier = $this->completedRun($name, $idempotencyKey)) !== null) {
            return ActionResult::completed([], 'Already done.', $earlier->subject_type, $earlier->subject_id);
        }

        // The panel's middleware trims what people type; a model or a script that calls the executor directly gets the same.
        $input = array_map(static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $input);

        $validator = Validator::make($input, $action::rules());

        if ($validator->fails()) {
            return $this->finish($action, $actor, $via, $input, $idempotencyKey, ActionResult::failed(
                'The input is not valid.',
                $validator->errors()->toArray(),
            ));
        }

        // Only the keys the rules name reach the action; anything else a caller (or model) sent is dropped.
        $clean = $validator->safe()->only(array_keys($action::rules()));

        if (($denied = $this->denial($action, $actor, $clean)) !== null) {
            return $this->finish($action, $actor, $via, $clean, $idempotencyKey, ActionResult::failed($denied));
        }

        if ($actor->isAi() && $action->risk()->needsApprovalFromAi()) {
            return $this->propose($action, $actor, $via, $clean, $idempotencyKey);
        }

        return $this->execute($action, $actor, $via, $clean, $idempotencyKey);
    }

    /**
     * Run an approved proposal as the person who approved it. It runs at most once: the status changes first, in a single
     * update that only one caller can win.
     */
    public function approve(ProposedAction $proposal, Actor $approver): ActionResult
    {
        if ($approver->kind !== Actor::USER) {
            throw new InvalidArgumentException('Only a person can approve a proposed action.');
        }

        if (! $this->claim($proposal, ProposedAction::APPROVED, $approver)) {
            return ActionResult::failed('This request is no longer waiting for approval.');
        }

        $action = $this->registry->get($proposal->action);
        $input = $proposal->input;

        // The input was validated when it was proposed, but the world may have moved on, so check the person may still do it.
        if (($denied = $this->denial($action, $approver, $input)) !== null) {
            $proposal->update(['status' => ProposedAction::FAILED, 'error' => $denied]);

            return $this->finish($action, $approver, 'approval', $input, null, ActionResult::failed($denied), $proposal->getKey());
        }

        $result = $this->execute($action, $approver, 'approval', $input, $proposal->idempotency_key, $proposal->getKey());

        if (! $result->ok()) {
            $proposal->update(['status' => ProposedAction::FAILED, 'error' => $result->message]);
        }

        return $result;
    }

    public function reject(ProposedAction $proposal, Actor $decider): bool
    {
        if ($decider->kind !== Actor::USER) {
            throw new InvalidArgumentException('Only a person can reject a proposed action.');
        }

        return $this->claim($proposal, ProposedAction::REJECTED, $decider);
    }

    /**
     * Mark every proposal past its time as expired.
     */
    public function expireStale(): int
    {
        return ProposedAction::query()
            ->where('status', ProposedAction::PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => ProposedAction::EXPIRED, 'updated_at' => now()]);
    }

    /**
     * Why this actor may not run the action with this input, or null when they may. An action's authorize() can say why by
     * throwing ActionFailedException ("this contact has not agreed to receive SMS"), so a request that could never work is
     * refused before a person is asked to approve it.
     *
     * @param  array<string, mixed>  $input
     */
    private function denial(CrmAction $action, Actor $actor, array $input): ?string
    {
        try {
            if (! $this->gate->allows($actor, $action, $input) || ! $action->authorize($actor, $input)) {
                return 'You are not allowed to do that.';
            }

            return $this->areaDenial($action, $actor, $input);
        } catch (ActionFailedException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Why the input names a field in a data area the actor does not hold, or null. The system holds every area.
     *
     * @param  array<string, mixed>  $input
     */
    private function areaDenial(CrmAction $action, Actor $actor, array $input): ?string
    {
        if ($actor->userId === null) {
            return null;
        }

        foreach ($action->areas() as $key => $area) {
            if (array_key_exists($key, $input) && ! $this->areas->holds($area, $actor->userId)) {
                return "You do not have access to the {$area->label()} area, so {$key} cannot be used.";
            }
        }

        return null;
    }

    /**
     * What the actor gets back leaves out the areas they do not hold, and for an AI the Sensitive area as well.
     */
    private function withheld(CrmAction $action, Actor $actor, ActionResult $result): ActionResult
    {
        if ($result->data === [] || $actor->kind === Actor::SYSTEM) {
            return $result;
        }

        return new ActionResult(
            $result->status,
            $this->redactor->redact($result->data, $action->areas(), $actor->userId, $actor->isAi()),
            $result->message,
            $result->errors,
            $result->proposalId,
            $result->subjectType,
            $result->subjectId,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function execute(CrmAction $action, Actor $actor, string $via, array $input, ?string $idempotencyKey, int|string|null $proposalId = null): ActionResult
    {
        try {
            $result = DB::transaction(fn (): ActionResult => $action->handle($actor, $input));
        } catch (ActionFailedException $e) {
            $result = ActionResult::failed($e->getMessage());
        } catch (ValidationException $e) {
            $result = ActionResult::failed('The input is not valid.', $e->errors());
        } catch (Throwable $e) {
            report($e);
            $result = ActionResult::failed('Something went wrong, and nothing was changed.');
        }

        return $this->finish($action, $actor, $via, $input, $idempotencyKey, $this->withheld($action, $actor, $result), $proposalId);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function propose(CrmAction $action, Actor $actor, string $via, array $input, ?string $idempotencyKey): ActionResult
    {
        // Asking again for the same thing returns the request that is already waiting.
        $existing = ProposedAction::query()
            ->where('action', $action::name())
            ->where('status', ProposedAction::PENDING)
            ->where('expires_at', '>', now())
            ->where('requested_by_user_id', (string) $actor->userId)
            ->when($idempotencyKey !== null, fn ($q) => $q->where('idempotency_key', $idempotencyKey))
            ->get()
            ->first(fn (ProposedAction $p): bool => $idempotencyKey !== null || $p->input == $input);

        $proposal = $existing ?? ProposedAction::create([
            'action' => $action::name(),
            'input' => $input,
            'requested_by_kind' => $actor->kind,
            'requested_by_user_id' => $actor->userId === null ? null : (string) $actor->userId,
            'via' => $via,
            'idempotency_key' => $idempotencyKey,
            'expires_at' => now()->addHours(self::PROPOSAL_HOURS),
        ]);

        $result = ActionResult::proposed($proposal->getKey(), 'This needs a person to approve it first. It has been queued, and nothing has been done yet.');

        return $this->finish($action, $actor, $via, $input, $idempotencyKey, $result, $proposal->getKey());
    }

    private function claim(ProposedAction $proposal, string $status, Actor $decider): bool
    {
        $claimed = ProposedAction::query()
            ->whereKey($proposal->getKey())
            ->where('status', ProposedAction::PENDING)
            ->where('expires_at', '>', now())
            ->update([
                'status' => $status,
                'decided_by_user_id' => (string) $decider->userId,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);

        $proposal->refresh();

        return $claimed === 1;
    }

    private function completedRun(string $name, string $idempotencyKey): ?ActionLogEntry
    {
        return ActionLogEntry::query()
            ->where('action', $name)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', ActionResult::COMPLETED)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function finish(CrmAction $action, Actor $actor, string $via, array $input, ?string $idempotencyKey, ActionResult $result, int|string|null $proposalId = null): ActionResult
    {
        ActionLogEntry::create([
            'action' => $action::name(),
            'actor_kind' => $actor->kind,
            'actor_user_id' => $actor->userId === null ? null : (string) $actor->userId,
            'via' => $via,
            'status' => $result->status,
            'input' => $this->redact($input, $action->sensitive()),
            'error' => $result->status === ActionResult::FAILED ? $result->message : null,
            'subject_type' => $result->subjectType,
            'subject_id' => $result->subjectId === null ? null : (string) $result->subjectId,
            'idempotency_key' => $idempotencyKey,
            'proposal_id' => $proposalId,
        ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $sensitive
     * @return array<string, mixed>
     */
    private function redact(array $input, array $sensitive): array
    {
        foreach ($sensitive as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = '[redacted]';
            }
        }

        return $input;
    }
}
