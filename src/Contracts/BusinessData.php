<?php

declare(strict_types=1);

namespace Odden\Core\Contracts;

/**
 * Whether the active tenant stores its business figures (deal amounts, sales targets) encrypted.
 *
 * Off by default: amounts are summed, averaged and sorted by the database. A workspace that turns it on keeps the figures encrypted
 * like personal data, and every total, average, sort and report then adds them up in PHP (EncryptedAmounts), up to the fallback limit.
 * Self-hosted installs set `odden-core.encryption.business_data`; a multi-tenant host rebinds this contract to a per-tenant setting.
 *
 * Reading never depends on this: a value is read in whichever form it is stored in, so turning the setting on or off is safe while
 * the stored rows are converted (`odden:convert-business-data`).
 */
interface BusinessData
{
    /**
     * Whether new and changed figures are written encrypted.
     */
    public function encrypted(): bool;
}
