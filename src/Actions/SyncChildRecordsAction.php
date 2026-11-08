<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;

class SyncChildRecordsAction
{
    /**
     * @template TChild of Model
     * @template TParent of Model
     *
     * @param  HasMany<TChild, TParent>  $relation
     * @param  list<array<string, mixed>>  $rows
     */
    public function execute(HasMany $relation, array $rows, ?string $orderColumn = null): void
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($relation->getParent());

        DB::transaction(function () use ($relation, $rows, $orderColumn): void {
            $existing = $relation->get()->keyBy(fn (Model $child): string => (string) $child->getKey());
            $kept = [];

            foreach ($rows as $position => $row) {
                $id = isset($row['id']) ? (string) $row['id'] : null;
                $row = Arr::except($row, ['id', $relation->getForeignKeyName()]);

                if ($orderColumn !== null) {
                    $row[$orderColumn] = $position + 1;
                }

                $child = $id !== null ? $existing->get($id) : null;

                if ($child === null) {
                    $relation->create($row);

                    continue;
                }

                $child->fill($row)->save();
                $kept[] = $id;
            }

            $existing->except($kept)->each(fn (Model $child): ?bool => $child->delete());
        });
    }
}
