<?php

declare(strict_types=1);

namespace Odden\Core\Contracts;

use Odden\Core\Encryption\DataKey;

/**
 * Hands out the key that protects the active tenant's data at rest.
 *
 * Self-hosted installs are single-tenant and use the default provider, which derives the key from a secret in the environment.
 * A multi-tenant host rebinds this contract to a provider that keeps a separate key for each tenant (Odden Cloud wraps each
 * tenant's key with AWS KMS), so one tenant's data cannot be read with another's key.
 *
 * The key is for the tenant the request, job or command is acting for (see TenantContext). It is never stored by the code that
 * asks for it, and an implementation must not keep it anywhere that outlives the request (no shared cache, no log, no queue).
 */
interface KeyProvider
{
    /**
     * The key new values are encrypted with.
     */
    public function current(): DataKey;

    /**
     * The key an existing value was encrypted with, named by the id stored beside it, or null when this provider does not
     * know that id (which makes the value unreadable).
     */
    public function find(string $id): ?DataKey;
}
