<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Events\CompaniesMerged;
use Odden\Core\Models\Company;
use Odden\Core\Support\RecordMerger;
use Odden\Core\Support\TenantGuard;

class MergeCompaniesAction
{
    public function __construct(
        protected RecordMerger $merger
    ) {}

    /**
     * Merge a secondary duplicate company into a primary company, inside a transaction.
     * Merges fields and properties, moves everything Core owns (activities, associations, list
     * memberships, property history, lifecycle transitions) to the primary, dispatches
     * CompaniesMerged so other packages can move their records, recalculates the health score,
     * and soft-deletes the secondary.
     *
     * @param  array<string, mixed>  $fieldOverrides
     */
    public function execute(Company $primary, Company $secondary, array $fieldOverrides = []): Company
    {
        // Merging a record into itself would treat each of its own associations as a duplicate of itself and delete
        // them, then soft-delete the record.
        throw_if($primary->is($secondary), InvalidArgumentException::class, 'A company cannot be merged into itself.');

        app(TenantGuard::class)->assertSameTenant($primary, $secondary);

        return DB::transaction(function () use ($primary, $secondary, $fieldOverrides): Company {
            // 1. Fill empty primary fields from secondary
            $fillableAttributes = ['domain', 'phone', 'industry', 'account_tier', 'owner_id', 'team_id'];
            foreach ($fillableAttributes as $attr) {
                if (empty($primary->getAttribute($attr)) && ! empty($secondary->getAttribute($attr))) {
                    $primary->setAttribute($attr, $secondary->getAttribute($attr));
                }
            }

            // Apply explicit field overrides
            foreach ($fieldOverrides as $key => $value) {
                $primary->setAttribute($key, $value);
            }

            // Intent score: take higher score
            $primary->intent_score = max((int) $primary->intent_score, (int) $secondary->intent_score);
            $primary->intent_surge = $primary->intent_surge || $secondary->intent_surge;

            // Health score: take average
            $primary->health_score = (int) round(((int) $primary->health_score + (int) $secondary->health_score) / 2);

            // Merge custom properties
            $primaryProps = $primary->properties ?? [];
            $secondaryProps = $secondary->properties ?? [];
            $primary->properties = array_merge($secondaryProps, $primaryProps);

            // Lifecycle stage dates: keep the earliest
            $this->merger->keepEarliestStageDates($primary, $secondary);

            $primary->save();

            // 2. Move activities, associations (contacts and deals included), list memberships,
            //    property history, and lifecycle transitions
            $this->merger->moveCoreRecords($primary, $secondary);

            // 3. Let other packages move the records they own, before the health score is recalculated
            CompaniesMerged::dispatch($primary, $secondary);

            // 4. Log audit note
            $primary->logActivity(
                type: ActivityType::Note,
                title: 'Company Merged',
                body: "Merged duplicate company {$secondary->name} (ID #{$secondary->id}) into this record."
            );

            // 5. Recalculate Health Score on master
            app(CalculateCustomerHealthScoreAction::class)->execute($primary);

            // 6. Soft-delete secondary record
            $secondary->delete();

            return $primary->fresh() ?? $primary;
        });
    }
}
