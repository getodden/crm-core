<?php

declare(strict_types=1);

namespace Odden\Core\Areas;

final class AllowAllAreaAccess implements AreaAccess
{
    public function allows(DataArea $area, int|string|null $userId = null): bool
    {
        return true;
    }

    public function mayReveal(DataArea $area, int|string|null $userId = null): bool
    {
        return false;
    }
}
