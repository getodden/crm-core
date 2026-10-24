<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\SensitivePropertyConverter;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A custom property can be marked sensitive: its values are then stored encrypted and belong to a data area (the Sensitive
     * area when none is chosen). The values already stored are converted by `odden:convert-sensitive-properties`, or by the job
     * the panel starts when the switch is turned on.
     */
    public function up(): void
    {
        $table = config('odden-core.tables.properties', 'odden_properties');

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->boolean('is_sensitive')->default(false)->after('is_searchable');
            $table->string('data_area', 20)->nullable()->after('is_sensitive');
        });
    }

    /**
     * Reverse the migrations. Sensitive values are decrypted first, so no property is left unreadable.
     */
    public function down(): void
    {
        $table = config('odden-core.tables.properties', 'odden_properties');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'is_sensitive')) {
            return;
        }

        DB::table($table)->update(['is_sensitive' => false]);

        app(SensitivePropertyConverter::class)->reconcileAll();

        Schema::table($table, function (Blueprint $table): void {
            $table->dropColumn(['is_sensitive', 'data_area']);
        });
    }
};
