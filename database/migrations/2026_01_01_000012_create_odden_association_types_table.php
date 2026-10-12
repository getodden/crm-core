<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Enums\AssociationCardinality;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $typesTable = config('odden-core.tables.association_types', 'odden_association_types');
        $associationsTable = config('odden-core.tables.associations', 'odden_associations');

        Schema::create($typesTable, function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->string('reverse_label')->nullable();
            $table->string('cardinality')->default(AssociationCardinality::ManyToMany->value);
            $table->string('from_record_type')->nullable();
            $table->string('to_record_type')->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::table($associationsTable, function (Blueprint $table): void {
            $table->unsignedBigInteger('association_type_id')->nullable()->after('type')->index();
            $table->string('label')->nullable()->after('association_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $typesTable = config('odden-core.tables.association_types', 'odden_association_types');
        $associationsTable = config('odden-core.tables.associations', 'odden_associations');

        Schema::table($associationsTable, function (Blueprint $table): void {
            $table->dropIndex(['association_type_id']);
            $table->dropColumn(['association_type_id', 'label']);
        });

        Schema::dropIfExists($typesTable);
    }
};
