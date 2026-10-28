<?php

declare(strict_types=1);

namespace Odden\Core\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Odden\Core\Models\Mention;

/**
 * A note or comment people can be mentioned in. Its mentions go when it is deleted.
 */
trait HasMentions
{
    public static function bootHasMentions(): void
    {
        static::deleted(function (Model $record): void {
            // A soft-deleted record can come back, with its mentions.
            if (method_exists($record, 'isForceDeleting') && ! $record->isForceDeleting()) {
                return;
            }

            Mention::query()->where('mentionable_type', $record->getMorphClass())->where('mentionable_id', $record->getKey())->delete();
        });
    }

    /**
     * The people mentioned in it.
     *
     * @return MorphMany<Mention, $this>
     */
    public function mentions(): MorphMany
    {
        return $this->morphMany(Mention::class, 'mentionable');
    }
}
