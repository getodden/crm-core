<?php

declare(strict_types=1);

use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Contact;
use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actor;
use Odden\Core\Tests\Fixtures\User;

beforeEach(function (): void {
    config(['odden-core.email_validation.check_dns' => false]);
    // Servers that enforce foreign keys (PostgreSQL, MySQL) need the person an activity is credited to to exist.
    $this->user = User::factory()->create();
});

function runAction(string $name, array $input, ?Actor $actor = null): ActionResult
{
    return app(ActionExecutor::class)->run($name, $actor ?? Actor::user(test()->user->id), $input);
}

it('creates a contact the same way CreateContactAction does', function (): void {
    $result = runAction('contacts.create', ['email' => '  JOHN.DOE@EXAMPLE.COM ', 'first_name' => 'John', 'lifecycle_stage' => 'customer']);

    $contact = Contact::query()->sole();
    expect($result->data)->toBe(['id' => $contact->id, 'created' => true])
        ->and($contact->email)->toBe('john.doe@example.com')
        ->and($contact->lifecycle_stage->value)->toBe('customer');
});

it('returns the existing contact instead of creating a duplicate', function (): void {
    $existing = Contact::factory()->create(['email' => 'dup@example.com']);

    $result = runAction('contacts.create', ['email' => 'DUP@example.com']);

    expect($result->data)->toBe(['id' => $existing->id, 'created' => false])->and(Contact::query()->count())->toBe(1);
});

it('refuses a lifecycle stage that does not exist', function (): void {
    expect(runAction('contacts.create', ['email' => 'a@example.com', 'lifecycle_stage' => 'emperor'])->errors)->toHaveKey('lifecycle_stage');
});

it('searches by name, email and phone, ignoring case', function (): void {
    Contact::factory()->create(['first_name' => 'Sarah', 'last_name' => 'Connor', 'email' => 'sarah@example.com', 'phone' => '555-0100']);
    Contact::factory()->create(['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com', 'phone' => null]);

    expect(runAction('contacts.search', ['query' => 'conn'])->data['contacts'])->toHaveCount(1)
        ->and(runAction('contacts.search', ['query' => 'SARAH@EXAMPLE'])->data['contacts'])->toHaveCount(1)
        ->and(runAction('contacts.search', ['query' => 'john@'])->data['contacts'])->toHaveCount(1)
        ->and(runAction('contacts.search', ['query' => '555-01'])->data['contacts'])->toHaveCount(1)
        ->and(runAction('contacts.search', ['query' => 'x'])->status)->toBe(ActionResult::FAILED);
});

it('updates only the fields that were sent', function (): void {
    $contact = Contact::factory()->create(['first_name' => 'Ann', 'job_title' => 'CEO', 'phone' => '1']);

    $result = runAction('contacts.update', ['contact_id' => $contact->id, 'job_title' => 'CTO', 'phone' => null]);

    $contact->refresh();
    expect($result->data['changed'])->toBe(['job_title', 'phone'])
        ->and($contact->job_title)->toBe('CTO')->and($contact->phone)->toBeNull()->and($contact->first_name)->toBe('Ann');
});

it('does not let contacts.update change the email or anything else', function (): void {
    $contact = Contact::factory()->create(['email' => 'keep@example.com']);

    $result = runAction('contacts.update', ['contact_id' => $contact->id, 'email' => 'new@example.com', 'lead_score' => 99]);

    expect($result->message)->toBe('Nothing to change: send at least one field.')
        ->and($contact->refresh()->email)->toBe('keep@example.com');
});

it('fails cleanly for a contact that does not exist', function (): void {
    foreach ([['contacts.update', ['contact_id' => 99, 'phone' => '1']], ['notes.add', ['contact_id' => 99, 'body' => 'x']], ['tasks.create', ['contact_id' => 99, 'title' => 'x']]] as [$name, $input]) {
        expect(runAction($name, $input)->message)->toBe('There is no contact with that id.');
    }
});

it('adds a note to the timeline for the person who asked', function (): void {
    $contact = Contact::factory()->create();

    $result = runAction('notes.add', ['contact_id' => $contact->id, 'body' => 'Met at the show.'], Actor::ai($this->user->id));

    $note = $contact->activities()->sole();
    expect($result->ok())->toBeTrue()
        ->and($note->type)->toBe(ActivityType::Note)
        ->and($note->body)->toBe('Met at the show.')
        ->and($note->creator_id)->toBe($this->user->id)
        ->and($note->metadata)->toBe(['added_by' => 'assistant']);
});

it('creates a pending task with a due date', function (): void {
    $contact = Contact::factory()->create();

    runAction('tasks.create', ['contact_id' => $contact->id, 'title' => 'Send proposal', 'due_at' => '2030-01-15 09:00:00']);

    $task = $contact->activities()->sole();
    expect($task->type)->toBe(ActivityType::Task)
        ->and($task->status)->toBe(ActivityStatus::Pending)
        ->and($task->due_at->toDateString())->toBe('2030-01-15')
        ->and($task->completed_at)->toBeNull();
});
