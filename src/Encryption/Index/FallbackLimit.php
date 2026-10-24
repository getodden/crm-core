<?php

declare(strict_types=1);

namespace Odden\Core\Encryption\Index;

/**
 * The most records the decrypt-and-filter fallback will read in one go. The default is a setting; a host with a plan limit on
 * contacts rebinds this class to one that returns the limit of the active plan.
 */
class FallbackLimit
{
    public function records(): int
    {
        return max(1, (int) config('odden-core.encryption.fallback_limit', 10000));
    }
}
