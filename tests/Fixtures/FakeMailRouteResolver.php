<?php

declare(strict_types=1);

namespace Odden\Core\Tests\Fixtures;

use Odden\Core\Contracts\MailRouteResolver;

/**
 * Answers from the domain's name instead of DNS: ".invalid" has no mail server, ".unreachable"
 * cannot be looked up, everything else accepts mail.
 */
class FakeMailRouteResolver implements MailRouteResolver
{
    public static int $lookups = 0;

    public function acceptsMail(string $domain): ?bool
    {
        self::$lookups++;

        return match (true) {
            str_ends_with($domain, '.invalid') => false,
            str_ends_with($domain, '.unreachable') => null,
            default => true,
        };
    }
}
