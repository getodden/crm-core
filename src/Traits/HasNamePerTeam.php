<?php

declare(strict_types=1);

namespace Odden\Core\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Exceptions\DuplicateNameException;
use Odden\Core\Support\TenantGuard;

/**
 * A definition (association type, custom object) whose name is unique per team and tenant
 * rather than across the whole table. Rows without a team are shared by every team.
 */
trait HasNamePerTeam
{
    protected static function bootHasNamePerTeam(): void
    {
        // The database index covers rows with a team; rows without one (single-team installs)
        // have a null in the index, which never collides, so check those here too.
        static::saving(function (Model $model): void {
            if (! $model->isDirty(['name', 'team_id', TenantGuard::COLUMN])) {
                return;
            }

            $teamId = $model->getAttribute('team_id');
            $tenantId = static::hasTenantColumn() ? app(TenantGuard::class)->tenantOf($model) : null;

            $taken = $model->newQuery()
                ->where('name', $model->getAttribute('name'))
                ->when($model->exists, fn (Builder $query) => $query->whereKeyNot($model->getKey()))
                ->when($teamId === null, fn (Builder $query) => $query->whereNull('team_id'), fn (Builder $query) => $query->where('team_id', $teamId))
                ->when($tenantId !== null, fn (Builder $query) => $query->where(TenantGuard::COLUMN, $tenantId))
                ->exists();

            if ($taken) {
                throw new DuplicateNameException(sprintf('%s [%s] already exists.', class_basename($model), $model->getAttribute('name')));
            }
        });
    }

    /**
     * The definition with this name that a record can use: one in the record's team first, then a
     * shared one with no team. Another team's or tenant's definition is never returned.
     */
    public static function findForRecord(string $name, ?Model $record): ?static
    {
        return static::findForTeam(
            $name,
            $record?->getAttribute('team_id'),
            $record === null ? null : app(TenantGuard::class)->tenantOf($record),
        );
    }

    /**
     * The definition with this name visible to a team (and tenant), preferring the team's own.
     */
    public static function findForTeam(string $name, mixed $teamId, int|string|null $tenantId = null): ?static
    {
        $teamId = is_int($teamId) || is_string($teamId) ? $teamId : null;
        $tenantId = static::hasTenantColumn() ? $tenantId : null;

        /** @var static|null $found */
        $found = static::query()
            ->where('name', $name)
            ->where(fn (Builder $query) => $query
                ->whereNull('team_id')
                ->when($teamId !== null, fn (Builder $q) => $q->orWhere('team_id', $teamId)))
            ->when($tenantId !== null, fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where(TenantGuard::COLUMN, $tenantId)->orWhereNull(TenantGuard::COLUMN))
                ->orderByRaw('CASE WHEN '.TenantGuard::COLUMN.' IS NULL THEN 1 ELSE 0 END'))
            ->orderByRaw('CASE WHEN team_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        return $found;
    }

    /**
     * Whether a tenancy add-on has added a tenant_id column to this model's table.
     */
    protected static function hasTenantColumn(): bool
    {
        $model = static::query()->getModel();

        return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), TenantGuard::COLUMN);
    }
}
