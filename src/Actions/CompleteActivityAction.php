<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Models\Activity;

class CompleteActivityAction
{
    public function execute(Activity $activity): Activity
    {
        $activity->update([
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
        ]);

        return $activity;
    }
}
