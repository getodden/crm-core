<?php

declare(strict_types=1);

namespace Odden\Core\Support;

/**
 * A minimal DNS client over UDP that gives up at a deadline.
 *
 * PHP's dns_get_record() has no timeout: it waits as long as the system resolver does (by
 * default 5 seconds per attempt, per nameserver, retried), and the resolver options that would
 * shorten that come from /etc/resolv.conf or the RES_OPTIONS environment variable, which PHP can't
 * set per call and not every C library reads. This client sends one query to the configured
 * nameservers itself, so a slow nameserver costs at most the time it is given.
 *
 * It asks only what SystemMailRouteResolver needs: whether a name has MX, A or AAAA records,
 * and the MX targets. A truncated answer is treated as unanswered rather than retried over TCP.
 *
 * @internal
 */
final class DnsClient
{
    public const int TYPE_A = 1;

    public const int TYPE_MX = 15;

    public const int TYPE_AAAA = 28;

    private const int RCODE_NXDOMAIN = 3;

    /**
     * @param  list<string>  $nameservers  Addresses as `host`, `host:port`, `[ipv6]:port` or a bare IPv6 address.
     */
    public function __construct(private readonly array $nameservers) {}

    /**
     * The nameservers listed in /etc/resolv.conf (or the given file), in order.
     *
     * @return list<string>
     */
    public static function systemNameservers(string $path = '/etc/resolv.conf'): array
    {
        if (! is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $nameservers = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*nameserver\s+(\S+)/', $line, $matches) === 1
                && ! str_contains($matches[1], '%')
                && filter_var($matches[1], FILTER_VALIDATE_IP) !== false) {
                $nameservers[] = $matches[1];
            }
        }

        return $nameservers;
    }

    /**
     * Records of the given type for the domain: for MX each record's target (an empty string
     * for a null MX), for A and AAAA an empty string per record. An empty list when the name
     * has no such record or doesn't exist; null when no nameserver answered before the
     * deadline (a Unix timestamp with microseconds) or the answer couldn't be used.
     *
     * @return list<string>|null
     */
    public function query(string $domain, int $type, float $deadline): ?array
    {
        $id = random_int(0, 0xFFFF);
        $packet = $this->buildQuery($id, $domain, $type);

        if ($packet === null) {
            return null;
        }

        foreach ($this->nameservers as $index => $nameserver) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return null;
            }

            // Leave the later nameservers their share of the time.
            $budget = $remaining / (count($this->nameservers) - $index);

            $response = $this->exchange($nameserver, $packet, $id, $budget);

            if ($response === null) {
                continue;
            }

            return $this->parseResponse($response, $id, $type);
        }

        return null;
    }

    /**
     * @internal Public for tests.
     */
    public function buildQuery(int $id, string $domain, int $type): ?string
    {
        $name = '';

        foreach (explode('.', trim($domain, '.')) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return null;
            }

            $name .= chr(strlen($label)).$label;
        }

        if (strlen($name) > 254) {
            return null;
        }

        // Header: id, flags (recursion desired), one question, no other records.
        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0).$name."\0".pack('nn', $type, 1);
    }

    /**
     * The records of the asked type in a response, as query() returns them.
     *
     * @return list<string>|null
     *
     * @internal Public for tests.
     */
    public function parseResponse(string $response, int $id, int $type): ?array
    {
        if (strlen($response) < 12) {
            return null;
        }

        /** @var array{id: int, flags: int, qd: int, an: int} $header */
        $header = unpack('nid/nflags/nqd/nan', $response);

        $isResponse = ($header['flags'] & 0x8000) !== 0;
        $truncated = ($header['flags'] & 0x0200) !== 0;
        $rcode = $header['flags'] & 0x000F;

        if ($header['id'] !== $id || ! $isResponse || $truncated) {
            return null;
        }

        if ($rcode === self::RCODE_NXDOMAIN) {
            return [];
        }

        if ($rcode !== 0) {
            return null;
        }

        $offset = 12;

        for ($i = 0; $i < $header['qd']; $i++) {
            if ($this->readName($response, $offset) === null) {
                return null;
            }

            $offset += 4;
        }

        $records = [];

        for ($i = 0; $i < $header['an']; $i++) {
            if ($this->readName($response, $offset) === null || strlen($response) < $offset + 10) {
                return null;
            }

            /** @var array{type: int, length: int} $fields */
            $fields = unpack('ntype/nclass/Nttl/nlength', $response, $offset);
            $offset += 10;
            $dataOffset = $offset;
            $offset += $fields['length'];

            if (strlen($response) < $offset) {
                return null;
            }

            if ($fields['type'] !== $type) {
                // A CNAME on the way to the answer, for example.
                continue;
            }

            if ($type === self::TYPE_MX) {
                $targetOffset = $dataOffset + 2;
                $target = $this->readName($response, $targetOffset);

                if ($target === null) {
                    return null;
                }

                $records[] = $target;
            } else {
                $records[] = '';
            }
        }

        return $records;
    }

    /**
     * Send the query and wait for the matching answer until the time runs out.
     */
    private function exchange(string $nameserver, string $packet, int $id, float $seconds): ?string
    {
        $socket = @stream_socket_client($this->socketAddress($nameserver), $errorCode, $errorMessage, $seconds);

        if ($socket === false) {
            return null;
        }

        try {
            if (@fwrite($socket, $packet) !== strlen($packet)) {
                return null;
            }

            $deadline = microtime(true) + $seconds;

            while (($remaining = $deadline - microtime(true)) > 0) {
                $read = [$socket];
                $write = null;
                $except = null;

                $seconds = (int) floor($remaining);
                $microseconds = (int) (($remaining - $seconds) * 1_000_000);

                if (@stream_select($read, $write, $except, $seconds, $microseconds) !== 1) {
                    return null;
                }

                $response = @fread($socket, 65535);

                // An ICMP error (nothing listening) surfaces as a failed read.
                if ($response === false || $response === '') {
                    return null;
                }

                // Ignore stray datagrams that don't answer this query.
                if (strlen($response) >= 2 && (ord($response[0]) << 8 | ord($response[1])) === $id) {
                    return $response;
                }
            }

            return null;
        } finally {
            fclose($socket);
        }
    }

    private function socketAddress(string $nameserver): string
    {
        if (filter_var($nameserver, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return "udp://[{$nameserver}]:53";
        }

        if (str_starts_with($nameserver, '[') || str_contains($nameserver, ':')) {
            return "udp://{$nameserver}";
        }

        return "udp://{$nameserver}:53";
    }

    /**
     * Read a possibly compressed name starting at $offset, and move $offset past it.
     */
    private function readName(string $message, int &$offset): ?string
    {
        $labels = [];
        $position = $offset;
        $jumped = false;
        $jumps = 0;
        $length = strlen($message);

        while (true) {
            if ($position >= $length) {
                return null;
            }

            $byte = ord($message[$position]);

            if ($byte === 0) {
                $position++;

                break;
            }

            if (($byte & 0xC0) === 0xC0) {
                if ($position + 1 >= $length || ++$jumps > 64) {
                    return null;
                }

                $pointer = (($byte & 0x3F) << 8) | ord($message[$position + 1]);

                if (! $jumped) {
                    $offset = $position + 2;
                    $jumped = true;
                }

                $position = $pointer;

                continue;
            }

            if ($position + 1 + $byte > $length) {
                return null;
            }

            $labels[] = substr($message, $position + 1, $byte);
            $position += 1 + $byte;
        }

        if (! $jumped) {
            $offset = $position;
        }

        return implode('.', $labels);
    }
}
