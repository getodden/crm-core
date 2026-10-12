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
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');
        $companiesTable = config('odden-core.tables.companies', 'odden_companies');

        if (Schema::hasTable($contactsTable)) {
            Schema::table($contactsTable, function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable()->index()->after('owner_id');
            });
        }

        if (Schema::hasTable($companiesTable)) {
            Schema::table($companiesTable, function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable()->index()->after('owner_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');
        $companiesTable = config('odden-core.tables.companies', 'odden_companies');

        if (Schema::hasTable($contactsTable)) {
            Schema::table($contactsTable, function (Blueprint $table): void {
                $table->dropIndex(['team_id']);
                $table->dropColumn('team_id');
            });
        }

        if (Schema::hasTable($companiesTable)) {
            Schema::table($companiesTable, function (Blueprint $table): void {
                $table->dropIndex(['team_id']);
                $table->dropColumn('team_id');
            });
        }
    }
};
