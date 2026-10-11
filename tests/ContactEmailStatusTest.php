<?php

declare(strict_types=1);

use Odden\Core\Actions\CreateContactAction;
use Odden\Core\Actions\ValidateContactEmailAction;
use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\EmailStatusReason;
use Odden\Core\Models\Contact;
use Odden\Core\Models\PropertyHistory;
use Odden\Core\Support\ContactLookup;

it('records an offline status on every contact it creates', function (): void {
    $risky = Contact::factory()->create(['email' => 'info@acme.com']);
    $clean = Contact::factory()->create(['email' => 'dana@acme.com']);
    $broken = Contact::factory()->create(['email' => 'not-an-email']);

    expect($risky->email_status)->toBe(EmailStatus::Risky)
        ->and($risky->email_status_reason)->toBe(EmailStatusReason::Role)
        ->and($clean->email_status)->toBe(EmailStatus::Unknown)
        ->and($clean->email_status_reason)->toBe(EmailStatusReason::NotChecked)
        ->and($broken->email_status)->toBe(EmailStatus::Invalid)
        ->and($clean->email_checked_at)->not->toBeNull();
});

it('records a status for contacts created through ContactLookup', function (): void {
    $contact = ContactLookup::findOrCreate('Dana@Mailinator.com');

    expect($contact->email_status)->toBe(EmailStatus::Risky)
        ->and($contact->email_status_reason)->toBe(EmailStatusReason::Disposable);
});

it('settles the status with a DNS check when created through CreateContactAction', function (): void {
    $valid = (new CreateContactAction)->execute(['email' => 'Dana@Acme.com']);
    $gone = (new CreateContactAction)->execute(['email' => 'dana@gone.invalid']);

    expect($valid->fresh()->email_status)->toBe(EmailStatus::Valid)
        ->and($gone->fresh()->email_status)->toBe(EmailStatus::Invalid)
        ->and($gone->fresh()->email_status_reason)->toBe(EmailStatusReason::NoMailServer);
});

it('leaves the DNS check out when it is switched off', function (): void {
    config(['odden-core.email_validation.check_dns' => false]);

    $contact = (new CreateContactAction)->execute(['email' => 'dana@acme.com']);

    expect($contact->fresh()->email_status)->toBe(EmailStatus::Unknown);
});

it('leaves the status empty when validation is disabled', function (): void {
    config(['odden-core.email_validation.enabled' => false]);

    $contact = (new CreateContactAction)->execute(['email' => 'info@acme.com']);

    expect($contact->fresh()->email_status)->toBeNull();
});

it('keeps a status the caller supplied', function (): void {
    $contact = Contact::factory()->create([
        'email' => 'dana@acme.com',
        'email_status' => EmailStatus::Valid,
    ]);

    expect($contact->email_status)->toBe(EmailStatus::Valid);
});

it('re-checks the address when the email changes so a status is never stale', function (): void {
    $contact = (new CreateContactAction)->execute(['email' => 'dana@acme.com']);
    expect($contact->fresh()->email_status)->toBe(EmailStatus::Valid);

    $contact->update(['email' => 'info@acme.com']);

    expect($contact->fresh()->email_status)->toBe(EmailStatus::Risky)
        ->and($contact->fresh()->email_status_reason)->toBe(EmailStatusReason::Role);
});

it('does not touch the status when something else changes', function (): void {
    $contact = (new CreateContactAction)->execute(['email' => 'dana@acme.com']);

    $contact->update(['first_name' => 'Dana']);

    expect($contact->fresh()->email_status)->toBe(EmailStatus::Valid);
});

it('validates an existing contact on demand without writing property history', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@acme.com']);
    $before = PropertyHistory::query()->count();

    app(ValidateContactEmailAction::class)->execute($contact);

    expect($contact->fresh()->email_status)->toBe(EmailStatus::Valid)
        ->and(PropertyHistory::query()->count())->toBe($before);
});

it('filters contacts by status', function (): void {
    Contact::factory()->create(['email' => 'info@acme.com']);
    Contact::factory()->create(['email' => 'dana@acme.com']);

    expect(Contact::query()->where('email_status', EmailStatus::Risky)->count())->toBe(1);
});

it('does not re-check a bounced address back to valid', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@acme.com']);
    $contact->forceFill([
        'email_status' => EmailStatus::Invalid,
        'email_status_reason' => EmailStatusReason::Bounced,
    ])->saveQuietly();

    app(ValidateContactEmailAction::class)->execute($contact);

    expect($contact->fresh()->hasBounced())->toBeTrue()
        ->and($contact->fresh()->email_status)->toBe(EmailStatus::Invalid);
});

it('forgets a bounce when the address changes', function (): void {
    $contact = Contact::factory()->create(['email' => 'dana@acme.com']);
    $contact->forceFill(['email_status' => EmailStatus::Invalid, 'email_status_reason' => EmailStatusReason::Bounced])->saveQuietly();

    $contact->update(['email' => 'dana.smith@acme.com']);

    expect($contact->fresh()->hasBounced())->toBeFalse()
        ->and($contact->fresh()->email_status)->not->toBe(EmailStatus::Invalid);
});
