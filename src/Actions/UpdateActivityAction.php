<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Activity;
use Odden\Core\Support\TenantGuard;

class UpdateActivityAction
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<array-key, mixed>|null  $mentionedUserIds
     * @param  array<array-key, string>  $mentionLabels
     */
    public function execute(
        Activity $activity,
        array $attributes,
        ?int $actorId = null,
        ?array $mentionedUserIds = null,
        array $mentionLabels = [],
    ): Activity {
        app(TenantGuard::class)->assertOwnedWhenTenanted($activity);

        return DB::transaction(function () use ($activity, $attributes, $actorId, $mentionedUserIds, $mentionLabels): Activity {
            $activity->update(Arr::except($attributes, ['subject_type', 'subject_id', 'creator_id']));

            $subject = $activity->subject()->withTrashed()->first();

            if ($mentionedUserIds !== null && $subject !== null) {
                app(RecordMentionsAction::class)->execute($activity, $subject, $mentionedUserIds, $actorId, $mentionLabels);
            }

            return $activity;
        });
    }
}
