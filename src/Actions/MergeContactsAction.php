<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Events\ContactsMerged;
use Odden\Core\Models\Contact;
use Odden\Core\Support\RecordMerger;
use Odden\Core\Support\TenantGuard;

class MergeContactsAction
{
    public function __construct(
        protected RecordMerger $merger
    ) {}

    /**
     * Merge a secondary duplicate contact into a primary contact, inside a transaction.
     * Merges fields and properties, moves everything Core owns (activities, associations, list
     * memberships, property history, lifecycle transitions) to the primary, dispatches
     * ContactsMerged so other packages can move their records, and soft-deletes the secondary.
     *
     * @param  array<string, mixed>  $fieldOverrides
     */
    public function execute(Contact $primary, Contact $secondary, array $fieldOverrides = []): Contact
    {
        // Merging a record into itself would treat each of its own associations as a duplicate of itself and delete
        // them, then soft-delete the record.
        throw_if($primary->is($secondary), InvalidArgumentException::class, 'A contact cannot be merged into itself.');

        app(TenantGuard::class)->assertSameTenant($primary, $secondary);

        return DB::transaction(function () use ($primary, $secondary, $fieldOverrides): Contact {
            // 1. Fill empty primary fields from secondary
            $fillableAttributes = ['first_name', 'last_name', 'phone', 'lifecycle_stage', 'lead_status', 'owner_id', 'team_id'];
            foreach ($fillableAttributes as $attr) {
                if (empty($primary->getAttribute($attr)) && ! empty($secondary->getAttribute($attr))) {
                    $primary->setAttribute($attr, $secondary->getAttribute($attr));
                }
            }

            // Apply explicit field overrides
            foreach ($fieldOverrides as $key => $value) {
                $primary->setAttribute($key, $value);
            }

            // Lead score: keep highest score
            $primary->lead_score = max((int) $primary->lead_score, (int) $secondary->lead_score);

            // Merge custom properties
            $primaryProps = $primary->properties ?? [];
            $secondaryProps = $secondary->properties ?? [];
            $primary->properties = array_merge($secondaryProps, $primaryProps);

            // Lifecycle stage dates: keep the earliest
            $this->merger->keepEarliestStageDates($primary, $secondary);

            $primary->save();

            // 2. Move activities, associations (deals included: Sales links them through
            //    associations), list memberships, property history, and lifecycle transitions
            $this->merger->moveCoreRecords($primary, $secondary);

            // 3. Let other packages move the records they own
            ContactsMerged::dispatch($primary, $secondary);

            // 4. Log audit note
            $primary->logActivity(
                type: ActivityType::Note,
                title: 'Contact Merged',
                body: "Merged duplicate contact {$secondary->email} (ID #{$secondary->id}) into this record."
            );

            // 5. Soft-delete secondary record
            $secondary->delete();

            return $primary->fresh() ?? $primary;
        });
    }
}
