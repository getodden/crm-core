<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
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

function contactEmailLock(string $email, int $seconds = 10): Lock
{
    return Cache::lock('odden:contact-email:'.sha1($email), $seconds);
}

it('looks again after waiting for the lock, so a contact created meanwhile is returned', function (): void {
    // Another request holds the lock while it creates the contact.
    $other = contactEmailLock('race@example.com');
    expect($other->get())->toBeTrue();

    $existing = Contact::factory()->create(['email' => 'race@example.com']);
    $other->release();

    $found = ContactLookup::findOrCreate('Race@Example.com', ['first_name' => 'Second']);

    expect($found->id)->toBe($existing->id)
        ->and(Contact::query()->where('email', 'race@example.com')->count())->toBe(1);
});

it('keeps the lock until the surrounding transaction commits, so other connections wait for the new contact', function (): void {
    DB::transaction(function (): void {
        ContactLookup::findOrCreate('pending@example.com');

        // Another connection can't see the uncommitted contact, so it must not get the lock yet.
        expect(contactEmailLock('pending@example.com')->get())->toBeFalse();

        // The same request looking up the address again sees its own contact without waiting.
        expect(ContactLookup::findOrCreate('pending@example.com')->email)->toBe('pending@example.com');
    });

    expect(contactEmailLock('pending@example.com')->get())->toBeTrue()
        ->and(Contact::query()->where('email', 'pending@example.com')->count())->toBe(1);
});

it('releases the lock right away outside a transaction', function (): void {
    ContactLookup::findOrCreate('quick@example.com');

    expect(contactEmailLock('quick@example.com')->get())->toBeTrue();
});

it('still creates the contact once when the lock is never released', function (): void {
    Sleep::fake(syncWithCarbon: true);

    expect(contactEmailLock('stuck@example.com', 60)->get())->toBeTrue();

    $first = ContactLookup::findOrCreate('stuck@example.com');
    $second = ContactLookup::findOrCreate('stuck@example.com');

    expect($second->id)->toBe($first->id)
        ->and(Contact::query()->where('email', 'stuck@example.com')->count())->toBe(1);
});
