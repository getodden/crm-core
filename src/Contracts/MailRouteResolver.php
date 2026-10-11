<?php

declare(strict_types=1);

namespace Odden\Core\Contracts;

/**
 * Answers whether a domain can receive email at all.
 *
 * The default implementation asks DNS. Bind another implementation to use a different
 * resolver, add a proxy, or keep tests offline.
 */
interface MailRouteResolver
{
    /**
     * True when the domain accepts mail, false when it provably does not (no MX and no
     * address record, or a null MX), and null when the lookup could not be completed.
     */
    public function acceptsMail(string $domain): ?bool;
}
