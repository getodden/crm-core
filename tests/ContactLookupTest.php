<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Odden\Core\Events\ContactCreated;
use Odden\Core\Models\Contact;
use Odden\Core\Support\ContactLookup;

it('finds a contact ignoring case and surrounding whitespace', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@example.com']);

    expect(ContactLookup::findByEmail('  Dana@Example.COM '))->id->toBe($contact->id);
});

it('stores contacts with the email lowercased and trimmed however they are saved', function (): void {
    $contact = Contact::factory()->create(['email' => ' Legacy.User@Example.com']);

    expect($contact->fresh()->email)->toBe('legacy.user@example.com')
        ->and(ContactLookup::findByEmail('legacy.user@example.com'))->id->toBe($contact->id);

    $contact->update(['email' => 'Renamed@Example.com ']);

    expect($contact->fresh()->email)->toBe('renamed@example.com');
});

it('normalizes emails saved before contacts were normalized on save', function (): void {
    $id = DB::table('odden_contacts')->insertGetId([
        'email' => ' Legacy.User@Example.com', 'lifecycle_stage' => 'lead', 'created_at' => now(), 'updated_at' => now(),
    ]);

    (include dirname(__DIR__).'/database/migrations/2026_01_01_000017_normalize_odden_contact_emails.php')->up();

    expect(DB::table('odden_contacts')->where('id', $id)->value('email'))->toBe('legacy.user@example.com')
        ->and(ContactLookup::findByEmail('Legacy.User@example.com'))->id->toBe($id);
});

it('uses one indexable exact match, even when nothing matches', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@example.com']);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    expect(ContactLookup::findByEmail('Dana@Example.com'))->id->toBe($contact->id)
        ->and(ContactLookup::findByEmail('nobody@example.com'))->toBeNull();

    expect($queries)->toHaveCount(2)
        ->and(implode(' ', $queries))->not->toContain('lower(');
});

it('returns null for an empty or unknown email', function (): void {
    expect(ContactLookup::findByEmail('   '))->toBeNull()
        ->and(ContactLookup::findByEmail('nobody@example.com'))->toBeNull();
});

it('creates new contacts with the email lowercased and trimmed, once', function (): void {
    $created = ContactLookup::findOrCreate(' New.Person@Example.com ', ['first_name' => 'New']);
    $again = ContactLookup::findOrCreate('new.person@example.com', ['first_name' => 'Other']);

    expect($created->email)->toBe('new.person@example.com')
        ->and($created->first_name)->toBe('New')
        ->and($again->id)->toBe($created->id)
        ->and(Contact::query()->count())->toBe(1);
});

it('creates contacts through CreateContactAction', function (): void {
    Event::fake([ContactCreated::class]);

    $created = ContactLookup::findOrCreate('fresh@example.com', ['first_name' => 'Fresh']);

    Event::assertDispatched(ContactCreated::class, fn (ContactCreated $event): bool => $event->contact->is($created));
    expect($created->email_status)->not->toBeNull();
});
