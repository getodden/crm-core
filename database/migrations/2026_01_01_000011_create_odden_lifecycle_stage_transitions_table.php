<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Support\UserModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $transitionsTable = config('odden-core.tables.lifecycle_stage_transitions', 'odden_lifecycle_stage_transitions');
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');
        $companiesTable = config('odden-core.tables.companies', 'odden_companies');

        // Create lifecycle stage transitions table
        Schema::create($transitionsTable, function (Blueprint $table): void {
            $table->id();
            $table->string('record_type');
            $table->unsignedBigInteger('record_id');
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->string('source')->default('manual');
            $table->foreignIdFor(UserModel::className(), 'user_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->timestamp('transitioned_at')->index();
            $table->timestamps();

            $table->index(['record_type', 'record_id']);
            $table->index(['from_stage', 'to_stage']);
        });

        // Add became_<stage>_at timestamps to contacts table
        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->timestamp('became_subscriber_at')->nullable()->after('lifecycle_stage');
            $table->timestamp('became_lead_at')->nullable()->after('became_subscriber_at');
            $table->timestamp('became_marketing_qualified_lead_at')->nullable()->after('became_lead_at');
            $table->timestamp('became_sales_qualified_lead_at')->nullable()->after('became_marketing_qualified_lead_at');
            $table->timestamp('became_opportunity_at')->nullable()->after('became_sales_qualified_lead_at');
            $table->timestamp('became_customer_at')->nullable()->after('became_opportunity_at');
            $table->timestamp('became_evangelist_at')->nullable()->after('became_customer_at');
            $table->timestamp('became_other_at')->nullable()->after('became_evangelist_at');
        });

        // Add lifecycle_stage and became_<stage>_at timestamps to companies table
        Schema::table($companiesTable, function (Blueprint $table): void {
            $table->string('lifecycle_stage')->nullable()->default(LifecycleStage::Lead->value)->index()->after('industry');
            $table->timestamp('became_subscriber_at')->nullable()->after('lifecycle_stage');
            $table->timestamp('became_lead_at')->nullable()->after('became_subscriber_at');
            $table->timestamp('became_marketing_qualified_lead_at')->nullable()->after('became_lead_at');
            $table->timestamp('became_sales_qualified_lead_at')->nullable()->after('became_marketing_qualified_lead_at');
            $table->timestamp('became_opportunity_at')->nullable()->after('became_sales_qualified_lead_at');
            $table->timestamp('became_customer_at')->nullable()->after('became_opportunity_at');
            $table->timestamp('became_evangelist_at')->nullable()->after('became_customer_at');
            $table->timestamp('became_other_at')->nullable()->after('became_evangelist_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $transitionsTable = config('odden-core.tables.lifecycle_stage_transitions', 'odden_lifecycle_stage_transitions');
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');
        $companiesTable = config('odden-core.tables.companies', 'odden_companies');

        Schema::dropIfExists($transitionsTable);

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->dropColumn([
                'became_subscriber_at',
                'became_lead_at',
                'became_marketing_qualified_lead_at',
                'became_sales_qualified_lead_at',
                'became_opportunity_at',
                'became_customer_at',
                'became_evangelist_at',
                'became_other_at',
            ]);
        });

        Schema::table($companiesTable, function (Blueprint $table): void {
            $table->dropIndex(['lifecycle_stage']);
            $table->dropColumn([
                'lifecycle_stage',
                'became_subscriber_at',
                'became_lead_at',
                'became_marketing_qualified_lead_at',
                'became_sales_qualified_lead_at',
                'became_opportunity_at',
                'became_customer_at',
                'became_evangelist_at',
                'became_other_at',
            ]);
        });
    }
};
