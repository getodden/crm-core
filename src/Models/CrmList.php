<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Odden\Core\Actions\EvaluateActiveListAction;
use Odden\Core\Database\Factories\CrmListFactory;
use Odden\Core\Encryption\Casts\EncryptedJson;
use Odden\Core\Enums\ListType;
use Odden\Core\Support\UserModel;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $entity_type
 * @property ListType $type
 * @property array<array<string, mixed>>|null $criteria
 * @property int|null $created_by_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class CrmList extends Model
{
    /** @use HasFactory<CrmListFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'entity_type',
        'type',
        'criteria',
        'created_by_id',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-core.tables.lists', 'odden_lists');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ListType::class,
            'criteria' => EncryptedJson::class.':lists.criteria',
        ];
    }

    /**
     * Memberships relationship.
     *
     * @return HasMany<ListMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ListMembership::class, 'list_id');
    }

    /**
     * Associated contacts in this list.
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            config('odden-core.tables.list_memberships', 'odden_list_memberships'),
            'list_id',
            'member_id'
        )
            ->wherePivot('member_type', (new Contact)->getMorphClass())
            ->withPivot('added_at');
    }

    /**
     * Associated companies in this list.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            config('odden-core.tables.list_memberships', 'odden_list_memberships'),
            'list_id',
            'member_id'
        )
            ->wherePivot('member_type', (new Company)->getMorphClass())
            ->withPivot('added_at');
    }

    /**
     * The user who created the list.
     *
     * @return BelongsTo<Model, $this>
     */
    public function creator(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'created_by_id');
    }

    /**
     * Add a record as a member of this list.
     */
    public function addMember(Model $record): ListMembership
    {
        return ListMembership::firstOrCreate([
            'list_id' => $this->id,
            'member_type' => $record->getMorphClass(),
            'member_id' => $record->getKey(),
        ], [
            'added_at' => now(),
        ]);
    }

    /**
     * Remove a record from this list.
     */
    public function removeMember(Model $record): int
    {
        return ListMembership::query()
            ->where('list_id', $this->id)
            ->where('member_type', $record->getMorphClass())
            ->where('member_id', $record->getKey())
            ->delete();
    }

    /**
     * Check if a record is currently a member of this list.
     */
    public function hasMember(Model $record): bool
    {
        return ListMembership::query()
            ->where('list_id', $this->id)
            ->where('member_type', $record->getMorphClass())
            ->where('member_id', $record->getKey())
            ->exists();
    }

    /**
     * Sync active list memberships based on its criteria rules.
     */
    public function syncActiveMembers(): int
    {
        if ($this->type !== ListType::Active) {
            return 0;
        }

        return app(EvaluateActiveListAction::class)->execute($this);
    }

    /**
     * Scope query to lists for a specific entity type.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CrmListFactory
    {
        return CrmListFactory::new();
    }
}
