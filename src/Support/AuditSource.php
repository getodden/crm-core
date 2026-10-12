<?php

declare(strict_types=1);

namespace Odden\Core\Support;

/**
 * The `source` recorded on property history. It is decided by the server, never by a request
 * header: `web` for HTTP requests and `console` for commands and queued jobs, unless code wraps
 * its changes in using(), for example an importer or an integration sync.
 */
final class AuditSource
{
    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ?? (app()->runningInConsole() ? 'console' : 'web');
    }

    /**
     * Record changes made inside the callback with this source.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(string $source, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $source;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }
}
