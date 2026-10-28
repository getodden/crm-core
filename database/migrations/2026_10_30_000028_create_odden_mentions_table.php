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
     * Who is mentioned where: one row per person picked with "@" in a note or a comment, by id, so a host can tell the person. The
     * label is the name as it was written in the text, so an "@Name" written before a change of name still finds the same person.
     */
    public function up(): void
    {
        $name = config('odden-core.tables.mentions', 'odden_mentions');

        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table): void {
            $table->id();
            $table->string('mentionable_type');
            $table->unsignedBigInteger('mentionable_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('label');
            $table->unsignedBigInteger('mentioned_by')->nullable();
            $table->timestamp('created_at')->nullable();

            // Also the index for finding the mentions of a note or comment.
            $table->unique(['mentionable_type', 'mentionable_id', 'user_id'], 'odden_mentions_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('odden-core.tables.mentions', 'odden_mentions'));
    }
};
