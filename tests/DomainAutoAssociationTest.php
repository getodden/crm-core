<?php

declare(strict_types=1);

use Odden\Core\Actions\AutoAssociateContactCompanyAction;
use Odden\Core\Actions\CreateCompanyAction;
use Odden\Core\Actions\CreateContactAction;
use Odden\Core\Actions\ExtractCorporateDomainAction;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\FreemailDomains;

it('identifies standard consumer freemail domains', function () {
    expect(FreemailDomains::isFreemail('gmail.com'))->toBeTrue()
        ->and(FreemailDomains::isFreemail('GMAIL.COM'))->toBeTrue()
        ->and(FreemailDomains::isFreemail('yahoo.com'))->toBeTrue()
        ->and(FreemailDomains::isFreemail('hotmail.co.uk'))->toBeTrue()
        ->and(FreemailDomains::isFreemail('outlook.com'))->toBeTrue()
        ->and(FreemailDomains::isFreemail('protonmail.com'))->toBeTrue()
        ->and(FreemailDomains::isFreemail('stripe.com'))->toBeFalse()
        ->and(FreemailDomains::isFreemail('acme-corp.org'))->toBeFalse();
});

it('extracts and normalizes corporate domains from email', function () {
    $action = app(ExtractCorporateDomainAction::class);

    expect($action->execute('sarah@stripe.com'))->toBe('stripe.com')
        ->and($action->execute('JOHN@ACME-WIDGETS.CO.UK'))->toBe('acme-widgets.co.uk')
        ->and($action->execute('rep@mail.hubspot.com'))->toBe('hubspot.com')
        ->and($action->execute('user@email.salesforce.com'))->toBe('salesforce.com')
        ->and($action->execute('invalid-email'))->toBeNull()
        ->and($action->execute('john.doe@gmail.com'))->toBeNull()
        ->and($action->execute('jane@yahoo.com'))->toBeNull();
});

it('derives human-readable company names from domains', function () {
    $action = app(ExtractCorporateDomainAction::class);

    expect($action->deriveCompanyName('stripe.com'))->toBe('Stripe')
        ->and($action->deriveCompanyName('acme-widgets.com'))->toBe('Acme Widgets')
        ->and($action->deriveCompanyName('linear-app.io'))->toBe('Linear App');
});

it('auto-associates contact to existing company with matching domain', function () {
    $company = Company::factory()->create([
        'name' => 'Stripe Inc',
        'domain' => 'stripe.com',
    ]);

    $contact = Contact::factory()->create([
        'email' => 'patrick@stripe.com',
    ]);

    expect($contact->companies)->toHaveCount(0);

    $action = app(AutoAssociateContactCompanyAction::class);
    $associatedCompany = $action->execute($contact);

    expect($associatedCompany)->not->toBeNull()
        ->and($associatedCompany->id)->toBe($company->id);

    $contact->refresh();
    expect($contact->companies)->toHaveCount(1)
        ->and($contact->companies->first()->id)->toBe($company->id);
});

it('does not auto-associate contact if email domain is freemail', function () {
    $contact = Contact::factory()->create([
        'email' => 'freelancer@gmail.com',
    ]);

    $action = app(AutoAssociateContactCompanyAction::class);
    $associated = $action->execute($contact, createCompanyIfMissing: true);

    expect($associated)->toBeNull();
    $contact->refresh();
    expect($contact->companies)->toHaveCount(0);
});

it('creates new company and associates contact when createCompanyIfMissing is true', function () {
    $contact = Contact::factory()->create([
        'email' => 'elena@novatech-solutions.com',
    ]);

    $action = app(AutoAssociateContactCompanyAction::class);
    $company = $action->execute($contact, createCompanyIfMissing: true);

    expect($company)->not->toBeNull()
        ->and($company->domain)->toBe('novatech-solutions.com')
        ->and($company->name)->toBe('Novatech Solutions');

    $contact->refresh();
    expect($contact->companies)->toHaveCount(1)
        ->and($contact->companies->first()->id)->toBe($company->id);
});

it('integrates auto-association seamlessly into CreateContactAction', function () {
    $existing = Company::factory()->create([
        'name' => 'Vercel',
        'domain' => 'vercel.com',
    ]);

    $createContactAction = app(CreateContactAction::class);

    $contact = $createContactAction->execute([
        'first_name' => 'Guillermo',
        'last_name' => 'Rauch',
        'email' => 'guillermo@vercel.com',
    ], autoAssociateCompany: true);

    $contact->refresh();
    expect($contact->companies)->toHaveCount(1)
        ->and($contact->companies->first()->id)->toBe($existing->id);
});

it('stores company domains as a bare host, so auto-association finds them', function (string $website): void {
    $company = app(CreateCompanyAction::class)->execute(['name' => 'Acme', 'domain' => $website]);

    expect($company->domain)->toBe('acme.com')
        ->and(Company::whereDomain($website)->first()?->id)->toBe($company->id)
        ->and(app(AutoAssociateContactCompanyAction::class)->execute(Contact::factory()->create(['email' => 'sam@acme.com']))?->id)->toBe($company->id);
})->with([
    'https://www.Acme.com/about',
    'www.acme.com',
    'acme.com:443/',
    'http://acme.com?ref=ad',
]);

it('picks the same company every time when several share a domain', function (): void {
    $older = Company::factory()->create(['domain' => 'globex.com', 'team_id' => null]);
    $teamCompany = Company::factory()->create(['domain' => 'globex.com', 'team_id' => 4]);
    Company::factory()->create(['domain' => 'globex.com', 'team_id' => null]);

    $action = app(AutoAssociateContactCompanyAction::class);

    expect($action->execute(Contact::factory()->create(['email' => 'a@globex.com']))?->id)->toBe($older->id)
        ->and($action->execute(Contact::factory()->create(['email' => 'b@globex.com', 'team_id' => 4]))?->id)->toBe($teamCompany->id);
});
