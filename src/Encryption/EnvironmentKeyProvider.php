<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Illuminate\Contracts\Config\Repository;
use Odden\Core\Contracts\KeyProvider;
use Odden\Core\Contracts\TenantContext;
use RuntimeException;

/**
 * The default key provider: one secret from the environment, with a separate key derived from it for each tenant.
 *
 * The secret is `odden-core.encryption.key` (ODDEN_ENCRYPTION_KEY, made with `php artisan odden:encryption-key`). When it is not
 * set the application key is used, which works out of the box but ties the data to APP_KEY: changing APP_KEY makes the data
 * unreadable, so set a key of its own for anything that matters. To change the secret, put the old one in
 * `odden-core.encryption.previous_keys`: values written with it stay readable, and new values use the new one.
 */
final class EnvironmentKeyProvider implements KeyProvider
{
    /** @var array<string, DataKey> Keys already derived in this request, by key id and tenant. */
    private array $derived = [];

    public function __construct(private readonly Repository $config, private readonly TenantContext $tenant) {}

    public function current(): DataKey
    {
        return $this->derive($this->secret($this->config->get('odden-core.encryption.key')));
    }

    public function find(string $id): ?DataKey
    {
        foreach ([$this->config->get('odden-core.encryption.key'), ...(array) $this->config->get('odden-core.encryption.previous_keys', [])] as $candidate) {
            $key = $this->derive($this->secret($candidate));

            if (hash_equals($key->id, $id)) {
                return $key;
            }
        }

        return null;
    }

    private function derive(string $secret): DataKey
    {
        $tenant = $this->tenant->id();
        $cacheKey = hash('sha256', $secret."\0".(string) $tenant);

        return $this->derived[$cacheKey] ??= new DataKey(
            substr(hash('sha256', 'id'."\0".$secret), 0, 12),
            hash_hkdf('sha256', $secret, 32, 'odden:data:'.($tenant === null ? 'install' : 'tenant:'.$tenant)),
        );
    }

    private function secret(mixed $configured): string
    {
        $value = is_string($configured) && trim($configured) !== '' ? trim($configured) : (string) $this->config->get('app.key');

        if (str_starts_with($value, 'base64:')) {
            $value = base64_decode(substr($value, 7), true) ?: '';
        }

        if (strlen($value) < 16) {
            throw new RuntimeException('No encryption key is set: run `php artisan odden:encryption-key` and put the result in ODDEN_ENCRYPTION_KEY.');
        }

        return $value;
    }
}
