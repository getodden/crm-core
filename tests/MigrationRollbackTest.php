<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('rolls every core migration back and runs them again', function (): void {
    $files = glob(dirname(__DIR__).'/database/migrations/*.php') ?: [];
    sort($files);
    $migrations = array_map(fn (string $file): object => include $file, $files);

    // Only Core's own migrations: the host's (users, cache) stay as they are.
    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }

    expect(Schema::hasTable('odden_contacts'))->toBeFalse()
        ->and(Schema::hasTable('odden_association_types'))->toBeFalse();

    foreach ($migrations as $migration) {
        $migration->up();
    }

    expect(Schema::hasColumn('odden_associations', 'association_type_id'))->toBeTrue()
        ->and(Schema::hasIndex('odden_association_types', 'odden_assoc_types_scope_name_unique'))->toBeTrue();
});
