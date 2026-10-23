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
        Schema::create(config('odden-core.tables.action_log', 'odden_action_log'), function (Blueprint $table): void {
            $table->id();
            $table->string('action');
            $table->string('actor_kind', 16);
            $table->string('actor_user_id')->nullable();
            $table->string('via', 32);
            $table->string('status', 16);
            // Free-text fields are recorded as "[redacted]": the log says what was asked, not what people wrote.
            $table->json('input')->nullable();
            $table->text('error')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('idempotency_key')->nullable()->index();
            $table->unsignedBigInteger('proposal_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['actor_kind', 'actor_user_id']);
        });

        Schema::create(config('odden-core.tables.proposed_actions', 'odden_proposed_actions'), function (Blueprint $table): void {
            $table->id();
            $table->string('action');
            // The full input, kept encrypted: it is what runs when a person approves, and it holds what people wrote.
            $table->text('input');
            $table->string('status', 16)->default('pending')->index();
            $table->string('requested_by_kind', 16);
            $table->string('requested_by_user_id')->nullable();
            $table->string('via', 32);
            $table->string('idempotency_key')->nullable();
            $table->string('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('odden-core.tables.proposed_actions', 'odden_proposed_actions'));
        Schema::dropIfExists(config('odden-core.tables.action_log', 'odden_action_log'));
    }
};
