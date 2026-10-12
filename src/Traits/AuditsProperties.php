<?php

declare(strict_types=1);

namespace Odden\Core\Traits;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Odden\Core\Models\PropertyHistory;
use Odden\Core\Support\AuditSource;

trait AuditsProperties
{
    /**
     * Columns the system maintains on its own; changes to them are not history.
     *
     * @var list<string>
     */
    protected static array $auditIgnoredAttributes = [
        'updated_at',
        'deleted_at',
        'remember_token',
        'email_checked_at',
        'lead_score_updated_at',
        'last_health_calculated_at',
    ];

    /**
     * Boot the trait to track property updates. History is written once the update has been
     * saved, so a failed save leaves none behind. Old and new values are both read through the
     * model's casts and written the same way, so a date or a decimal reads alike on both sides.
     */
    public static function bootAuditsProperties(): void
    {
        static::updated(function (Model $model): void {
            $userId = auth()->id();
            $source = AuditSource::current();

            $record = function (string $name, mixed $old, mixed $new) use ($model, $userId, $source): void {
                PropertyHistory::create([
                    'auditable_type' => $model->getMorphClass(),
                    'auditable_id' => $model->getKey(),
                    'property_name' => $name,
                    'old_value' => self::auditValue($model, $old),
                    'new_value' => self::auditValue($model, $new),
                    'user_id' => $userId,
                    'source' => $source,
                    'created_at' => now(),
                ]);
            };

            foreach (array_keys($model->getChanges()) as $key) {
                if (in_array($key, self::$auditIgnoredAttributes, true)) {
                    continue;
                }

                $oldValue = $model->getOriginal($key);
                $newValue = $model->getAttribute($key);

                if ($key === 'properties') {
                    // Diff the dynamic properties array
                    $oldProperties = self::auditProperties($oldValue);
                    $newProperties = self::auditProperties($newValue);

                    foreach (array_unique(array_merge(array_keys($oldProperties), array_keys($newProperties))) as $propKey) {
                        $oldVal = $oldProperties[$propKey] ?? null;
                        $newVal = $newProperties[$propKey] ?? null;

                        if ($oldVal !== $newVal) {
                            $record((string) $propKey, $oldVal, $newVal);
                        }
                    }

                    continue;
                }

                $record($key, $oldValue, $newValue);
            }
        });
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function auditProperties(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    private static function auditValue(Model $model, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $model->fromDateTime($value),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /**
     * History of property changes on this record.
     *
     * @return MorphMany<PropertyHistory, $this>
     */
    public function propertyHistory(): MorphMany
    {
        return $this->morphMany(PropertyHistory::class, 'auditable')->latest('created_at');
    }
}
