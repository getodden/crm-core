<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Events\UserMentioned;
use Odden\Core\Models\Mention;
use Odden\Core\Support\Mentions;

class RecordMentionsAction
{
    /**
     * Keep the people mentioned in a note or comment in step with what its author picked, and fire UserMentioned once for each
     * person newly mentioned. Ids that are not teammates (Mentions::teammates) are ignored, so a forged id mentions no one.
     *
     * Each mention keeps its label, the name as written in the text, so a person renamed later is still found by the "@Name"
     * written before. Saving the same mentions twice at once records them once and tells each person once.
     *
     * @param  Model  $mentionable  The note or comment.
     * @param  Model  $subject  The record it is on.
     * @param  array<array-key, mixed>  $userIds  The people picked in the editor.
     * @param  array<array-key, string>  $labels  The name each was written with, by user id; the person's current name when missing.
     * @return list<int> The people newly mentioned.
     */
    public function execute(Model $mentionable, Model $subject, array $userIds, ?int $actorId, array $labels = []): array
    {
        $names = Mentions::names($userIds);
        $allowed = array_values(array_filter(
            array_values(array_unique(array_map('intval', array_filter($userIds, 'is_numeric')))),
            fn (int $userId): bool => isset($names[$userId]),
        ));

        $mentions = Mention::query()
            ->where('mentionable_type', $mentionable->getMorphClass())
            ->where('mentionable_id', $mentionable->getKey());

        $existing = (clone $mentions)->pluck('label', 'user_id')->all();
        $removed = array_values(array_diff(array_map('intval', array_keys($existing)), $allowed));

        if ($removed !== []) {
            (clone $mentions)->whereIn('user_id', $removed)->delete();
        }

        $added = [];

        foreach ($allowed as $userId) {
            $label = filled($labels[$userId] ?? null) ? (string) $labels[$userId] : $names[$userId];

            if (array_key_exists($userId, $existing)) {
                if ($existing[$userId] !== $label) {
                    (clone $mentions)->where('user_id', $userId)->update(['label' => $label]);
                }

                continue;
            }

            // A second save racing this one may have just recorded the same person: the unique index keeps one row, and only the
            // save that wrote it tells them.
            $inserted = Mention::query()->insertOrIgnore([
                'mentionable_type' => $mentionable->getMorphClass(),
                'mentionable_id' => $mentionable->getKey(),
                'user_id' => $userId,
                'label' => $label,
                'mentioned_by' => $actorId,
                'created_at' => now(),
            ]);

            if ($inserted > 0) {
                $added[] = $userId;
            }
        }

        foreach ($added as $userId) {
            event(new UserMentioned($userId, $actorId, $mentionable, $subject));
        }

        return $added;
    }
}
