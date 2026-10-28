<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Odden\Core\Support\UserModel;

/**
 * A person picked with "@" in a note or a comment (see RecordMentionsAction).
 *
 * @property int $id
 * @property string $mentionable_type
 * @property int $mentionable_id
 * @property int $user_id
 * @property string $label The name as written in the text when the person was picked.
 * @property int|null $mentioned_by
 * @property CarbonInterface|null $created_at
 * @property-read Model|null $user
 */
class Mention extends Model
{
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'mentionable_type',
        'mentionable_id',
        'user_id',
        'label',
        'mentioned_by',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-core.tables.mentions', 'odden_mentions');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'mentioned_by' => 'integer',
        ];
    }

    /**
     * The note or comment the person is mentioned in.
     *
     * @return MorphTo<Model, $this>
     */
    public function mentionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The person mentioned.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::className(), 'user_id');
    }
}
