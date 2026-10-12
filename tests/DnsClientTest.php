<?php

declare(strict_types=1);

use Odden\Core\Actions\CreateContactAction;
use Odden\Core\Contracts\MailRouteResolver;
use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\EmailStatusReason;
use Odden\Core\Support\DnsClient;
use Odden\Core\Support\SystemMailRouteResolver;

/**
 * A UDP socket on localhost that receives queries and never answers, like a nameserver that
 * has stopped responding.
 *
 * @return array{0: resource, 1: string}
 */
function silentNameserver(): array
{
    $server = stream_socket_server('udp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND);

    expect($server)->not->toBeFalse();

    return [$server, (string) stream_socket_get_name($server, false)];
}

function dnsName(string $name): string
{
    $encoded = '';

    foreach (explode('.', $name) as $label) {
        $encoded .= chr(strlen($label)).$label;
    }

    return $encoded."\0";
}

/**
 * A response to a query for example.com, with the given flags and answer records.
 *
 * @param  list<string>  $answers
 */
function dnsResponse(int $id, int $flags, array $answers): string
{
    return pack('nnnnnn', $id, $flags, 1, count($answers), 0, 0)
        .dnsName('example.com').pack('nn', DnsClient::TYPE_MX, 1)
        .implode('', $answers);
}

/**
 * An answer record whose owner name points back at the question (offset 12).
 */
function dnsRecord(int $type, string $data): string
{
    return "\xC0\x0C".pack('nnNn', $type, 1, 300, strlen($data)).$data;
}

it('gives up on a nameserver that does not answer within the time it is given', function (): void {
    [$server, $address] = silentNameserver();

    $client = new DnsClient([$address]);

    $started = microtime(true);
    $result = $client->query('example.com', DnsClient::TYPE_MX, microtime(true) + 0.3);
    $elapsed = microtime(true) - $started;

    fclose($server);

    expect($result)->toBeNull()
        ->and($elapsed)->toBeLessThan(1.0);
});

it('treats a slow nameserver as unknown, never invalid, and still creates the contact quickly', function (): void {
    [$server, $address] = silentNameserver();

    config([
        'odden-core.email_validation.dns_nameservers' => [$address],
        'odden-core.email_validation.dns_timeout_seconds' => 0.3,
    ]);
    $this->app->bind(MailRouteResolver::class, SystemMailRouteResolver::class);

    $started = microtime(true);
    $contact = app(CreateContactAction::class)->execute(['email' => 'dana@slow-nameserver.example', 'lifecycle_stage' => 'lead']);
    $first = microtime(true) - $started;

    // The failure is remembered, so the next contact on the domain doesn't wait at all.
    $started = microtime(true);
    app(CreateContactAction::class)->execute(['email' => 'sam@slow-nameserver.example', 'lifecycle_stage' => 'lead']);
    $second = microtime(true) - $started;

    fclose($server);

    expect($contact->email_status)->toBe(EmailStatus::Unknown)
        ->and($contact->email_status_reason)->toBe(EmailStatusReason::DnsUnavailable)
        ->and($first)->toBeLessThan(1.5)
        ->and($second)->toBeLessThan(0.25);
});

it('reads MX targets, including compressed names and a null MX', function (): void {
    $client = new DnsClient(['127.0.0.1']);

    $mail = dnsRecord(DnsClient::TYPE_MX, pack('n', 10).dnsName('mail.example.com'));
    // "backup" followed by a pointer to "example.com" in the question.
    $backup = dnsRecord(DnsClient::TYPE_MX, pack('n', 20)."\x06backup\xC0\x0C");
    $nullMx = dnsRecord(DnsClient::TYPE_MX, pack('n', 0)."\0");

    expect($client->parseResponse(dnsResponse(7, 0x8180, [$mail, $backup]), 7, DnsClient::TYPE_MX))
        ->toBe(['mail.example.com', 'backup.example.com'])
        ->and($client->parseResponse(dnsResponse(7, 0x8180, [$nullMx]), 7, DnsClient::TYPE_MX))
        ->toBe(['']);
});

it('skips records of other types, such as a CNAME on the way', function (): void {
    $client = new DnsClient(['127.0.0.1']);

    $cname = dnsRecord(5, dnsName('alias.example.net'));
    $address = dnsRecord(DnsClient::TYPE_A, "\x7F\x00\x00\x01");

    expect($client->parseResponse(dnsResponse(9, 0x8180, [$cname, $address]), 9, DnsClient::TYPE_A))->toBe([''])
        ->and($client->parseResponse(dnsResponse(9, 0x8180, [$cname]), 9, DnsClient::TYPE_A))->toBe([]);
});

it('answers a name that does not exist with no records, and unusable answers with null', function (): void {
    $client = new DnsClient(['127.0.0.1']);

    expect($client->parseResponse(dnsResponse(3, 0x8183, []), 3, DnsClient::TYPE_MX))->toBe([])
        ->and($client->parseResponse(dnsResponse(3, 0x8182, []), 3, DnsClient::TYPE_MX))->toBeNull() // SERVFAIL
        ->and($client->parseResponse(dnsResponse(3, 0x8380, []), 3, DnsClient::TYPE_MX))->toBeNull() // truncated
        ->and($client->parseResponse(dnsResponse(4, 0x8180, []), 3, DnsClient::TYPE_MX))->toBeNull() // another query
        ->and($client->parseResponse(substr(dnsResponse(3, 0x8180, [dnsRecord(DnsClient::TYPE_MX, pack('n', 10).dnsName('mail.example.com'))]), 0, -4), 3, DnsClient::TYPE_MX))->toBeNull()
        ->and($client->parseResponse("\xC0\x0C", 3, DnsClient::TYPE_MX))->toBeNull();
});

it('does not follow compression pointers in a loop', function (): void {
    $client = new DnsClient(['127.0.0.1']);

    // The answer's owner name points at itself.
    $response = pack('nnnnnn', 5, 0x8180, 0, 1, 0, 0)."\xC0\x0C".pack('nnNn', DnsClient::TYPE_MX, 1, 300, 0);

    expect($client->parseResponse($response, 5, DnsClient::TYPE_MX))->toBeNull();
});

it('builds a recursive query and refuses names DNS cannot carry', function (): void {
    $client = new DnsClient(['127.0.0.1']);

    expect($client->buildQuery(1, 'example.com', DnsClient::TYPE_MX))
        ->toBe(pack('nnnnnn', 1, 0x0100, 1, 0, 0, 0).dnsName('example.com').pack('nn', DnsClient::TYPE_MX, 1))
        ->and($client->buildQuery(1, str_repeat('a', 64).'.com', DnsClient::TYPE_MX))->toBeNull()
        ->and($client->buildQuery(1, 'a..com', DnsClient::TYPE_MX))->toBeNull();
});

it('reads the nameservers from resolv.conf', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'resolv');
    file_put_contents($path, "# comment\nsearch example.com\nnameserver 10.0.0.2\nnameserver fe80::1%eth0\nnameserver ::1\noptions timeout:5\nnameserver not-an-ip\n");

    $nameservers = DnsClient::systemNameservers($path);
    unlink($path);

    expect($nameservers)->toBe(['10.0.0.2', '::1'])
        ->and(DnsClient::systemNameservers('/nonexistent/resolv.conf'))->toBe([]);
});
