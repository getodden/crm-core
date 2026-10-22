<?php

declare(strict_types=1);

namespace Odden\Core\Demo;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\DemoRecord;
use Odden\Core\Support\UserModel;

/**
 * Loads and removes the sample data. Loading again first removes what an earlier load made, so running it twice leaves one set.
 */
final class DemoManager
{
    public function __construct(private readonly DemoRegistry $registry) {}

    public function isLoaded(): bool
    {
        return DemoRecord::exist();
    }

    /**
     * @param  (Closure(string): void)|null  $progress  Called with each module's name as its data loads.
     * @return list<string> The modules that were loaded.
     */
    public function load(?Closure $progress = null): array
    {
        $this->remove();

        $loaded = [];

        DB::transaction(function () use ($progress, &$loaded): void {
            $context = new DemoContext($this->owner());

            foreach ($this->registry->seeders() as $seeder) {
                $progress?->__invoke($seeder->module());
                $seeder->seed($context);
                $loaded[] = $seeder->module();
            }
        });

        return $loaded;
    }

    /**
     * @return int The number of sample records deleted.
     */
    public function remove(): int
    {
        return DB::transaction(fn (): int => DemoRecord::purge());
    }

    /**
     * The first user owns the sample records, so the cockpits and quotas have someone's work to show. No user is ever created.
     */
    private function owner(): ?Model
    {
        return UserModel::className()::query()->orderBy('id')->first();
    }
}
