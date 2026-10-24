<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Areas\DataArea;
use Odden\Core\Database\Factories\PropertyDefinitionFactory;
use Odden\Core\Encryption\SensitiveProperties;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Exceptions\SensitivePropertyInUse;

/**
 * @property int $id
 * @property string $entity_type
 * @property string $name
 * @property string $label
 * @property PropertyType $type
 * @property string $group_name
 * @property array<string, mixed>|null $options
 * @property string|null $description
 * @property bool $is_required
 * @property bool $is_searchable
 * @property bool $is_sensitive
 * @property string|null $data_area
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PropertyDefinition extends Model
{
    /** @use HasFactory<PropertyDefinitionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_type',
        'name',
        'label',
        'type',
        'group_name',
        'options',
        'description',
        'is_required',
        'is_searchable',
        'is_sensitive',
        'data_area',
        'sort_order',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-core.tables.properties', 'odden_properties');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PropertyType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_searchable' => 'boolean',
            'is_sensitive' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The data area this property's values belong to: the area chosen for it, or Sensitive. Meaningful for a sensitive property only.
     */
    public function area(): DataArea
    {
        return DataArea::tryFrom((string) $this->data_area) ?? DataArea::Sensitive;
    }

    /**
     * Scope to properties for a specific entity type.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType)->orderBy('sort_order');
    }

    /**
     * A change to a definition changes what is encrypted and who sees it, so what was read in this request is forgotten.
     */
    protected static function booted(): void
    {
        $forget = static fn () => app(SensitiveProperties::class)->forget();

        static::saved($forget);
        static::deleted($forget);

        static::saving(function (self $definition): void {
            // Values are encrypted under the property's record type and name, so renaming a sensitive property would make them
            // unreadable. Turn the switch off (the values are converted back), rename, turn it on again.
            if ($definition->exists && $definition->getOriginal('is_sensitive') && ($definition->isDirty('name') || $definition->isDirty('entity_type'))) {
                throw new SensitivePropertyInUse('A sensitive property cannot be renamed or moved to another record type. Turn Sensitive off first; its values are converted back.');
            }

            // The database cannot search encrypted values.
            if ($definition->is_sensitive) {
                $definition->is_searchable = false;
            }

            if ($definition->is_sensitive && DataArea::tryFrom((string) $definition->data_area) === null) {
                $definition->data_area = DataArea::Sensitive->value;
            }
        });

        static::deleting(function (self $definition): void {
            // Without the definition nothing would say whose eyes its encrypted values are for.
            if ($definition->is_sensitive) {
                throw new SensitivePropertyInUse('A sensitive property cannot be deleted. Turn Sensitive off first; its values are converted back.');
            }
        });
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PropertyDefinitionFactory
    {
        return PropertyDefinitionFactory::new();
    }
}
