<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Activity;
use Odden\Core\Support\TenantGuard;

class DeleteActivityAction
{
    public function execute(Activity $activity): bool
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($activity);

        return DB::transaction(fn (): bool => (bool) $activity->delete());
    }
}
