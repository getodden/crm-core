<?php

declare(strict_types=1);

namespace Odden\Core\Console;

final class InstallSteps
{
    /** @var array<class-string<InstallStep>, true> */
    private array $steps = [];

    /**
     * @param  class-string<InstallStep>  $step
     */
    public function register(string $step): void
    {
        $this->steps[$step] = true;
    }

    /**
     * @return list<InstallStep>
     */
    public function all(): array
    {
        return array_map(fn (string $class): InstallStep => app($class), array_keys($this->steps));
    }
}
