<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('rolls the per-team name indexes back and forward', function (): void {
    $migration = include dirname(__DIR__).'/database/migrations/2026_01_01_000016_scope_odden_definition_names_to_team.php';

    expect(Schema::hasIndex('odden_association_types', 'odden_assoc_types_scope_name_unique'))->toBeTrue()
        ->and(Schema::hasIndex('odden_association_types', 'odden_association_types_name_unique'))->toBeFalse()
        ->and(Schema::hasIndex('odden_custom_object_definitions', 'odden_object_defs_scope_name_unique'))->toBeTrue();

    $migration->down();

    expect(Schema::hasIndex('odden_association_types', 'odden_association_types_name_unique'))->toBeTrue()
        ->and(Schema::hasIndex('odden_association_types', 'odden_assoc_types_scope_name_unique'))->toBeFalse()
        ->and(Schema::hasIndex('odden_custom_object_definitions', 'odden_custom_object_definitions_name_unique'))->toBeTrue()
        ->and(Schema::hasIndex('odden_properties', 'odden_entity_prop_unique'))->toBeTrue();

    $migration->up();

    expect(Schema::hasIndex('odden_association_types', 'odden_assoc_types_scope_name_unique'))->toBeTrue()
        ->and(Schema::hasIndex('odden_custom_object_definitions', 'odden_object_defs_scope_name_unique'))->toBeTrue();
});
