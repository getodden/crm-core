<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column a host application uses to mark an account disabled (odden-core.users.disabled_column). Added with the rest of the
 * schema, not inside a test, for the same reason as the other fixture tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('disabled_at');
        });
    }
};
