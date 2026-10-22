<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('odden-core.tables.demo_records', 'odden_demo_records'), function (Blueprint $table): void {
            $table->id();
            $table->string('model_type');
            $table->string('model_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['model_type', 'model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('odden-core.tables.demo_records', 'odden_demo_records'));
    }
};
