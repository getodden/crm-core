<?php

declare(strict_types=1);

namespace Odden\Core\Screens;

use Illuminate\Database\Eloquent\Model;

interface ScreenShell
{
    /**
     * @param  class-string  $class
     */
    public function draws(Screen $screen, ScreenDrawer $drawer, string $class): bool;

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $parameters
     */
    public function url(Screen $screen, ScreenDrawer $drawer, string $class, Model|int|string|null $record, array $parameters, ?Model $tenant, bool $absolute): string;
}
