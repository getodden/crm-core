<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Odden\Core\Models\Contact;

it('gives the age in whole years, counting from the birthday', function (): void {
    $contact = Contact::factory()->create(['date_of_birth' => '2010-06-15']);

    expect($contact->age(Carbon::parse('2023-06-14')))->toBe(12)
        ->and($contact->age(Carbon::parse('2023-06-15')))->toBe(13)
        ->and($contact->age(Carbon::parse('2023-06-16')))->toBe(13)
        ->and($contact->age(Carbon::parse('2028-06-15')))->toBe(18);
});

it('uses today when no date is given', function (): void {
    $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
    $contact = Contact::factory()->create(['date_of_birth' => '2000-10-23']);

    expect($contact->age())->toBe(26);
});

it('does not know the age of a contact without a date of birth, or with one in the future', function (): void {
    $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));

    expect(Contact::factory()->create(['date_of_birth' => null])->age())->toBeNull()
        ->and(Contact::factory()->create(['date_of_birth' => '2026-10-24'])->age())->toBeNull();
});

it('keeps the date of birth out of arrays and JSON, but stores and returns it', function (): void {
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02']);

    expect($contact->toArray())->not->toHaveKey('date_of_birth')
        ->and($contact->toJson())->not->toContain('2011-03-02')
        ->and($contact->fresh()->date_of_birth->toDateString())->toBe('2011-03-02');
});
