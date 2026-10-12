<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Association type and custom object names were unique across the whole table, so one team
     * (or tenant) taking a name blocked every other. They become unique per team, and per tenant
     * when a tenancy add-on has added a tenant_id column. The new indexes only relax the old
     * ones, so existing rows always satisfy them.
     */
    public function up(): void
    {
        foreach ($this->tables() as $table => [$oldIndex, $newIndex, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $scoped = array_values(array_filter(
                ['tenant_id', ...$columns],
                fn (string $column): bool => $column !== 'tenant_id' || Schema::hasColumn($table, 'tenant_id'),
            ));

            // Property definitions have no team; without a tenant column there is nothing to change.
            if ($scoped === $this->unscoped($table) || Schema::hasIndex($table, $newIndex)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $oldIndex, $newIndex, $scoped): void {
                if (Schema::hasIndex($table, $oldIndex)) {
                    $blueprint->dropUnique($oldIndex);
                }

                $blueprint->unique($scoped, $newIndex);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables() as $table => [$oldIndex, $newIndex]) {
            if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $newIndex)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $oldIndex, $newIndex): void {
                $blueprint->dropUnique($newIndex);
                $blueprint->unique($this->unscoped($table), $oldIndex);
            });
        }
    }

    /**
     * Each table's original unique index, its scoped replacement, and the scoped columns
     * (tenant_id is prepended when it exists).
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private function tables(): array
    {
        $types = config('odden-core.tables.association_types', 'odden_association_types');
        $definitions = config('odden-core.tables.custom_object_definitions', 'odden_custom_object_definitions');
        $properties = config('odden-core.tables.properties', 'odden_properties');

        return [
            $types => ["{$types}_name_unique", 'odden_assoc_types_scope_name_unique', ['team_id', 'name']],
            $definitions => ["{$definitions}_name_unique", 'odden_object_defs_scope_name_unique', ['team_id', 'name']],
            $properties => ['odden_entity_prop_unique', 'odden_entity_prop_scope_unique', ['entity_type', 'name']],
        ];
    }

    /**
     * The columns of a table's original unique index.
     *
     * @return list<string>
     */
    private function unscoped(string $table): array
    {
        return $table === config('odden-core.tables.properties', 'odden_properties') ? ['entity_type', 'name'] : ['name'];
    }
};
