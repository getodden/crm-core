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
     * The search index for encrypted columns: one row per keyed hash of a value (an email address, a word of a name, the first
     * letters of a word) and the record it belongs to. See Odden\Core\Encryption\Index\SearchIndex.
     */
    public function up(): void
    {
        $name = config('odden-core.tables.search_index', 'odden_search_index');

        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table): void {
            $table->string('field', 64);
            $table->char('token', 64);
            $table->unsignedBigInteger('record_id');

            $table->primary(['field', 'token', 'record_id'], 'odden_search_index_primary');
            $table->index(['field', 'record_id'], 'odden_search_index_record_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('odden-core.tables.search_index', 'odden_search_index'));
    }
};
