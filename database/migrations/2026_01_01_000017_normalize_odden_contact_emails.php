<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Contacts are saved with the email lowercased and trimmed, and ContactLookup matches the
     * stored value exactly so it can use the email index. Normalize rows saved before that.
     */
    public function up(): void
    {
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        if (! Schema::hasTable($contactsTable)) {
            return;
        }

        DB::table($contactsTable)
            ->whereRaw('email <> LOWER(TRIM(email))')
            ->update(['email' => DB::raw('LOWER(TRIM(email))')]);
    }

    /**
     * Reverse the migrations. The original spelling isn't kept, and the normalized one is
     * equivalent, so there is nothing to undo.
     */
    public function down(): void {}
};
