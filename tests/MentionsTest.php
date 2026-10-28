<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Odden\Core\Actions\RecordMentionsAction;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Events\UserMentioned;
use Odden\Core\Models\Contact;
use Odden\Core\Models\Mention;
use Odden\Core\Support\Mentions;
use Odden\Core\Tests\Fixtures\User;

/**
 * @param  list<int>  $userIds  The users who belong to the tenant.
 */
function inMentionTenant(int $tenant, array $userIds): void
{
    app()->instance(TenantContext::class, new class($tenant, $userIds) implements TenantContext
    {
        /** @param list<int> $userIds */
        public function __construct(private int $tenant, private array $userIds) {}

        public function id(): int
        {
            return $this->tenant;
        }

        public function scopeUsers(Builder $users): Builder
        {
            return $users->whereIn('id', $this->userIds);
        }
    });
}

it('offers every user when there is no tenant, by name', function (): void {
    $zoe = User::factory()->create(['name' => 'Zoe Adams']);
    $amy = User::factory()->create(['name' => 'Amy Brown']);

    expect(Mentions::teammates())->toBe([$amy->id => 'Amy Brown', $zoe->id => 'Zoe Adams'])
        ->and(Mentions::teammates('zoe'))->toBe([$zoe->id => 'Zoe Adams']);
});

it('offers only the active tenant\'s members in the picker', function (): void {
    $jane = User::factory()->create(['name' => 'Jane Doe']);
    $john = User::factory()->create(['name' => 'John Doe']);
    $outsider = User::factory()->create(['name' => 'Jane Outsider']);

    inMentionTenant(1, [$jane->id, $john->id]);
    expect(Mentions::teammates('jane'))->toBe([$jane->id => 'Jane Doe'])
        ->and(Mentions::names([$jane->id, $outsider->id]))->toBe([$jane->id => 'Jane Doe']);

    inMentionTenant(2, [$outsider->id]);
    expect(Mentions::teammates())->toBe([$outsider->id => 'Jane Outsider']);
});

it('stores a mention and fires UserMentioned once for each person newly mentioned', function (): void {
    Event::fake([UserMentioned::class]);
    [$author, $jane, $john] = User::factory()->count(3)->create();
    $contact = Contact::factory()->create();
    $note = $contact->logNote('Ask Jane about the renewal');
    $record = app(RecordMentionsAction::class);

    expect($record->execute($note, $contact, [$jane->id], $author->id, [$jane->id => 'Jane']))->toBe([$jane->id]);
    expect(Mention::query()->where('user_id', $jane->id)->where('mentioned_by', $author->id)->where('mentionable_id', $note->id)->value('label'))->toBe('Jane');

    Event::assertDispatchedTimes(UserMentioned::class, 1);
    Event::assertDispatched(UserMentioned::class, fn (UserMentioned $event): bool => $event->mentionedUserId === $jane->id
        && $event->actorId === $author->id && $event->mentionable->is($note) && $event->subject->is($contact));

    // Saved again with the same person and one more: only the new person is told. A missing label is the person's name.
    expect($record->execute($note, $contact, [$jane->id, $john->id], $author->id, [$jane->id => 'Jane']))->toBe([$john->id]);
    expect(Mention::query()->where('user_id', $john->id)->value('label'))->toBe($john->name);
    Event::assertDispatchedTimes(UserMentioned::class, 2);

    // Taking a person out removes their mention, and tells no one.
    expect($record->execute($note, $contact, [$john->id], $author->id))->toBe([]);
    expect(Mention::query()->where('mentionable_id', $note->id)->pluck('user_id')->all())->toBe([$john->id]);
    Event::assertDispatchedTimes(UserMentioned::class, 2);
});

it('tells a person once when the same mention is saved twice at once', function (): void {
    Event::fake([UserMentioned::class]);
    $jane = User::factory()->create(['name' => 'Jane']);
    $contact = Contact::factory()->create();
    $note = $contact->logNote('Ask @Jane');
    $raced = false;

    // The other save records Jane just after this one has read the mentions it already has.
    DB::listen(function (QueryExecuted $query) use (&$raced, $note, $jane): void {
        if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'odden_mentions')) {
            $raced = true;
            DB::table('odden_mentions')->insert(['mentionable_type' => $note->getMorphClass(), 'mentionable_id' => $note->id, 'user_id' => $jane->id, 'label' => 'Jane']);
        }
    });

    expect(app(RecordMentionsAction::class)->execute($note, $contact, [$jane->id], null))->toBe([])
        ->and($raced)->toBeTrue()
        ->and(Mention::query()->count())->toBe(1);
    Event::assertNotDispatched(UserMentioned::class);
});

it('keeps the name a person was written with after they are renamed', function (): void {
    $jane = User::factory()->create(['name' => 'Jane Doe']);
    $contact = Contact::factory()->create();
    $note = $contact->logNote('Ask @Jane Doe');
    app(RecordMentionsAction::class)->execute($note, $contact, [$jane->id], null);

    $jane->update(['name' => 'Jane Smith']);

    expect(Mentions::mentionedNames($note))->toBe(['Jane Doe'])
        ->and(Mentions::highlight($note->body, Mentions::mentionedNames($note))->toHtml())->toBe('Ask <strong>@Jane Doe</strong>');
});

it('matches % and _ in a search as themselves', function (): void {
    $percent = User::factory()->create(['name' => '100% Sam']);
    $underscore = User::factory()->create(['name' => 'Sam_Lee']);
    User::factory()->create(['name' => 'Sam Lee']);

    expect(Mentions::teammates('%'))->toBe([$percent->id => '100% Sam'])
        ->and(Mentions::teammates('m_l'))->toBe([$underscore->id => 'Sam_Lee']);
});

it('ignores ids that are not teammates', function (): void {
    Event::fake([UserMentioned::class]);
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    inMentionTenant(1, [$member->id]);
    $contact = Contact::factory()->create();
    $note = $contact->logNote('Hello');

    expect(app(RecordMentionsAction::class)->execute($note, $contact, [$outsider->id, 'nobody', 999999], $member->id))->toBe([]);
    expect(Mention::query()->count())->toBe(0);
    Event::assertNotDispatched(UserMentioned::class);
});

it('removes the mentions of a deleted note', function (): void {
    $jane = User::factory()->create();
    $contact = Contact::factory()->create();
    $note = $contact->logNote('Ask Jane');
    app(RecordMentionsAction::class)->execute($note, $contact, [$jane->id], null);

    $note->delete();

    expect(Mention::query()->count())->toBe(0);
});

it('escapes the text and shows only the mentioned names in bold', function (): void {
    $html = Mentions::highlight('<script>alert(1)</script> ask @Jo Smith & @Jo, not @Nobody', ['Jo', 'Jo Smith', '<b>Bad</b>'])->toHtml();

    expect($html)->toBe('&lt;script&gt;alert(1)&lt;/script&gt; ask <strong>@Jo Smith</strong> &amp; <strong>@Jo</strong>, not @Nobody');
});

it('escapes a name that carries markup', function (): void {
    $html = Mentions::highlight('Hi @<img src=x onerror=alert(1)>', ['<img src=x onerror=alert(1)>'])->toHtml();

    expect($html)->toBe('Hi <strong>@&lt;img src=x onerror=alert(1)&gt;</strong>');
});
