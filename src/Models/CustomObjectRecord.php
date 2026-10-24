<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Odden\Core\Encryption\Casts\CustomProperties;
use Odden\Core\Support\UserModel;
use Odden\Core\Traits\AuditsProperties;
use Odden\Core\Traits\BelongsToTeam;
use Odden\Core\Traits\HasActivities;
use Odden\Core\Traits\HasAssociations;
use Odden\Core\Traits\HasCustomProperties;

/**
 * @property int $id
 * @property int $definition_id
 * @property string $name
 * @property array<string, mixed>|null $properties
 * @property int|null $owner_id
 * @property int|null $team_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read CustomObjectDefinition $definition
 */
class CustomObjectRecord extends Model
{
    use AuditsProperties;
    use BelongsToTeam;
    use HasActivities;
    use HasAssociations;
    use HasCustomProperties;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'definition_id',
        'name',
        'properties',
        'owner_id',
        'team_id',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-core.tables.custom_object_records', 'odden_custom_object_records');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => CustomProperties::class,
        ];
    }

    /**
     * The schema definition this record belongs to.
     *
     * @return BelongsTo<CustomObjectDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(
            CustomObjectDefinition::class,
            'definition_id'
        );
    }

    /**
     * The assigned record owner.
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'owner_id');
    }
}
