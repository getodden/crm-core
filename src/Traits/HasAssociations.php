<?php

declare(strict_types=1);

namespace Odden\Core\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Odden\Core\Actions\AssociateRecordsAction;
use Odden\Core\Models\Association;
use Odden\Core\Models\AssociationType;

trait HasAssociations
{
    /**
     * Associations where this model is the parent.
     *
     * @return MorphMany<Association, $this>
     */
    public function associationsAsParent(): MorphMany
    {
        return $this->morphMany(Association::class, 'parent');
    }

    /**
     * Associations where this model is the child.
     *
     * @return MorphMany<Association, $this>
     */
    public function associationsAsChild(): MorphMany
    {
        return $this->morphMany(Association::class, 'child');
    }

    /**
     * Associate this model with another record.
     */
    public function associateWith(
        Model $record,
        string|AssociationType $type = 'default',
        ?string $label = null
    ): Association {
        return app(AssociateRecordsAction::class)->execute($this, $record, $type, $label);
    }

    /**
     * Dissociate this model from another record.
     */
    public function dissociateFrom(Model $record, ?string $type = null): int
    {
        return $this->associationsWith($record, $type)->delete();
    }

    /**
     * Determine if this model is associated with another record.
     */
    public function isAssociatedWith(Model $record, ?string $type = null): bool
    {
        return $this->associationsWith($record, $type)->exists();
    }

    /**
     * Associations between this model and $record, in either direction.
     *
     * @return Builder<Association>
     */
    protected function associationsWith(Model $record, ?string $type = null): Builder
    {
        return Association::query()
            ->where(function (Builder $query) use ($record): void {
                $query->where(fn (Builder $direction) => $direction
                    ->where('parent_type', $this->getMorphClass())
                    ->where('parent_id', $this->getKey())
                    ->where('child_type', $record->getMorphClass())
                    ->where('child_id', $record->getKey()))
                    ->orWhere(fn (Builder $direction) => $direction
                        ->where('parent_type', $record->getMorphClass())
                        ->where('parent_id', $record->getKey())
                        ->where('child_type', $this->getMorphClass())
                        ->where('child_id', $this->getKey()));
            })
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type));
    }

    /**
     * Get all associated models of a given class.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass
     * @return Collection<int, T>
     */
    public function getAssociated(string $modelClass, ?string $type = null): Collection
    {
        $targetInstance = new $modelClass;
        $targetMorph = $targetInstance->getMorphClass();

        // 1. Where this model is parent and target is child
        $childIds = Association::query()
            ->where('parent_type', $this->getMorphClass())
            ->where('parent_id', $this->getKey())
            ->where('child_type', $targetMorph)
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type))
            ->pluck('child_id');

        // 2. Where this model is child and target is parent
        $parentIds = Association::query()
            ->where('child_type', $this->getMorphClass())
            ->where('child_id', $this->getKey())
            ->where('parent_type', $targetMorph)
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type))
            ->pluck('parent_id');

        $allIds = $childIds->merge($parentIds)->unique();

        if ($allIds->isEmpty()) {
            /** @var Collection<int, T> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, T> $results */
        $results = $modelClass::whereIn((new $modelClass)->getKeyName(), $allIds->values()->all())->get();

        return $results;
    }

    /**
     * Get all associated models of a given class that have a specific association label.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass
     * @return Collection<int, T>
     */
    public function getAssociatedByLabel(string $modelClass, string $label): Collection
    {
        $targetInstance = new $modelClass;
        $targetMorph = $targetInstance->getMorphClass();

        // 1. Where this model is parent and target is child
        $childIds = Association::query()
            ->where('parent_type', $this->getMorphClass())
            ->where('parent_id', $this->getKey())
            ->where('child_type', $targetMorph)
            ->where(function (Builder $q) use ($label): void {
                $q->where('label', $label)
                    ->orWhereHas('associationType', fn (Builder $sub) => $sub->where('label', $label));
            })
            ->pluck('child_id');

        // 2. Where this model is child and target is parent
        $parentIds = Association::query()
            ->where('child_type', $this->getMorphClass())
            ->where('child_id', $this->getKey())
            ->where('parent_type', $targetMorph)
            ->where(function (Builder $q) use ($label): void {
                // Seen from the child, the parent carries the type's reverse label. A link without
                // a reverse label reads the same from both sides.
                $q->whereHas('associationType', fn (Builder $sub) => $sub->where('reverse_label', $label))
                    ->orWhere(fn (Builder $symmetric) => $symmetric
                        ->where(fn (Builder $sub) => $sub->where('label', $label)->orWhereHas('associationType', fn (Builder $type) => $type->where('label', $label)))
                        ->whereDoesntHave('associationType', fn (Builder $type) => $type->whereNotNull('reverse_label')));
            })
            ->pluck('parent_id');

        $allIds = $childIds->merge($parentIds)->unique();

        if ($allIds->isEmpty()) {
            /** @var Collection<int, T> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, T> $results */
        $results = $modelClass::whereIn((new $modelClass)->getKeyName(), $allIds->values()->all())->get();

        return $results;
    }
}
