<?php

declare(strict_types=1);

namespace Odden\Core\Traits;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Odden\Core\Encryption\Casts\EncryptedAmount;
use Odden\Core\Encryption\FieldEncrypter;
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

            // A figure a workspace keeps encrypted lives in two columns (the plain one stays 0.00): its history is the figure itself.
            $amounts = self::auditAmounts($model);
            $changes = array_keys($model->getChanges());

            foreach ($amounts as $column => [$before, $after]) {
                if (abs($before - $after) >= 0.005) {
                    $record($column, number_format($before, 2, '.', ''), number_format($after, 2, '.', ''));
                }
            }

            foreach ($changes as $key) {
                if (in_array($key, self::$auditIgnoredAttributes, true) || self::isAmountColumn($model, $key)) {
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
     * The figures with an EncryptedAmount cast that this save changed: column => [before, after]. Both columns of a figure are looked at
     * together, so keeping it plain, encrypting it or converting it back is not a change; only the figure changing is.
     *
     * @return array<string, array{float, float}>
     */
    private static function auditAmounts(Model $model): array
    {
        $changed = array_keys($model->getChanges());
        $found = [];

        foreach ($model->getCasts() as $column => $cast) {
            if (! is_a(explode(':', $cast, 2)[0], EncryptedAmount::class, true)) {
                continue;
            }

            $companion = EncryptedAmount::companion($column);

            if (! in_array($column, $changed, true) && ! in_array($companion, $changed, true)) {
                continue;
            }

            $field = explode(':', $cast, 2)[1] ?? $model->getTable().'.'.$column;
            $attributes = $model->getAttributes();
            $found[$column] = [
                self::amountOf($model->getRawOriginal($column), $model->getRawOriginal($companion), $field),
                self::amountOf($attributes[$column] ?? null, $attributes[$companion] ?? null, $field),
            ];
        }

        return $found;
    }

    private static function amountOf(mixed $plain, mixed $stored, string $field): float
    {
        $encrypter = app(FieldEncrypter::class);

        return is_string($stored) && $encrypter->isEncrypted($stored)
            ? (float) $encrypter->decrypt($stored, $field)
            : (float) $plain;
    }

    private static function isAmountColumn(Model $model, string $key): bool
    {
        foreach ($model->getCasts() as $column => $cast) {
            if (is_a(explode(':', $cast, 2)[0], EncryptedAmount::class, true) && in_array($key, [$column, EncryptedAmount::companion($column)], true)) {
                return true;
            }
        }

        return false;
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
