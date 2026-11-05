<?php

declare(strict_types=1);

namespace Odden\Core\Screens;

use Illuminate\Database\Eloquent\Model;

interface ScreenTenantParameters
{
    /**
     * @return array<string, int|string>
     */
    public function for(Model $tenant): array;
}
