<?php

declare(strict_types=1);

use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\EmailStatusReason;
use Odden\Core\Support\EmailValidator;
use Odden\Core\Tests\Fixtures\FakeMailRouteResolver;

function checkEmail(string $email, bool $dns = true)
{
    return app(EmailValidator::class)->check($email, $dns);
}

it('accepts an ordinary address on a domain that accepts mail', function (): void {
    $check = checkEmail('Dana.Smith@Example.com');

    expect($check->status)->toBe(EmailStatus::Valid)
        ->and($check->reason)->toBeNull()
        ->and($check->isValid())->toBeTrue();
});

it('rejects malformed addresses as invalid syntax', function (string $email): void {
    $check = checkEmail($email);

    expect($check->status)->toBe(EmailStatus::Invalid)
        ->and($check->reason)->toBe(EmailStatusReason::Syntax);
})->with([
    'empty' => '',
    'no at sign' => 'dana.example.com',
    'no local part' => '@example.com',
    'no domain' => 'dana@',
    'no dot in domain' => 'dana@localhost',
    'double dot' => 'dana..smith@example.com',
    'spaces' => 'dana smith@example.com',
    'leading dot domain' => 'dana@.example.com',
    'local part too long' => str_repeat('a', 65).'@example.com',
]);

it('marks a domain with no mail server as invalid', function (): void {
    $check = checkEmail('dana@gone.invalid');

    expect($check->status)->toBe(EmailStatus::Invalid)
        ->and($check->reason)->toBe(EmailStatusReason::NoMailServer);
});

it('reports unknown when the domain could not be looked up', function (): void {
    $check = checkEmail('dana@slow.unreachable');

    expect($check->status)->toBe(EmailStatus::Unknown)
        ->and($check->reason)->toBe(EmailStatusReason::DnsUnavailable);
});

it('flags a typo in a well-known domain and suggests the correction', function (string $typo, string $suggested): void {
    $check = checkEmail("dana@{$typo}");

    expect($check->status)->toBe(EmailStatus::Risky)
        ->and($check->reason)->toBe(EmailStatusReason::Typo)
        ->and($check->suggestion)->toBe("dana@{$suggested}");
})->with([
    ['gmai.com', 'gmail.com'],
    ['gmail.con', 'gmail.com'],
    ['hotmal.com', 'hotmail.com'],
    ['yaho.com', 'yahoo.com'],
    ['gmial.com', 'gmail.com'],
    ['hotmial.com', 'hotmail.com'],
    ['outlook.cmo', 'outlook.com'],
]);

it('does not suggest a correction for real providers that are near a popular domain', function (string $domain): void {
    expect(checkEmail("dana@{$domain}")->status)->toBe(EmailStatus::Valid);
})->with(['mail.com', 'gmx.com', 'me.com', 'msn.com', 'gmail.com']);

it('flags disposable providers, including their subdomains', function (string $domain): void {
    $check = checkEmail("dana@{$domain}");

    expect($check->status)->toBe(EmailStatus::Risky)
        ->and($check->reason)->toBe(EmailStatusReason::Disposable);
})->with(['mailinator.com', 'sub.yopmail.com', 'guerrillamail.net']);

it('flags shared role mailboxes, ignoring plus tags', function (string $local): void {
    $check = checkEmail("{$local}@acme.com");

    expect($check->status)->toBe(EmailStatus::Risky)
        ->and($check->reason)->toBe(EmailStatusReason::Role);
})->with(['info', 'support', 'noreply', 'support+billing', 'Sales']);

it('never calls a result valid when DNS was not consulted', function (): void {
    $check = checkEmail('dana@example.com', dns: false);

    expect($check->status)->toBe(EmailStatus::Unknown)
        ->and($check->reason)->toBe(EmailStatusReason::NotChecked);
});

it('still finds offline problems when DNS is skipped', function (): void {
    expect(checkEmail('not-an-email', dns: false)->status)->toBe(EmailStatus::Invalid)
        ->and(checkEmail('info@acme.com', dns: false)->reason)->toBe(EmailStatusReason::Role);
});

it('uses configured additions to the disposable and role lists', function (): void {
    config([
        'odden-core.email_validation.disposable_domains' => ['burn.example'],
        'odden-core.email_validation.role_local_parts' => ['frontdesk'],
    ]);

    expect(checkEmail('dana@burn.example')->reason)->toBe(EmailStatusReason::Disposable)
        ->and(checkEmail('frontdesk@acme.com')->reason)->toBe(EmailStatusReason::Role);
});

it('validates internationalised domains in their ascii form', function (): void {
    expect(checkEmail('dana@bücher.example')->status)->toBe(EmailStatus::Valid);
});

it('skips the DNS lookup for consumer providers it already knows accept mail', function (): void {
    FakeMailRouteResolver::$lookups = 0;

    checkEmail('dana@gmail.com');

    expect(FakeMailRouteResolver::$lookups)->toBe(0);
});
