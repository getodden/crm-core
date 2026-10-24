<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use LogicException;
use SensitiveParameter;

/**
 * A 256-bit key and the id that names it. The id is stored with every value the key encrypts, so a value can be read with the
 * right key after the key has changed.
 *
 * It refuses to be serialized (queues, caches, sessions) and shows only its id in dumps and stack traces.
 */
final class DataKey
{
    public function __construct(public readonly string $id, #[SensitiveParameter] private readonly string $bytes)
    {
        if (strlen($bytes) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new LogicException('A data key must be exactly 32 bytes.');
        }
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * A key derived from this one for a single purpose, so the key that encrypts values is never also the key that builds the
     * search index.
     */
    public function derive(string $purpose): string
    {
        return hash_hkdf('sha256', $this->bytes, 32, 'odden:'.$purpose);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id];
    }

    public function __serialize(): array
    {
        throw new LogicException('A data key cannot be serialized.');
    }

    public function __sleep(): array
    {
        throw new LogicException('A data key cannot be serialized.');
    }
}
