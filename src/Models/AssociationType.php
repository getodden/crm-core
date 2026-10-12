<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Odden\Core\Enums\AssociationCardinality;
use Odden\Core\Exceptions\SystemAssociationTypeException;
use Odden\Core\Traits\BelongsToTeam;
use Odden\Core\Traits\HasNamePerTeam;

/**
 * @property int $id
 * @property string $name
 * @property string $label
 * @property string|null $reverse_label
 * @property AssociationCardinality $cardinality
 * @property string|null $from_record_type
 * @property string|null $to_record_type
 * @property bool $is_system
 * @property int|null $team_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AssociationType extends Model
{
    use BelongsToTeam;
    use HasNamePerTeam;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'label',
        'reverse_label',
        'cardinality',
        'from_record_type',
        'to_record_type',
        'is_system',
        'team_id',
    ];

    /**
     * System types are built in: they can't be deleted, and their name and record types can't change.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $type): void {
            if ($type->is_system) {
                throw new SystemAssociationTypeException("Association type [{$type->name}] is a system type and can't be deleted.");
            }
        });

        static::updating(function (self $type): void {
            if ($type->getOriginal('is_system') && $type->isDirty(['name', 'from_record_type', 'to_record_type', 'is_system'])) {
                throw new SystemAssociationTypeException("Association type [{$type->getOriginal('name')}] is a system type; only its labels and cardinality can change.");
            }
        });
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-core.tables.association_types', 'odden_association_types');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cardinality' => AssociationCardinality::class,
            'is_system' => 'boolean',
        ];
    }

    /**
     * Associations of this type.
     *
     * @return HasMany<Association, $this>
     */
    public function associations(): HasMany
    {
        return $this->hasMany(
            Association::class,
            'association_type_id'
        );
    }

    /**
     * Resolve the appropriate label based on the viewing record. Pass the association when both
     * ends can be the same type (company to company), where only the link knows which side the
     * record is on.
     */
    public function getLabelFor(Model $record, ?Association $association = null): string
    {
        if ($this->reverse_label === null) {
            return $this->label;
        }

        if ($association !== null) {
            $isChild = $association->child_type === $record->getMorphClass()
                && (string) $association->child_id === (string) $record->getKey();

            return $isChild ? $this->reverse_label : $this->label;
        }

        if ($this->to_record_type !== null && $record->getMorphClass() === $this->to_record_type) {
            return $this->reverse_label;
        }

        return $this->label;
    }
}
