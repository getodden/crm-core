<?php

declare(strict_types=1);

namespace Odden\Core\Logging;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use JsonSerializable;
use Throwable;

/**
 * Keeps decrypted customer values out of the application's logs and stored exception text.
 *
 * Every value the application decrypts is remembered, briefly and only in this process's memory, and a log line (message, context,
 * exceptions) has each of them replaced before it is written. Database errors lose their bindings, models in a log context are
 * reduced to their class and id, and the text databases put in some errors ("Duplicate entry 'x'", "Key (email)=(x)") is blanked.
 *
 * It is a net under the code that should not log customer values in the first place, not a licence to: a value shorter than
 * MIN_LENGTH is never matched (it would blank ordinary words), and a value the application never decrypted is not known.
 */
final class LogScrubber
{
    public const string MASK = '[redacted]';

    /** Shorter values are not remembered: they would blank common words from every log line. */
    private const int MIN_LENGTH = 4;

    /** Longer values are not remembered whole (a note or a mail body does not appear in a log line unchanged). */
    private const int MAX_LENGTH = 1000;

    /** How many values are remembered at once; the oldest are forgotten first. */
    private const int CAPACITY = 1000;

    /** @var array<string, true> */
    private static array $known = [];

    /** @var list<string>|null The remembered values, longest first (the order they are replaced in), built when first needed. */
    private static ?array $ordered = null;

    /**
     * Remember a value the application has just decrypted.
     */
    public static function remember(string $plain): void
    {
        $length = strlen($plain);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || isset(self::$known[$plain])) {
            return;
        }

        self::$known[$plain] = true;
        self::$ordered = null;

        if (count(self::$known) > self::CAPACITY) {
            unset(self::$known[array_key_first(self::$known)]);
        }
    }

    /**
     * Remember the text values inside a decrypted structure (a JSON column).
     */
    public static function rememberLeaves(mixed $value): void
    {
        if (is_string($value)) {
            self::remember($value);
        } elseif (is_array($value)) {
            foreach ($value as $leaf) {
                self::rememberLeaves($leaf);
            }
        }
    }

    /**
     * Forget everything (between requests and jobs).
     */
    public static function flush(): void
    {
        self::$known = [];
        self::$ordered = null;
    }

    /**
     * The text with every remembered value, and what databases print of a value, replaced by a mask.
     */
    public static function scrub(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        if (self::$known !== []) {
            self::$ordered ??= self::sorted(array_map('strval', array_keys(self::$known)));
            $text = str_replace(self::$ordered, self::MASK, $text);
        }

        return (string) preg_replace(
            [
                "/Duplicate entry '.*?' for key/s",
                '/Key \((.*?)\)=\(.*?\)/s',
                '/\bUNIQUE constraint failed: [\w.,\s]+/',
            ],
            [
                "Duplicate entry '".self::MASK."' for key",
                'Key ($1)=('.self::MASK.')',
                'UNIQUE constraint failed',
            ],
            $text,
        );
    }

    /**
     * What an exception says about itself without the values in it.
     */
    public static function message(Throwable $e): string
    {
        // A query error prints the statement with its bindings filled in; the statement with its placeholders is all that is kept.
        if ($e instanceof QueryException) {
            return 'SQLSTATE['.$e->getCode().'] (SQL: '.self::scrub($e->getSql()).')';
        }

        return self::scrub($e->getMessage());
    }

    /**
     * The exception as text, like (string) $e, without the values in the messages and without the arguments of the calls.
     */
    public static function describe(Throwable $e): string
    {
        $lines = [];

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $lines[] = sprintf('%s: %s in %s:%d', $current::class, self::message($current), $current->getFile(), $current->getLine());
            $lines[] = "Stack trace:\n".self::traceAsString($current);
        }

        return implode("\n\nNext ", $lines);
    }

    /**
     * A copy of the exception that is safe to hand to a log formatter: the same class name, place and trace, scrubbed messages.
     */
    public static function throwable(Throwable $e): Throwable
    {
        return new ScrubbedThrowable($e);
    }

    /**
     * A log context with its text scrubbed, its exceptions made safe and its models reduced to a class and an id.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public static function context(array $context, int $depth = 0): array
    {
        foreach ($context as $key => $value) {
            $context[$key] = self::value($value, $depth);
        }

        return $context;
    }

    private static function value(mixed $value, int $depth): mixed
    {
        return match (true) {
            is_string($value) => self::scrub($value),
            $value instanceof Throwable => self::throwable($value),
            $value instanceof Model => $value::class.'#'.$value->getKey(),
            is_array($value) => $depth > 6 ? self::MASK : self::context($value, $depth + 1),
            // Anything that prints itself as data (a collection, a resource) can carry decrypted attributes.
            $value instanceof Arrayable, $value instanceof JsonSerializable => $value::class,
            default => $value,
        };
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        usort($values, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $values;
    }

    private static function traceAsString(Throwable $e): string
    {
        $out = [];

        foreach ($e->getTrace() as $i => $frame) {
            $out[] = sprintf('#%d %s(%s): %s%s%s()', $i, $frame['file'] ?? '[internal function]', $frame['line'] ?? '', $frame['class'] ?? '', $frame['type'] ?? '', $frame['function']);
        }

        return implode("\n", $out);
    }
}
