<?php

declare(strict_types=1);

namespace Odden\Core\Demo;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Models\DemoRecord;

/**
 * What the sample-data seeders share while they run: the person who owns the sample records, the records an earlier seeder made
 * (so a later one can attach deals or tickets to them), and the tracker that marks each record as sample data.
 */
final class DemoContext
{
    /** @var array<string, array<string, Model>> */
    private array $records = [];

    public function __construct(public readonly ?Model $owner = null) {}

    /**
     * Mark a record as sample data and return it.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    public function track(Model $model): Model
    {
        DemoRecord::mark($model);

        return $model;
    }

    /**
     * Keep a record under a name so a later seeder can find it.
     */
    public function remember(string $group, string $key, Model $model): void
    {
        $this->records[$group][$key] = $model;
    }

    /**
     * @return array<string, Model>
     */
    public function all(string $group): array
    {
        return $this->records[$group] ?? [];
    }

    public function find(string $group, string $key): ?Model
    {
        return $this->records[$group][$key] ?? null;
    }

    public function ownerId(): int|string|null
    {
        return $this->owner?->getKey();
    }
}
