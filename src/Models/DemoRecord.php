<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A row in the list of records that came from the sample data. The list lives apart from the records, so any module's table can be
 * marked without a column of its own, and removing the sample data never touches a record that is not on the list.
 *
 * @property int $id
 * @property string $model_type
 * @property string $model_id
 * @property Carbon $created_at
 */
class DemoRecord extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('odden-core.tables.demo_records', 'odden_demo_records');
    }

    public static function mark(Model $model): void
    {
        static::query()->firstOrCreate([
            'model_type' => $model->getMorphClass(),
            'model_id' => (string) $model->getKey(),
        ]);
    }

    public static function isDemo(Model $model): bool
    {
        return static::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', (string) $model->getKey())
            ->exists();
    }

    public static function exist(): bool
    {
        return static::query()->exists();
    }

    /**
     * Delete every marked record, newest first so a record goes before the ones it was made from, and clear the list.
     *
     * @return int The number of records deleted.
     */
    public static function purge(): int
    {
        $deleted = 0;

        foreach (static::query()->orderByDesc('id')->cursor() as $row) {
            $class = Model::getActualClassNameForMorph($row->model_type);

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            // Scopes are lifted so a trashed record, or one outside the current tenant's view, still goes.
            $model = $class::withoutGlobalScopes()->whereKey($row->model_id)->first();

            if ($model !== null) {
                // Activity the app logged on a sample record afterwards (a skipped email, a note) goes with it.
                if (method_exists($model, 'activities')) {
                    $model->activities()->delete();
                }

                in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model->forceDelete() : $model->delete();
                $deleted++;
            }
        }

        static::query()->delete();

        return $deleted;
    }
}
