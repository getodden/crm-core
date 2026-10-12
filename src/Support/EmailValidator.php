<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Egulias\EmailValidator\EmailValidator as EguliasValidator;
use Egulias\EmailValidator\Validation\MultipleValidationWithAnd;
use Egulias\EmailValidator\Validation\NoRFCWarningsValidation;
use Egulias\EmailValidator\Validation\RFCValidation;
use Odden\Core\Contracts\MailRouteResolver;
use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\EmailStatusReason;

/**
 * Checks an email address without contacting its mail server.
 *
 * It looks at the address itself (syntax, typo in a well-known domain, disposable provider,
 * shared role address) and, when asked, at DNS to see whether the domain accepts mail at all.
 * It never probes the mailbox, so a "valid" result means no problem was found rather than
 * that the mailbox exists. Delivery results and confirmation clicks are what prove that.
 */
class EmailValidator
{
    /**
     * Well-known consumer domains a mistyped domain is compared against.
     *
     * @var list<string>
     */
    protected const POPULAR_DOMAINS = [
        'gmail.com',
        'yahoo.com',
        'hotmail.com',
        'outlook.com',
        'icloud.com',
        'aol.com',
        'protonmail.com',
        'live.com',
        'msn.com',
        'comcast.net',
    ];

    /**
     * Local parts that are shared mailboxes rather than a person.
     *
     * @var list<string>
     */
    protected const ROLE_LOCAL_PARTS = [
        'abuse',
        'admin',
        'administrator',
        'billing',
        'contact',
        'do-not-reply',
        'donotreply',
        'hello',
        'help',
        'hostmaster',
        'info',
        'mailer-daemon',
        'marketing',
        'no-reply',
        'noc',
        'noreply',
        'office',
        'postmaster',
        'privacy',
        'root',
        'sales',
        'security',
        'spam',
        'support',
        'team',
        'webmaster',
    ];

    public function __construct(protected MailRouteResolver $routes) {}

    /**
     * @param  bool  $checkDns  Ask DNS whether the domain accepts mail. When false the result
     *                          is never "valid", because nothing proves the domain can receive mail.
     */
    public function check(string $email, bool $checkDns = true): EmailCheck
    {
        $email = ContactLookup::normalizeEmail($email);

        $parts = $this->split($email);

        if ($parts === null || ! $this->hasValidSyntax($parts[0], $parts[1])) {
            return new EmailCheck(EmailStatus::Invalid, EmailStatusReason::Syntax);
        }

        [$local, $domain] = $parts;

        $acceptsMail = null;

        if ($checkDns) {
            // A consumer provider is known to accept mail; asking DNS about it only costs time.
            $acceptsMail = FreemailDomains::isFreemail($domain)
                ? true
                : $this->routes->acceptsMail($domain);

            if ($acceptsMail === false) {
                return new EmailCheck(EmailStatus::Invalid, EmailStatusReason::NoMailServer);
            }
        }

        $suggestion = $this->suggestDomain($domain);

        if ($suggestion !== null) {
            return new EmailCheck(EmailStatus::Risky, EmailStatusReason::Typo, $local.'@'.$suggestion);
        }

        if (DisposableEmailDomains::isDisposable($domain)) {
            return new EmailCheck(EmailStatus::Risky, EmailStatusReason::Disposable);
        }

        if ($this->isRoleAddress($local)) {
            return new EmailCheck(EmailStatus::Risky, EmailStatusReason::Role);
        }

        if (! $checkDns) {
            return new EmailCheck(EmailStatus::Unknown, EmailStatusReason::NotChecked);
        }

        if ($acceptsMail === null) {
            return new EmailCheck(EmailStatus::Unknown, EmailStatusReason::DnsUnavailable);
        }

        return new EmailCheck(EmailStatus::Valid);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    protected function split(string $email): ?array
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return null;
        }

        return [substr($email, 0, $at), strtolower(substr($email, $at + 1))];
    }

    protected function hasValidSyntax(string $local, string $domain): bool
    {
        if (strlen($local) > 64 || ! str_contains($domain, '.') || str_starts_with($domain, '.') || str_ends_with($domain, '.')) {
            return false;
        }

        // Internationalised domains are validated in their ASCII form.
        if (! mb_check_encoding($domain, 'ASCII')) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;

            if ($ascii === false) {
                return false;
            }

            $domain = $ascii;
        }

        $address = $local.'@'.$domain;

        if (strlen($address) > 254) {
            return false;
        }

        $validator = new EguliasValidator;

        return $validator->isValid($address, new MultipleValidationWithAnd([
            new RFCValidation,
            new NoRFCWarningsValidation,
        ], MultipleValidationWithAnd::STOP_ON_ERROR));
    }

    /**
     * A well-known domain this one is a single typo away from (gmial.com, gmail.con), or null.
     * Domains that are themselves well-known providers are never suggested a correction.
     */
    protected function suggestDomain(string $domain): ?string
    {
        if (FreemailDomains::isFreemail($domain) || in_array($domain, self::POPULAR_DOMAINS, true)) {
            return null;
        }

        foreach (self::POPULAR_DOMAINS as $popular) {
            if ($this->isSingleTypo($domain, $popular) && ! $this->isOtherShortName($domain, $popular)) {
                return $popular;
            }
        }

        return null;
    }

    /**
     * One edit apart: a character inserted, dropped or changed, or two neighbours swapped
     * (gmial.com). PHP's levenshtein() counts a swap as two edits, and a swap is the most
     * common way to mistype a domain.
     */
    protected function isSingleTypo(string $domain, string $popular): bool
    {
        $length = strlen($domain);
        $popularLength = strlen($popular);

        if ($domain === $popular || abs($length - $popularLength) > 1) {
            return false;
        }

        if (levenshtein($domain, $popular) === 1) {
            return true;
        }

        if ($length !== $popularLength) {
            return false;
        }

        for ($i = 0; $i < $length - 1; $i++) {
            if ($domain[$i] !== $popular[$i]) {
                return $domain[$i] === $popular[$i + 1]
                    && $domain[$i + 1] === $popular[$i]
                    && substr($domain, $i + 2) === substr($popular, $i + 2);
            }
        }

        return false;
    }

    /**
     * One edit away from a short name such as aol, msn or live is usually another real domain
     * (aon.com, aoc.com, lime.com), so for those only a typo in the rest (aol.con) counts.
     */
    protected function isOtherShortName(string $domain, string $popular): bool
    {
        $name = explode('.', $popular, 2)[0];

        return strlen($name) < 5 && explode('.', $domain, 2)[0] !== $name;
    }

    protected function isRoleAddress(string $local): bool
    {
        // support+billing@ is still the support mailbox.
        $base = explode('+', $local, 2)[0];

        /** @var list<string> $configured */
        $configured = config('odden-core.email_validation.role_local_parts', []);

        return in_array($base, [...self::ROLE_LOCAL_PARTS, ...array_map('strtolower', $configured)], true);
    }
}
