<?php

declare(strict_types=1);

namespace Odden\Core\Demo;

/**
 * One module's share of the sample data. A package registers its seeder with {@see DemoRegistry} and `odden:demo` runs the registered
 * seeders in order, so a module's sample data is only loaded when the module is installed.
 */
interface DemoSeeder
{
    /**
     * The module's name, shown while the sample data loads.
     */
    public function module(): string;

    /**
     * Lower numbers run first. Core is 0; a module that builds on core records uses a higher number.
     */
    public function order(): int;

    /**
     * Create the module's sample records. Every record must go through {@see DemoContext::track()} so that `odden:demo --remove` can delete it.
     */
    public function seed(DemoContext $context): void;
}
