<?php

declare(strict_types=1);

namespace Odden\Core\Demo;

/**
 * The modules that have sample data. Each package adds its {@see DemoSeeder} from its service provider.
 */
final class DemoRegistry
{
    /** @var array<class-string<DemoSeeder>, true> */
    private array $seeders = [];

    /**
     * @param  class-string<DemoSeeder>  $seeder
     */
    public function register(string $seeder): void
    {
        $this->seeders[$seeder] = true;
    }

    /**
     * @return list<DemoSeeder>
     */
    public function seeders(): array
    {
        $seeders = array_map(fn (string $class): DemoSeeder => app($class), array_keys($this->seeders));

        usort($seeders, fn (DemoSeeder $a, DemoSeeder $b): int => $a->order() <=> $b->order());

        return $seeders;
    }
}
