<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Support\ActivityMarker;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Notes, calls, tasks and the rest of the timeline hold what people wrote and said, so the title, body and metadata are
     * encrypted. The three columns become text, because the stored form is longer than the value. Code that looked activities up by
     * their metadata (cadence steps, quote views) now looks for the plain `marker` column, which is filled in first from the
     * metadata of the rows already stored.
     */
    public function up(): void
    {
        $name = config('odden-core.tables.activities', 'odden_activities');

        if (! Schema::hasColumn($name, 'marker')) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('marker', 80)->nullable()->index('odden_activity_marker_idx');
            });
        }

        Schema::table($name, function (Blueprint $table): void {
            $table->text('title')->nullable()->change();
            $table->text('metadata')->nullable()->change();
        });

        DB::table($name)->whereNotNull('metadata')->whereNull('marker')->chunkById(500, function ($rows) use ($name): void {
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->metadata, true);
                $marker = is_array($decoded) ? ActivityMarker::fromMetadata($decoded) : null;

                if ($marker !== null) {
                    DB::table($name)->where('id', $row->id)->update(['marker' => $marker]);
                }
            }
        });

        if (config('odden-core.encryption.convert_on_migrate', true)) {
            $converter = app(ColumnConverter::class);

            foreach (['title', 'body', 'metadata'] as $column) {
                $converter->encrypt($name, $column, "activities.{$column}");
            }
        }
    }

    /**
     * Reverse the migrations. The values are decrypted with the active key first.
     */
    public function down(): void
    {
        $name = config('odden-core.tables.activities', 'odden_activities');

        $converter = app(ColumnConverter::class);

        foreach (['title', 'body', 'metadata'] as $column) {
            $converter->decrypt($name, $column, "activities.{$column}");
        }

        Schema::table($name, function (Blueprint $table): void {
            $table->dropIndex('odden_activity_marker_idx');
        });

        Schema::table($name, function (Blueprint $table): void {
            $table->dropColumn('marker');
        });

        Schema::table($name, function (Blueprint $table): void {
            $table->string('title')->nullable()->change();
            $table->json('metadata')->nullable()->change();
        });
    }
};
