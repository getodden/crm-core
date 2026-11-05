<?php

declare(strict_types=1);

namespace Odden\Core\Screens;

final readonly class ScreenDrawer
{
    /**
     * @param  class-string  $class
     */
    public function __construct(
        public string $shell,
        public string $class,
        public ?string $page = null,
        public ?string $panel = null,
    ) {}
}
