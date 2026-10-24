<?php

declare(strict_types=1);

use Odden\Core\Models\ActionLogEntry;
use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Registry\ActionGate;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actor;
use Odden\Core\Registry\CrmAction;
use Odden\Core\Tests\Fixtures\User;

beforeEach(function (): void {
    config(['odden-core.email_validation.check_dns' => false]);
});

it('runs a valid action and logs it', function (): void {
    $result = app(ActionExecutor::class)->run('contacts.create', Actor::user(7), ['email' => 'Ann@Example.com', 'first_name' => 'Ann'], via: 'ui');

    expect($result->ok())->toBeTrue()
        ->and(Contact::query()->whereEmail('ann@example.com')->exists())->toBeTrue();

    $log = ActionLogEntry::query()->sole();
    expect($log->action)->toBe('contacts.create')
        ->and($log->actor_kind)->toBe('user')
        ->and($log->actor_user_id)->toBe('7')
        ->and($log->via)->toBe('ui')
        ->and($log->status)->toBe('completed')
        ->and($log->subject_type)->toBe((new Contact)->getMorphClass());
});

it('rejects invalid input without running, and logs the failure', function (): void {
    $result = app(ActionExecutor::class)->run('contacts.create', Actor::user(1), ['email' => 'not-an-email']);

    expect($result->status)->toBe(ActionResult::FAILED)
        ->and($result->errors)->toHaveKey('email')
        ->and(Contact::query()->count())->toBe(0)
        ->and(ActionLogEntry::query()->sole()->status)->toBe('failed');
});

it('drops input keys the rules do not name', function (): void {
    app(ActionExecutor::class)->run('contacts.create', Actor::user(1), ['email' => 'a@example.com', 'owner_id' => 99, 'team_id' => 5]);

    $contact = Contact::query()->sole();
    expect($contact->owner_id)->toBeNull()->and($contact->team_id)->toBeNull();
});

it('fails an unknown action name', function (): void {
    expect(app(ActionExecutor::class)->run('contacts.explode', Actor::system(), [])->status)->toBe(ActionResult::FAILED);
});

it('stops an actor the gate refuses and does not run the action', function (): void {
    app()->instance(ActionGate::class, new class implements ActionGate
    {
        public function allows(Actor $actor, CrmAction $action, array $input): bool
        {
            return false;
        }
    });

    $result = app(ActionExecutor::class)->run('contacts.create', Actor::user(1), ['email' => 'a@example.com']);

    expect($result->message)->toBe('You are not allowed to do that.')
        ->and(Contact::query()->count())->toBe(0);
});

it('does not keep what people wrote in the log', function (): void {
    $contact = Contact::factory()->create();

    app(ActionExecutor::class)->run('notes.add', Actor::user(1), ['contact_id' => $contact->id, 'body' => 'secret: she is moving to Lisbon']);

    $log = ActionLogEntry::query()->sole();
    expect($log->input['body'])->toBe('[redacted]')
        ->and($log->input['contact_id'])->toBe($contact->id)
        ->and(json_encode($log->getAttributes()))->not->toContain('Lisbon');
});

it('returns a refusal worth showing, and a generic message for anything else', function (): void {
    $executor = app(ActionExecutor::class);

    $missing = $executor->run('notes.add', Actor::user(1), ['contact_id' => 999, 'body' => 'x']);
    expect($missing->message)->toBe('There is no contact with that id.');
});

it('does not repeat a completed run that has the same idempotency key', function (): void {
    $executor = app(ActionExecutor::class);
    $contact = Contact::factory()->create();
    $input = ['contact_id' => $contact->id, 'title' => 'Call back'];

    $user = User::factory()->create();
    $first = $executor->run('tasks.create', Actor::user($user->id), $input, idempotencyKey: 'k1');
    $second = $executor->run('tasks.create', Actor::user($user->id), $input, idempotencyKey: 'k1');

    expect($first->ok())->toBeTrue()->and($second->ok())->toBeTrue()
        ->and($second->message)->toBe('Already done.')
        ->and($contact->activities()->count())->toBe(1);
});

it('lets an AI actor run read and write actions at once', function (): void {
    $result = app(ActionExecutor::class)->run('contacts.create', Actor::ai(1), ['email' => 'ai@example.com'], via: 'assistant');

    expect($result->ok())->toBeTrue()->and(Contact::query()->count())->toBe(1);
});
