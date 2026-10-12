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

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->string('job_title')->nullable()->after('last_name');
            $table->string('lead_status', 30)->default('new')->index()->after('lifecycle_stage');
            $table->string('linkedin_url')->nullable()->after('phone');
            $table->string('timezone', 50)->nullable()->after('linkedin_url');
            $table->timestamp('last_contacted_at')->nullable()->index()->after('properties');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->dropIndex(['lead_status']);
            $table->dropIndex(['last_contacted_at']);
            $table->dropColumn([
                'job_title',
                'lead_status',
                'linkedin_url',
                'timezone',
                'last_contacted_at',
            ]);
        });
    }
};
