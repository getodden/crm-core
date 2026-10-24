<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\ColumnConverter;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The date of birth becomes text, because the stored form of an encrypted value is longer than a date, and the rows already
     * stored are encrypted (see odden-core.encryption.convert_on_migrate). The age is worked out in code (Contact::age()).
     */
    public function up(): void
    {
        $table = config('odden-core.tables.contacts', 'odden_contacts');

        if (! Schema::hasColumn($table, 'date_of_birth')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->text('date_of_birth')->nullable()->change();
        });

        if (config('odden-core.encryption.convert_on_migrate', true)) {
            app(ColumnConverter::class)->encrypt($table, 'date_of_birth', 'contacts.date_of_birth', fn (string $value): string => Date::parse($value)->toDateString());
        }
    }

    /**
     * Reverse the migrations. The values are decrypted with the active key first.
     */
    public function down(): void
    {
        $table = config('odden-core.tables.contacts', 'odden_contacts');

        if (! Schema::hasColumn($table, 'date_of_birth')) {
            return;
        }

        app(ColumnConverter::class)->decrypt($table, 'date_of_birth', 'contacts.date_of_birth');

        Schema::table($table, function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable()->change();
        });
    }
};
