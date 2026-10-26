<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Areas\AllowAllAreaAccess;
use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\AreaRedactor;
use Odden\Core\Areas\AreaRevealed;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Areas\DataAreas;
use Odden\Core\Areas\RevealNotAllowed;
use Odden\Core\Encryption\EncryptedColumns;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Models\PropertyHistory;
use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actor;
use Odden\Core\Tests\Fixtures\User;

/**
 * A host that has roles: the given user ids hold only the given areas, and may reveal the areas listed.
 *
 * @param  array<int, list<DataArea>>  $held  User id => areas.
 * @param  list<DataArea>  $mayReveal
 */
function areasHeldBy(array $held, array $mayReveal = []): void
{
    app()->instance(AreaAccess::class, new class($held, $mayReveal) implements AreaAccess
    {
        public function __construct(private array $held, private array $mayReveal) {}

        public function allows(DataArea $area, int|string|null $userId = null): bool
        {
            return in_array($area, $this->held[$userId] ?? [], true);
        }

        public function mayReveal(DataArea $area, int|string|null $userId = null): bool
        {
            return in_array($area, $this->mayReveal, true);
        }
    });
    app()->forgetInstance(Areas::class);
    app()->forgetInstance(AreaRedactor::class);
    app()->forgetInstance(ActionExecutor::class);
}

function signedInAs(int $id): User
{
    $user = User::query()->create(['id' => $id, 'name' => "User {$id}", 'email' => "u{$id}@example.com", 'password' => 'x']);
    test()->actingAs($user);

    return $user;
}

it('puts every encrypted field in an area, and every Odden table', function (): void {
    $areas = app(DataAreas::class);

    foreach (app(EncryptedColumns::class)->fieldNames() as $field) {
        expect($areas->knowsField($field))->toBeTrue("the encrypted field [{$field}] has no data area");
    }

    expect(array_diff(array_keys($areas->allFields()), app(EncryptedColumns::class)->fieldNames()))->toBe([], 'areas for fields that are not encrypted');

    // MySQL lists the tables of every database on the server, so keep to this connection's own.
    $database = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? DB::getDatabaseName() : null;

    foreach (Schema::getTables() as $table) {
        if ($database !== null && $table['schema'] !== $database) {
            continue;
        }

        if (str_starts_with($table['name'], 'odden_')) {
            expect(array_key_exists($table['name'], $areas->allTables()))->toBeTrue("the table [{$table['name']}] has no data area");
        }
    }
});

it('refuses to give a field with no area an area', function (): void {
    expect(fn () => app(DataAreas::class)->areaOfField('contacts.shoe_size'))->toThrow(InvalidArgumentException::class);
});

it('knows the area of an attribute from its encrypted cast', function (): void {
    $contact = new Contact;
    $areas = app(DataAreas::class);

    expect($areas->areaOfAttribute($contact, 'email'))->toBe(DataArea::Identity)
        ->and($areas->areaOfAttribute($contact, 'date_of_birth'))->toBe(DataArea::Sensitive)
        ->and($areas->areaOfAttribute($contact, 'lead_status'))->toBeNull()
        ->and($areas->areaOfAttribute(new Activity, 'body'))->toBe(DataArea::Sales);
});

it('keeps a change of the date of birth in the Sensitive area in the history', function (): void {
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02', 'job_title' => 'Analyst']);
    $contact->update(['date_of_birth' => '2012-04-09', 'job_title' => 'Director']);

    $history = PropertyHistory::query()->get()->keyBy('property_name');
    $areas = app(DataAreas::class);

    expect($areas->areaOfAttribute($history['date_of_birth'], 'new_value'))->toBe(DataArea::Sensitive)
        ->and($areas->areaOfAttribute($history['job_title'], 'new_value'))->toBe(DataArea::Identity);
});

it('lets everyone see everything on a self-hosted install, and nobody reveal anything', function (): void {
    expect(app(AreaAccess::class))->toBeInstanceOf(AllowAllAreaAccess::class);

    signedInAs(1);
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02']);

    expect(app(Areas::class)->canSee(DataArea::Sensitive, $contact))->toBeTrue()
        ->and($contact->fresh()->makeVisible('date_of_birth')->toArray()['date_of_birth'])->not->toBeNull()
        ->and(app(Areas::class)->mayReveal(DataArea::Sensitive))->toBeFalse();
});

it('shows the system everything: nobody signed in means nothing is masked', function (): void {
    areasHeldBy([]);
    $contact = Contact::factory()->create(['email' => 'ann@example.com']);

    expect(app(Areas::class)->canSee(DataArea::Sensitive, $contact))->toBeTrue()
        ->and($contact->fresh()->toArray()['email'])->toBe('ann@example.com');
});

it('leaves a masked area out of arrays and JSON, which is what an API response or an export is made of', function (): void {
    $contact = Contact::factory()->create(['email' => 'ann@example.com', 'first_name' => 'Ann', 'date_of_birth' => '2011-03-02']);
    signedInAs(2);
    areasHeldBy([2 => [DataArea::Identity]]);

    $array = $contact->fresh()->makeVisible('date_of_birth')->toArray();

    expect($array['email'])->toBe('ann@example.com')
        ->and($array['date_of_birth'])->toBeNull()
        ->and($contact->fresh()->makeVisible('date_of_birth')->toJson())->not->toContain('2011')
        // Code that works on the record, not on what a person sees, still reads it.
        ->and($contact->fresh()->date_of_birth->toDateString())->toBe('2011-03-02');

    areasHeldBy([2 => [DataArea::Sensitive]]);
    expect($contact->fresh()->toArray()['email'])->toBeNull()
        ->and($contact->fresh()->makeVisible('date_of_birth')->toArray()['date_of_birth'])->not->toBeNull();
});

it('does not let someone change what they cannot see', function (): void {
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02', 'job_title' => 'Analyst']);
    signedInAs(3);
    areasHeldBy([3 => [DataArea::Identity]]);

    $contact->fresh()->update(['date_of_birth' => '1999-01-01', 'job_title' => 'Director']);

    expect($contact->fresh()->date_of_birth->toDateString())->toBe('2011-03-02')
        ->and($contact->fresh()->job_title)->toBe('Director');

    $created = Contact::factory()->create(['date_of_birth' => '1990-05-05']);
    expect($created->fresh()->date_of_birth)->toBeNull();
});

it('reveals an area for one record with a reason, for a limited time, and says so', function (): void {
    Event::fake([AreaRevealed::class]);
    $one = Contact::factory()->create(['date_of_birth' => '2011-03-02']);
    $other = Contact::factory()->create(['date_of_birth' => '2012-03-02']);
    signedInAs(4);
    areasHeldBy([4 => [DataArea::Identity]], [DataArea::Sensitive]);
    $areas = app(Areas::class);

    expect($areas->mayReveal(DataArea::Sensitive))->toBeTrue()
        ->and(fn () => $areas->reveal(DataArea::Sensitive, $one, 'too short'))->toThrow(RevealNotAllowed::class)
        ->and($areas->canSee(DataArea::Sensitive, $one))->toBeFalse();

    $areas->reveal(DataArea::Sensitive, $one, '  Parent asked about the age limit.  ');

    expect($areas->canSee(DataArea::Sensitive, $one))->toBeTrue()
        ->and($one->fresh()->makeVisible('date_of_birth')->toArray()['date_of_birth'])->not->toBeNull()
        ->and($areas->canSee(DataArea::Sensitive, $other))->toBeFalse()
        ->and($other->fresh()->makeVisible('date_of_birth')->toArray()['date_of_birth'])->toBeNull();

    Event::assertDispatched(AreaRevealed::class, fn (AreaRevealed $event): bool => $event->area === DataArea::Sensitive
        && $event->userId === 4 && $event->recordId === $one->id && $event->reason === 'Parent asked about the age limit.');

    $this->travel(16)->minutes();
    expect($areas->canSee(DataArea::Sensitive, $one))->toBeFalse();
});

it('refuses a reveal when the role does not allow it, even with a reason', function (): void {
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02']);
    signedInAs(5);
    areasHeldBy([5 => [DataArea::Identity]], []);

    expect(fn () => app(Areas::class)->reveal(DataArea::Sensitive, $contact, 'I would like to see it please'))->toThrow(RevealNotAllowed::class);
});

it('keeps the assistant to the areas of the person it acts for, and the Sensitive area away from the AI provider whoever asks', function (): void {
    Contact::factory()->create(['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com', 'date_of_birth' => now()->subYears(14)->toDateString()]);
    areasHeldBy([6 => [DataArea::Identity, DataArea::Sensitive], 7 => [DataArea::Sales]]);
    config(['odden-core.email_validation.check_dns' => false]);

    // Someone with Identity and Sensitive: gets the contact, and the age but not the date of birth.
    $data = app(ActionExecutor::class)->run('contacts.search', Actor::ai(6), ['query' => 'ann'])->data['contacts'][0];
    expect($data['email'])->toBe('ann@example.com')->and($data['age'])->toBe(14)->and($data)->not->toHaveKey('date_of_birth');

    // Someone without Identity: the fields are withheld, and so is the age.
    $data = app(ActionExecutor::class)->run('contacts.search', Actor::ai(7), ['query' => 'ann'])->data['contacts'][0];
    expect($data['email'])->toBe(AreaRedactor::WITHHELD)->and($data['name'])->toBe(AreaRedactor::WITHHELD)->and($data['age'])->toBeNull()
        ->and($data['id'])->toBeInt();

    // A person using the same action in the panel gets the same areas, not the AI's extra redaction.
    expect(app(ActionExecutor::class)->run('contacts.search', Actor::user(7), ['query' => 'ann'])->data['contacts'][0]['email'])->toBe(AreaRedactor::WITHHELD);
});

it('refuses input that names a field in an area the actor does not hold', function (): void {
    $contact = Contact::factory()->create(['job_title' => 'Analyst']);
    areasHeldBy([8 => [DataArea::Sales]]);

    $result = app(ActionExecutor::class)->run('contacts.update', Actor::ai(8), ['contact_id' => $contact->id, 'job_title' => 'Director']);

    expect($result->status)->toBe(ActionResult::FAILED)
        ->and($result->message)->toContain('Identity')
        ->and($contact->fresh()->job_title)->toBe('Analyst');
});

it('withholds the Sensitive area from text bound for an AI provider by key name, whatever the person holds', function (): void {
    areasHeldBy([9 => DataArea::cases()]);

    $out = app(AreaRedactor::class)->forAi(['ticket' => ['subject' => 'Help', 'contact' => ['name' => 'Ann', 'date_of_birth' => '2011-03-02']]]);

    expect($out['ticket']['contact']['date_of_birth'])->toBe(AreaRedactor::SENSITIVE)
        ->and($out['ticket']['contact']['name'])->toBe('Ann');
});

it('does not touch the database to answer who holds what when nobody is signed in', function (): void {
    areasHeldBy([]);

    DB::enableQueryLog();
    app(Areas::class)->canSee(DataArea::Sensitive);

    expect(DB::getQueryLog())->toBe([]);
});
