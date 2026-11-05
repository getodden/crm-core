<?php

declare(strict_types=1);

namespace Odden\Core\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenShell;

class RecordingScreenShell implements ScreenShell
{
    /** @var list<array{key: string, shell: string, class: string, record: Model|int|string|null, parameters: array<string, mixed>, tenant: Model|null, absolute: bool}> */
    public static array $calls = [];

    /** @var list<string> */
    public static array $refusedClasses = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$refusedClasses = [];
    }

    public function draws(Screen $screen, ScreenDrawer $drawer, string $class): bool
    {
        return ! in_array($class, self::$refusedClasses, true);
    }

    public function url(Screen $screen, ScreenDrawer $drawer, string $class, Model|int|string|null $record, array $parameters, ?Model $tenant, bool $absolute): string
    {
        self::$calls[] = [
            'key' => $screen->key,
            'shell' => $drawer->shell,
            'class' => $class,
            'record' => $record,
            'parameters' => $parameters,
            'tenant' => $tenant,
            'absolute' => $absolute,
        ];

        return "{$drawer->shell}:{$class}:{$screen->key}";
    }
}
