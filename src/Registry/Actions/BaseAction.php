<?php

declare(strict_types=1);

namespace Odden\Core\Registry\Actions;

use Odden\Core\Registry\Actor;
use Odden\Core\Registry\CrmAction;
use Odden\Core\Registry\Risk;

/**
 * Defaults shared by the registry actions: Write risk, no sensitive fields, and no checks beyond the host's ActionGate.
 */
abstract class BaseAction implements CrmAction
{
    public function risk(): Risk
    {
        return Risk::Write;
    }

    public function sensitive(): array
    {
        return [];
    }

    public function authorize(Actor $actor, array $input): bool
    {
        return true;
    }
}
