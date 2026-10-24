<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Odden\Core\Encryption\Casts\EncryptedJson;
use Odden\Core\Encryption\Casts\EncryptedString;
use Odden\Core\Encryption\Index\FallbackLimit;
use Odden\Core\Encryption\Index\TooManyToFilter;
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
            // A custom object can hold anything, and its name usually repeats one of its properties, so both are stored encrypted whole
            // (not value by value, as the contact, company and deal properties are).
            'name' => EncryptedString::class.':custom_object_records.name',
            'properties' => EncryptedJson::class.':custom_object_records.properties',
        ];
    }

    /**
     * Records whose property has this value. The values are stored encrypted, so the records are read, decrypted and compared in PHP
     * (up to the fallback limit); narrow the query first with a plain filter such as the definition.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     *
     * @throws TooManyToFilter
     */
    public function scopeWhereProperty(Builder $query, string $name, mixed $value): Builder
    {
        $limit = app(FallbackLimit::class)->records();
        $candidates = (clone $query)->limit($limit + 1)->get();

        if ($candidates->count() > $limit) {
            throw new TooManyToFilter($candidates->count(), $limit);
        }

        return $query->whereKey($candidates->filter(fn (self $record): bool => data_get($record->properties ?? [], $name) == $value)->modelKeys());
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
