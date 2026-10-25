<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Odden\Core\Logging\LogScrubber;
use Odden\Core\Logging\ScrubLogs;
use Odden\Core\Models\Contact;

function scrubbedLog(): array
{
    $handler = new TestHandler;
    $logger = new Logger(new Monolog('test', [$handler]));
    (new ScrubLogs)($logger);

    return [$logger, $handler];
}

beforeEach(fn () => LogScrubber::flush());

it('blanks a value the application decrypted from a log message and context', function (): void {
    $contact = Contact::factory()->create(['first_name' => 'Quentin', 'last_name' => 'Marplethorpe', 'email' => 'quentin@marplethorpe.example']);
    $contact = Contact::query()->findOrFail($contact->id);
    $name = $contact->last_name;
    $email = $contact->email;

    [$logger, $handler] = scrubbedLog();
    $logger->warning("Could not mail {$email}", ['to' => $email, 'nested' => ['who' => "Dear {$name}"], 'attempt' => 3]);

    $record = $handler->getRecords()[0];
    expect($record->message)->toBe('Could not mail '.LogScrubber::MASK)
        ->and($record->context['to'])->toBe(LogScrubber::MASK)
        ->and($record->context['nested']['who'])->toBe('Dear '.LogScrubber::MASK)
        ->and($record->context['attempt'])->toBe(3);
});

it('does not log a model as its attributes, and cleans an exception without losing its place', function (): void {
    $contact = Contact::factory()->create(['last_name' => 'Marplethorpe']);
    $contact = Contact::query()->findOrFail($contact->id);
    $error = new RuntimeException("No mailbox for {$contact->last_name}", 5, new LogicException("inner {$contact->last_name}"));

    [$logger, $handler] = scrubbedLog();
    $logger->error('Failed', ['contact' => $contact, 'exception' => $error]);

    $context = $handler->getRecords()[0]->context;
    $exception = $context['exception'];

    expect($context['contact'])->toBe(Contact::class.'#'.$contact->id)
        ->and($exception->getMessage())->toBe('RuntimeException: No mailbox for '.LogScrubber::MASK)
        ->and($exception->getPrevious()->getMessage())->toBe('LogicException: inner '.LogScrubber::MASK)
        ->and($exception->getLine())->toBe($error->getLine())
        ->and($exception->getFile())->toBe($error->getFile())
        ->and($exception->getCode())->toBe(5);
});

it('drops the bindings of a query error and the value a database prints in a constraint error', function (): void {
    $query = new QueryException('default', 'select * from odden_contacts where email = ?', ['quentin@marplethorpe.example'], new PDOException("Duplicate entry 'quentin@marplethorpe.example' for key 'email'"));

    expect(LogScrubber::message($query))->not->toContain('marplethorpe')->toContain('select * from odden_contacts where email = ?')
        ->and(LogScrubber::scrub('Key (email)=(quentin@marplethorpe.example) already exists.'))->toBe('Key (email)=('.LogScrubber::MASK.') already exists.')
        ->and(LogScrubber::scrub('UNIQUE constraint failed: odden_contacts.email'))->toBe('UNIQUE constraint failed')
        ->and(LogScrubber::describe($query))->not->toContain('marplethorpe');
});

it('leaves short values alone, and forgets everything on flush', function (): void {
    LogScrubber::remember('Ann');
    LogScrubber::remember('Marplethorpe');

    expect(LogScrubber::scrub('Ann saw Marplethorpe'))->toBe('Ann saw '.LogScrubber::MASK);

    LogScrubber::flush();

    expect(LogScrubber::scrub('Ann saw Marplethorpe'))->toBe('Ann saw Marplethorpe');
});

it('puts the scrubber on every configured log channel', function (): void {
    foreach (array_keys(config('logging.channels')) as $channel) {
        expect(config("logging.channels.{$channel}.tap"))->toContain(ScrubLogs::class);
    }
});
