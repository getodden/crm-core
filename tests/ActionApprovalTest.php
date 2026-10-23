<?php

declare(strict_types=1);

use Odden\Core\Models\ActionLogEntry;
use Odden\Core\Models\ProposedAction;
use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Registry\ActionRegistry;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actions\BaseAction;
use Odden\Core\Registry\Actor;
use Odden\Core\Registry\Risk;

/**
 * An outbound action that counts how many times it really ran.
 */
class FakeSendAction extends BaseAction
{
    public static int $sent = 0;

    public static function name(): string
    {
        return 'test.send';
    }

    public static function description(): string
    {
        return 'Pretend to send a message.';
    }

    public static function rules(): array
    {
        return ['to' => ['required', 'string'], 'text' => ['required', 'string']];
    }

    public function risk(): Risk
    {
        return Risk::Outbound;
    }

    public function sensitive(): array
    {
        return ['text'];
    }

    public function handle(Actor $actor, array $input): ActionResult
    {
        self::$sent++;

        return ActionResult::completed(['sent_by' => $actor->kind.':'.$actor->userId]);
    }
}

beforeEach(function (): void {
    FakeSendAction::$sent = 0;
    app(ActionRegistry::class)->register(FakeSendAction::class);
});

it('holds an outbound action an AI asked for and runs nothing', function (): void {
    $result = app(ActionExecutor::class)->run('test.send', Actor::ai(3), ['to' => 'a', 'text' => 'hello'], via: 'assistant');

    expect($result->status)->toBe(ActionResult::PROPOSED)
        ->and(FakeSendAction::$sent)->toBe(0)
        ->and(ProposedAction::query()->sole()->status)->toBe('pending')
        ->and(ActionLogEntry::query()->sole()->status)->toBe('proposed');
});

it('runs the same outbound action at once for a person', function (): void {
    $result = app(ActionExecutor::class)->run('test.send', Actor::user(3), ['to' => 'a', 'text' => 'hello']);

    expect($result->ok())->toBeTrue()->and(FakeSendAction::$sent)->toBe(1);
});

it('keeps the proposed input encrypted at rest', function (): void {
    app(ActionExecutor::class)->run('test.send', Actor::ai(3), ['to' => 'a', 'text' => 'the secret words']);

    $raw = DB::table('odden_proposed_actions')->value('input');
    expect($raw)->not->toContain('secret')->and(ProposedAction::query()->sole()->input['text'])->toBe('the secret words');
});

it('does not queue the same request twice', function (): void {
    $executor = app(ActionExecutor::class);
    $executor->run('test.send', Actor::ai(3), ['to' => 'a', 'text' => 'hello']);
    $executor->run('test.send', Actor::ai(3), ['to' => 'a', 'text' => 'hello']);

    expect(ProposedAction::query()->count())->toBe(1);
});

it('runs an approved proposal once, as the person who approved it', function (): void {
    $executor = app(ActionExecutor::class);
    $executor->run('test.send', Actor::ai(3), ['to' => 'a', 'text' => 'hello']);
    $proposal = ProposedAction::query()->sole();

    $result = $executor->approve($proposal, Actor::user(9));

    expect($result->ok())->toBeTrue()
        ->and($result->data['sent_by'])->toBe('user:9')
        ->and(FakeSendAction::$sent)->toBe(1)
        ->and($proposal->status)->toBe('approved')
        ->and($proposal->decided_by_user_id)->toBe('9');

    // A second approval, or a replay, does nothing.
    expect($executor->approve($proposal, Actor::user(9))->status)->toBe(ActionResult::FAILED)
        ->and(FakeSendAction::$sent)->toBe(1);
});

it('does not run a rejected proposal', function (): void {
    $executor = app(ActionExecutor::class);
    $executor->run('test.send', Actor::ai(3), ['to' => 'a', 'text' => 'hello']);
    $proposal = ProposedAction::query()->sole();

    expect($executor->reject($proposal, Actor::user(9)))->toBeTrue()
        ->and($executor->approve($proposal, Actor::user(9))->status)->toBe(ActionResult::FAILED)
        ->and(FakeSendAction::$sent)->toBe(0);
});

it('does not run a proposal past its time', function (): void {
    $executor = app(ActionExecutor::class);
    $executor->run('test.send', Actor::ai(3), ['to' => 'a', 'text' => 'hello']);
    $this->travel(ActionExecutor::PROPOSAL_HOURS + 1)->hours();

    expect($executor->approve(ProposedAction::query()->sole(), Actor::user(9))->status)->toBe(ActionResult::FAILED)
        ->and(FakeSendAction::$sent)->toBe(0)
        ->and($executor->expireStale())->toBe(1);
});

it('lets only a person decide', function (): void {
    $executor = app(ActionExecutor::class);
    $executor->run('test.send', Actor::ai(3), ['to' => 'a', 'text' => 'hello']);
    $proposal = ProposedAction::query()->sole();

    expect(fn () => $executor->approve($proposal, Actor::ai(3)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $executor->reject($proposal, Actor::system()))->toThrow(InvalidArgumentException::class)
        ->and(FakeSendAction::$sent)->toBe(0);
});
