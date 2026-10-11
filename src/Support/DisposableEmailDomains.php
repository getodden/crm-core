<?php

declare(strict_types=1);

namespace Odden\Core\Support;

/**
 * Throwaway-inbox providers. This is a starter list of the best-known services, not an
 * exhaustive one; add more through `odden-core.email_validation.disposable_domains`.
 */
class DisposableEmailDomains
{
    /**
     * @var list<string>
     */
    protected static array $domains = [
        '10minutemail.com',
        '10minutemail.net',
        '1secmail.com',
        '1secmail.net',
        '1secmail.org',
        'burnermail.io',
        'crazymailing.com',
        'discard.email',
        'dispostable.com',
        'dropmail.me',
        'emailfake.com',
        'emailondeck.com',
        'fakeinbox.com',
        'fakemail.net',
        'getairmail.com',
        'getnada.com',
        'grr.la',
        'guerrillamail.biz',
        'guerrillamail.com',
        'guerrillamail.de',
        'guerrillamail.info',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamailblock.com',
        'inboxkitten.com',
        'jetable.org',
        'maildrop.cc',
        'mailcatch.com',
        'mailforspam.com',
        'mailinator.com',
        'mailnesia.com',
        'mailsac.com',
        'mintemail.com',
        'moakt.com',
        'mohmal.com',
        'mytemp.email',
        'pokemail.net',
        'sharklasers.com',
        'spam4.me',
        'spamgourmet.com',
        'temp-mail.org',
        'tempail.com',
        'tempinbox.com',
        'tempmail.com',
        'tempr.email',
        'throwawaymail.com',
        'tmail.ws',
        'tmpmail.net',
        'tmpmail.org',
        'trashmail.com',
        'trbvm.com',
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
    ];

    /**
     * Whether the domain, or any parent domain of it, is a disposable provider.
     */
    public static function isDisposable(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        /** @var list<string> $configured */
        $configured = config('odden-core.email_validation.disposable_domains', []);
        $known = [...static::$domains, ...array_map('strtolower', $configured)];

        while ($domain !== '') {
            if (in_array($domain, $known, true)) {
                return true;
            }

            $dot = strpos($domain, '.');

            if ($dot === false) {
                return false;
            }

            $domain = substr($domain, $dot + 1);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return static::$domains;
    }
}
