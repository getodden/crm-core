<?php

declare(strict_types=1);

namespace Odden\Core\Screens;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class Screens
{
    public static function define(Screen ...$screens): void
    {
        self::registry()->define(...$screens);
    }

    public static function redefine(Screen ...$screens): void
    {
        self::registry()->redefine(...$screens);
    }

    public static function defineGroup(string $label, int $sort): void
    {
        self::registry()->defineGroup($label, $sort);
    }

    public static function draw(string $key, ScreenDrawer $drawer): void
    {
        self::registry()->draw($key, $drawer);
    }

    /**
     * @param  class-string<ScreenShell>  $class
     */
    public static function shell(string $name, string $class, int $priority = 0): void
    {
        self::registry()->shell($name, $class, $priority);
    }

    /**
     * @param  class-string  $original
     * @param  class-string  $replacement
     */
    public static function replace(string $original, string $replacement): void
    {
        self::registry()->replace($original, $replacement);
    }

    public static function except(string ...$patterns): void
    {
        self::registry()->except(...$patterns);
    }

    public static function disableModules(string ...$modules): void
    {
        self::registry()->disableModules(...$modules);
    }

    /**
     * @param  class-string<Model>  ...$models
     */
    public static function requireViewAny(string $key, ?string $module, string ...$models): void
    {
        self::registry()->requireViewAny($key, $module, ...$models);
    }

    public static function freeze(): void
    {
        self::registry()->freeze();
    }

    public static function isFrozen(): bool
    {
        return self::registry()->isFrozen();
    }

    public static function has(string $key): bool
    {
        return self::registry()->has($key);
    }

    public static function get(string $key): Screen
    {
        return self::registry()->get($key);
    }

    /**
     * @return list<Screen>
     */
    public static function all(): array
    {
        return self::registry()->all();
    }

    /**
     * @return array<class-string, class-string>
     */
    public static function replacements(): array
    {
        return self::registry()->replacements();
    }

    /**
     * @return list<string>
     */
    public static function exceptions(): array
    {
        return self::registry()->exceptions();
    }

    /**
     * @return list<string>
     */
    public static function disabledModules(): array
    {
        return self::registry()->disabledModules();
    }

    public static function isExcepted(string $key): bool
    {
        return self::registry()->isExcepted($key);
    }

    public static function moduleEnabled(?string $module): bool
    {
        return self::registry()->moduleEnabled($module);
    }

    public static function drawerFor(string $key, string $shell): ?ScreenDrawer
    {
        return self::registry()->drawerFor($key, $shell);
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    public static function keysDrawnBy(string $shell, string $class): array
    {
        return self::registry()->keysDrawnBy($shell, $class);
    }

    public static function available(string $key): bool
    {
        return self::registry()->available($key);
    }

    public static function can(string $key, Authenticatable $user, ?Model $record = null): bool
    {
        return self::registry()->can($key, $user, $record);
    }

    public static function canOpen(string $key, Authenticatable $user, ?Model $record = null): bool
    {
        return self::registry()->canOpen($key, $user, $record);
    }

    /**
     * @return list<array{group: string|null, screens: list<Screen>}>
     */
    public static function navigation(Authenticatable $user): array
    {
        return self::registry()->navigation($user);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function url(string $key, Model|int|string|null $record = null, array $parameters = [], ?Model $tenant = null, bool $absolute = true): string
    {
        return self::registry()->url($key, $record, $parameters, $tenant, $absolute);
    }

    private static function registry(): ScreenRegistry
    {
        return app(ScreenRegistry::class);
    }
}
