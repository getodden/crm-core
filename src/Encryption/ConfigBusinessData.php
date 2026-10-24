<?php

declare(strict_types=1);

namespace Odden\Core\Encryption;

use Odden\Core\Contracts\BusinessData;

/**
 * The default: one setting for the whole install (`odden-core.encryption.business_data`, ODDEN_ENCRYPT_BUSINESS_DATA).
 */
final class ConfigBusinessData implements BusinessData
{
    public function encrypted(): bool
    {
        return (bool) config('odden-core.encryption.business_data', false);
    }
}
