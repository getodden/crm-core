<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Encryption\EncryptedColumns;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Encryption\Index\IndexText;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Models\PropertyHistory;
use Odden\Core\Support\ActivityMarker;

function storedValue(string $table, int $id, string $column): ?string
{
    return DB::table($table)->where('id', $id)->value($column);
}

function runMigration(string $file): void
{
    (include dirname(__DIR__).'/database/migrations/'.$file)->up();
}

it('stores what people write on the timeline encrypted, and reads it back', function (): void {
    $contact = Contact::factory()->create();
    $activity = $contact->logActivity(type: 'note', title: 'Call with Ada', body: 'She is moving to Leeds in May.', metadata: ['to' => 'ada@example.com']);

    foreach (['title', 'body', 'metadata'] as $column) {
        $stored = storedValue('odden_activities', $activity->id, $column);

        expect(app(FieldEncrypter::class)->isEncrypted($stored))->toBeTrue()
            ->and($stored)->not->toContain('Ada')->not->toContain('Leeds')->not->toContain('example.com');
    }

    $fresh = $activity->fresh();

    expect($fresh->title)->toBe('Call with Ada')
        ->and($fresh->body)->toBe('She is moving to Leeds in May.')
        ->and($fresh->metadata)->toBe(['to' => 'ada@example.com']);
});

it('tags cadence and portal-view activities with a plain marker, and leaves the rest untagged', function (): void {
    $contact = Contact::factory()->create();

    $step = $contact->logActivity(type: 'task', title: 'Call', metadata: ['sequence_enrollment_id' => 12, 'step' => 3]);
    $view = $contact->logActivity(type: 'note', title: 'Viewed', metadata: ['quote_id' => 7, 'event' => 'portal_view', 'ip' => '203.0.113.9']);
    $plain = $contact->logNote('Just a note');

    expect(storedValue('odden_activities', $step->id, 'marker'))->toBe(ActivityMarker::cadenceStep(12, 3))
        ->and(storedValue('odden_activities', $view->id, 'marker'))->toBe(ActivityMarker::quotePortalView(7))
        ->and(storedValue('odden_activities', $plain->id, 'marker'))->toBeNull()
        ->and(Activity::query()->where('marker', 'cadence:12:3')->pluck('id')->all())->toBe([$step->id]);
});

it('encrypts the old and new value of a property change', function (): void {
    $contact = Contact::factory()->create(['job_title' => 'Analyst']);
    $contact->update(['job_title' => 'Director of Finance']);

    $history = PropertyHistory::query()->where('property_name', 'job_title')->firstOrFail();

    expect(storedValue('odden_property_history', $history->id, 'new_value'))->not->toContain('Director')
        ->and($history->new_value)->toBe('Director of Finance')
        ->and($history->old_value)->toBe('Analyst');
});

it('keeps the stored date of birth when it is saved unchanged, and replaces it when it changes', function (): void {
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02']);
    $first = storedValue('odden_contacts', $contact->id, 'date_of_birth');

    $contact = $contact->fresh();
    $contact->update(['date_of_birth' => '2011-03-02', 'job_title' => 'Scout']);

    expect(storedValue('odden_contacts', $contact->id, 'date_of_birth'))->toBe($first)
        ->and(PropertyHistory::query()->where('property_name', 'date_of_birth')->count())->toBe(0);

    $contact->update(['date_of_birth' => '2012-04-09']);

    expect(storedValue('odden_contacts', $contact->id, 'date_of_birth'))->not->toBe($first)
        ->and($contact->fresh()->age(now()->setDate(2026, 4, 9)))->toBe(14);
});

it('works out the age from an encrypted date of birth, and from none', function (): void {
    expect(Contact::factory()->create(['date_of_birth' => '2010-06-15'])->fresh()->age(now()->setDate(2023, 6, 14)))->toBe(12)
        ->and(Contact::factory()->create()->fresh()->age())->toBeNull();
});

it('converts plain rows from before, and does it again without harm', function (): void {
    $contact = Contact::factory()->create();

    DB::table('odden_contacts')->where('id', $contact->id)->update(['date_of_birth' => '2009-12-31 00:00:00']);
    $id = DB::table('odden_activities')->insertGetId([
        'subject_type' => $contact->getMorphClass(), 'subject_id' => $contact->id, 'type' => 'task', 'status' => 'pending',
        'title' => 'Call: Intro', 'body' => 'Cadence [Warm] Step 2', 'metadata' => '{"sequence_enrollment_id":5,"step":2}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $historyId = DB::table('odden_property_history')->insertGetId([
        'auditable_type' => $contact->getMorphClass(), 'auditable_id' => $contact->id, 'property_name' => 'job_title',
        'old_value' => 'Intern', 'new_value' => 'Manager', 'source' => 'web', 'created_at' => now(),
    ]);

    // The plain values are still readable while plain values are allowed.
    expect(Activity::query()->find($id)->title)->toBe('Call: Intro')
        ->and($contact->fresh()->date_of_birth->toDateString())->toBe('2009-12-31');

    foreach (range(1, 2) as $_) {
        runMigration('2026_10_24_000021_encrypt_contact_date_of_birth.php');
        runMigration('2026_10_24_000022_encrypt_odden_activities_text.php');
        runMigration('2026_10_24_000023_encrypt_odden_property_history_values.php');
    }

    $encrypter = app(FieldEncrypter::class);

    expect($encrypter->isEncrypted(storedValue('odden_contacts', $contact->id, 'date_of_birth')))->toBeTrue()
        ->and($encrypter->isEncrypted(storedValue('odden_activities', $id, 'title')))->toBeTrue()
        ->and($encrypter->isEncrypted(storedValue('odden_activities', $id, 'metadata')))->toBeTrue()
        ->and(storedValue('odden_activities', $id, 'marker'))->toBe('cadence:5:2')
        ->and($encrypter->isEncrypted(storedValue('odden_property_history', $historyId, 'new_value')))->toBeTrue();

    config(['odden-core.encryption.allow_plaintext' => false]);

    expect($contact->fresh()->date_of_birth->toDateString())->toBe('2009-12-31')
        ->and(Activity::query()->find($id)->metadata)->toBe(['sequence_enrollment_id' => 5, 'step' => 2])
        ->and(PropertyHistory::query()->find($historyId)->old_value)->toBe('Intern');
});

it('leaves stored rows alone when the host turns conversion on migrate off, and the command converts them later', function (): void {
    $contact = Contact::factory()->create();
    DB::table('odden_contacts')->where('id', $contact->id)->update(['date_of_birth' => '2009-12-31']);

    config(['odden-core.encryption.convert_on_migrate' => false]);
    runMigration('2026_10_24_000021_encrypt_contact_date_of_birth.php');

    expect(storedValue('odden_contacts', $contact->id, 'date_of_birth'))->toBe('2009-12-31');

    $this->artisan('odden:encrypt-existing')->assertSuccessful();

    expect(app(FieldEncrypter::class)->isEncrypted(storedValue('odden_contacts', $contact->id, 'date_of_birth')))->toBeTrue()
        ->and($contact->fresh()->date_of_birth->toDateString())->toBe('2009-12-31');
});

it('converts only the rows a scope allows', function (): void {
    $kept = Contact::factory()->create(['team_id' => 1]);
    $other = Contact::factory()->create(['team_id' => 2]);
    DB::table('odden_contacts')->update(['date_of_birth' => '2009-12-31']);

    $done = app(EncryptedColumns::class)->convert(app(ColumnConverter::class), fn ($query, string $table) => $table === 'odden_contacts' ? $query->where('team_id', 1) : $query->whereRaw('1 = 0'));

    expect($done['contacts.date_of_birth'])->toBe(1)
        ->and(app(FieldEncrypter::class)->isEncrypted(storedValue('odden_contacts', $kept->id, 'date_of_birth')))->toBeTrue()
        ->and(storedValue('odden_contacts', $other->id, 'date_of_birth'))->toBe('2009-12-31');
});

it('fills the hash column of an encrypted column from plain and from encrypted values, and a second run changes nothing', function (): void {
    Schema::create('odden_hash_probe', function (Blueprint $table): void {
        $table->id();
        $table->text('email')->nullable();
        $table->string('email_hash', 64)->nullable()->unique();
    });

    $encrypter = app(FieldEncrypter::class);
    DB::table('odden_hash_probe')->insert([
        ['email' => 'Ada@Example.com'],
        ['email' => $encrypter->encrypt('grace@example.com', 'probe.email')],
        ['email' => null],
    ]);

    $converter = app(ColumnConverter::class);

    expect($converter->hash('odden_hash_probe', 'email', 'email_hash', 'probe.email', IndexText::EMAIL))->toBe(2)
        ->and($converter->hash('odden_hash_probe', 'email', 'email_hash', 'probe.email', IndexText::EMAIL))->toBe(0)
        ->and(DB::table('odden_hash_probe')->where('email_hash', $encrypter->blindIndex('ada@example.com', 'probe.email'))->count())->toBe(1)
        ->and(DB::table('odden_hash_probe')->where('email_hash', $encrypter->blindIndex('grace@example.com', 'probe.email'))->count())->toBe(1)
        // The hash is keyed by the field: the same address in another field gives another hash.
        ->and($encrypter->blindIndex('ada@example.com', 'probe.email'))->not->toBe($encrypter->blindIndex('ada@example.com', 'other.email'));

    Schema::drop('odden_hash_probe');
});
