<?php

declare(strict_types=1);

namespace Odden\Core\Screens;

use Illuminate\Database\Eloquent\Model;

final class NoScreenTenantParameters implements ScreenTenantParameters
{
    public function for(Model $tenant): array
    {
        return [];
    }
}
