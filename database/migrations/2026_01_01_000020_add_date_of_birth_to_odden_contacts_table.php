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
     * A null date_of_birth means the age is not known. It is personal data about a person who may be a child: the age checks
     * that read it (see Contact::age()) treat an unknown age as unknown, never as an adult.
     */
    public function up(): void
    {
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        if (Schema::hasColumn($contactsTable, 'date_of_birth')) {
            return;
        }

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable()->after('timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->dropColumn('date_of_birth');
        });
    }
};
