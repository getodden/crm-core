<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\Core\Events\ModelSaved;
use Odden\Core\Support\TenantGuard;

class SaveModelAction
{
    /**
     * @param  Model|class-string<Model>  $target
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Model|string $target, array $attributes): Model
    {
        $record = $target instanceof Model ? $target : new $target;

        if ($record->exists) {
            app(TenantGuard::class)->assertOwnedWhenTenanted($record);
        }

        $wasCreated = ! $record->exists;

        $saved = DB::transaction(fn (): bool => $record->fill($attributes)->save());

        if ($saved) {
            ModelSaved::dispatch($record, $wasCreated);
        }

        return $record;
    }
}
