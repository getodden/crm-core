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
     * A null email_status means the address has never been checked.
     */
    public function up(): void
    {
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        if (Schema::hasColumn($contactsTable, 'email_status')) {
            return;
        }

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->string('email_status', 20)->nullable()->index()->after('email');
            $table->string('email_status_reason', 40)->nullable()->after('email_status');
            $table->timestamp('email_checked_at')->nullable()->after('email_status_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->dropIndex(['email_status']);
            $table->dropColumn(['email_status', 'email_status_reason', 'email_checked_at']);
        });
    }
};
