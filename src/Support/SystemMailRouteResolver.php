<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Support\Facades\Cache;
use Odden\Core\Contracts\MailRouteResolver;
use Throwable;

/**
 * Resolves mail routes through the system DNS resolver, caching definite answers per domain.
 *
 * A list of 100,000 addresses usually has a few thousand distinct domains, so the cache turns
 * an import into a few thousand lookups. A lookup that failed (a timeout, a resolver error) is
 * remembered only briefly, so an unreachable domain doesn't stall every contact created for it
 * but is retried soon after.
 *
 * Lookups go straight to the nameservers through DnsClient, so they end after
 * `email_validation.dns_timeout_seconds` however slow a nameserver is.
 */
class SystemMailRouteResolver implements MailRouteResolver
{
    public function acceptsMail(string $domain): ?bool
    {
        $domain = $this->asciiDomain($domain);

        if ($domain === '') {
            return false;
        }

        $ttl = (int) config('odden-core.email_validation.dns_cache_minutes', 1440);
        $key = 'odden-core:mail-route:'.$domain;

        $cached = $this->remembered($key);

        if ($cached !== null) {
            return $cached;
        }

        $failureTtl = (int) config('odden-core.email_validation.dns_failure_cache_minutes', 5);

        if ($failureTtl > 0 && $this->remembered($key.':failed') !== null) {
            return null;
        }

        $result = $this->lookup($domain);

        if ($result !== null && $ttl > 0) {
            $this->remember($key, $result, $ttl);
        } elseif ($result === null && $failureTtl > 0) {
            $this->remember($key.':failed', true, $failureTtl);
        }

        return $result;
    }

    /**
     * RFC 5321 section 5.1: use the MX records, and only when there are none fall back to the
     * domain's own address records. A single MX of "." is a null MX (RFC 7505): the domain
     * states it does not accept mail.
     *
     * The whole lookup gets `dns_timeout_seconds`; past that the answer is unknown (null), which
     * never marks an address invalid.
     */
    protected function lookup(string $domain): ?bool
    {
        $timeout = (float) config('odden-core.email_validation.dns_timeout_seconds', 2);
        $client = $timeout > 0 ? $this->dnsClient() : null;

        if ($client === null) {
            return $this->lookupWithSystemResolver($domain, $timeout);
        }

        $deadline = microtime(true) + $timeout;

        $mx = $client->query($domain, DnsClient::TYPE_MX, $deadline);

        if ($mx === null) {
            return null;
        }

        if ($mx !== []) {
            return $this->hasMailExchanger($mx);
        }

        foreach ([DnsClient::TYPE_A, DnsClient::TYPE_AAAA] as $type) {
            $addresses = $client->query($domain, $type, $deadline);

            if ($addresses === null) {
                return null;
            }

            if ($addresses !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The client for the configured nameservers, or those in /etc/resolv.conf; null when there
     * are none to ask.
     */
    protected function dnsClient(): ?DnsClient
    {
        /** @var list<string> $configured */
        $configured = array_values(array_filter((array) config('odden-core.email_validation.dns_nameservers', []), is_string(...)));

        $nameservers = $configured !== [] ? $configured : DnsClient::systemNameservers();

        return $nameservers === [] ? null : new DnsClient($nameservers);
    }

    /**
     * The lookup through PHP's dns_get_record(), for when no nameserver is known. It can't be
     * interrupted, so an answer that took longer than the timeout is treated as unknown, and
     * cached as a failure, so the next contacts for the domain don't wait for it too.
     */
    protected function lookupWithSystemResolver(string $domain, float $timeout): ?bool
    {
        $started = microtime(true);
        $tookTooLong = fn (): bool => $timeout > 0 && microtime(true) - $started > $timeout;

        $mx = @dns_get_record($domain, DNS_MX);

        if ($mx === false || $tookTooLong()) {
            return null;
        }

        if ($mx !== []) {
            return $this->hasMailExchanger(array_map(fn (array $record): string => (string) ($record['target'] ?? ''), $mx));
        }

        $addresses = @dns_get_record($domain, DNS_A | DNS_AAAA);

        if ($addresses === false || $tookTooLong()) {
            return null;
        }

        return $addresses !== [];
    }

    /**
     * @param  list<string>  $targets
     */
    private function hasMailExchanger(array $targets): bool
    {
        foreach ($targets as $target) {
            if (rtrim($target, '.') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The cache only saves time, so a cache store that is down must not stop a contact from
     * being created; the lookup just runs again.
     */
    protected function remembered(string $key): ?bool
    {
        try {
            $value = Cache::get($key);
        } catch (Throwable) {
            return null;
        }

        return is_bool($value) ? $value : null;
    }

    protected function remember(string $key, bool $value, int $minutes): void
    {
        try {
            Cache::put($key, $value, now()->addMinutes($minutes));
        } catch (Throwable) {
            // Not caching is harmless.
        }
    }

    protected function asciiDomain(string $domain): string
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));

        if ($domain !== '' && ! mb_check_encoding($domain, 'ASCII') && function_exists('idn_to_ascii')) {
            $converted = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            return $converted === false ? '' : $converted;
        }

        return $domain;
    }
}
