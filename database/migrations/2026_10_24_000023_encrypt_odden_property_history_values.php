<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Odden\Core\Encryption\ColumnConverter;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The history keeps the old and new value of a property, which is the same personal data as the property itself, so both are
     * encrypted. The columns are already text.
     */
    public function up(): void
    {
        if (! config('odden-core.encryption.convert_on_migrate', true)) {
            return;
        }

        $converter = app(ColumnConverter::class);
        $table = config('odden-core.tables.property_history', 'odden_property_history');

        foreach (['old_value', 'new_value'] as $column) {
            $converter->encrypt($table, $column, "property_history.{$column}");
        }
    }

    /**
     * Reverse the migrations. The values are decrypted with the active key.
     */
    public function down(): void
    {
        $converter = app(ColumnConverter::class);
        $table = config('odden-core.tables.property_history', 'odden_property_history');

        foreach (['old_value', 'new_value'] as $column) {
            $converter->decrypt($table, $column, "property_history.{$column}");
        }
    }
};
