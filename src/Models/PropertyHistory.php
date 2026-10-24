<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Odden\Core\Database\Factories\PropertyHistoryFactory;
use Odden\Core\Encryption\Casts\EncryptedString;
use Odden\Core\Support\UserModel;

/**
 * @property int $id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property string $property_name
 * @property string|null $old_value
 * @property string|null $new_value
 * @property int|null $user_id
 * @property string $source
 * @property CarbonInterface $created_at
 */
class PropertyHistory extends Model
{
    /** @use HasFactory<PropertyHistoryFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'property_name',
        'old_value',
        'new_value',
        'user_id',
        'source',
        'created_at',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-core.tables.property_history', 'odden_property_history');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => EncryptedString::class.':property_history.old_value',
            'new_value' => EncryptedString::class.':property_history.new_value',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The auditable record.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The user who made the change.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PropertyHistoryFactory
    {
        return PropertyHistoryFactory::new();
    }
}
