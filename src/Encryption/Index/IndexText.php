<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Index;

/**
 * How a value is reduced before it is hashed into the search index, so equal values give equal hashes: an email address is
 * lower-cased, a phone number is its digits, a name is split into lower-cased words and the leading letters of each word.
 */
final class IndexText
{
    /** The longest prefix that is indexed for a word. A longer search term is looked up as a whole word. */
    public const int MAX_PREFIX = 24;

    /** The shortest prefix that is indexed. A one-letter search term is looked up as a whole word. */
    public const int MIN_PREFIX = 2;

    public const string EMAIL = 'email';

    public const string DIGITS = 'digits';

    public const string TEXT = 'text';

    public static function normalize(string $kind, string $value): string
    {
        return match ($kind) {
            self::EMAIL => mb_strtolower(trim($value)),
            self::DIGITS => (string) preg_replace('/\D+/', '', $value),
            default => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value))),
        };
    }

    /**
     * The lower-cased words of a text (letters and digits; everything else separates words).
     *
     * @return list<string>
     */
    public static function words(string $value): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($words === false ? [] : $words));
    }

    /**
     * The leading letters of every word, from MIN_PREFIX to MAX_PREFIX characters (the whole word when it is shorter).
     *
     * @return list<string>
     */
    public static function prefixes(string $value): array
    {
        $prefixes = [];

        foreach (self::words($value) as $word) {
            $length = mb_strlen($word);

            for ($i = self::MIN_PREFIX; $i <= min($length, self::MAX_PREFIX); $i++) {
                $prefixes[mb_substr($word, 0, $i)] = true;
            }
        }

        return array_map('strval', array_keys($prefixes));
    }

    /**
     * How a typed search word is looked up: [kind, value]. Two to 24 letters match the start of a word; one letter, or more than
     * 24, matches a whole word.
     *
     * @return array{0: string, 1: string}
     */
    public static function searchTerm(string $word): array
    {
        $length = mb_strlen($word);

        return $length >= self::MIN_PREFIX && $length <= self::MAX_PREFIX ? ['prefix', $word] : ['word', $word];
    }
}
