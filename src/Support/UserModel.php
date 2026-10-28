<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Contracts\TenantContext;
use RuntimeException;

/**
 * Resolves the host application's user model so Odden packages never
 * reference a concrete App\ class.
 *
 * Resolution order: config('odden-core.user_model'), then the default
 * auth provider's model (config('auth.providers.users.model')).
 */
final class UserModel
{
    /**
     * @return class-string<Model>
     */
    public static function className(): string
    {
        $class = config('odden-core.user_model') ?? config('auth.providers.users.model');

        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            throw new RuntimeException(
                'Odden could not resolve an Eloquent user model. Set "odden-core.user_model" or "auth.providers.users.model".'
            );
        }

        return $class;
    }

    /**
     * A query on the user model, narrowed to the active tenant's users when there is one.
     *
     * @return Builder<Model>
     */
    public static function query(): Builder
    {
        return app(TenantContext::class)->scopeUsers(self::className()::query());
    }

    /**
     * The users a picker may offer: query() without disabled accounts, when "odden-core.users.disabled_column" is set.
     *
     * Use it for lists a person chooses from (owner, assignee, @mention candidates). Use query() for everything else: looking a
     * user up, routing, reports, and resolving a value already stored on a record.
     *
     * @param  int|string|array<array-key, int|string>|null  $keep  The key (or keys) of the user(s) a record being edited already has; offered even when disabled, so
     *                                                              the form still shows and accepts its current value.
     * @return Builder<Model>
     */
    public static function pickable(int|string|array|null $keep = null): Builder
    {
        $query = self::query();
        $column = config('odden-core.users.disabled_column');

        if (! is_string($column) || $column === '') {
            return $query;
        }

        $qualified = self::make()->qualifyColumn($column);

        return $query->where(function (Builder $query) use ($qualified, $keep): void {
            $query->whereNull($qualified);

            if ($keep !== null && $keep !== '' && $keep !== []) {
                $query->orWhereKey($keep);
            }
        });
    }

    public static function make(): Model
    {
        $class = self::className();

        return new $class;
    }

    public static function table(): string
    {
        return self::make()->getTable();
    }

    /**
     * The user's "name" attribute, or the fallback when there is no user or no usable name.
     */
    public static function displayName(?Model $user, string $fallback = 'Unknown'): string
    {
        $name = $user?->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : $fallback;
    }
}
