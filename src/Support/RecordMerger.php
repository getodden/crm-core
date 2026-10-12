<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Enums\AssociationCardinality;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\AssociationType;

/**
 * Moves the records Core owns from a duplicate onto the record it is merged into.
 *
 * Used by MergeContactsAction and MergeCompaniesAction inside their transaction. Data owned by
 * other packages is moved by their listeners for ContactsMerged and CompaniesMerged.
 */
final class RecordMerger
{
    /**
     * Lifecycle stage date columns on contacts and companies. The earliest date is kept.
     */
    private const array STAGE_DATE_COLUMNS = [
        'became_subscriber_at',
        'became_lead_at',
        'became_marketing_qualified_lead_at',
        'became_sales_qualified_lead_at',
        'became_opportunity_at',
        'became_customer_at',
        'became_evangelist_at',
        'became_other_at',
    ];

    /**
     * Set each lifecycle stage date on the primary to the earlier of the two records' dates.
     * Only changes attributes; the caller saves the primary.
     */
    public function keepEarliestStageDates(Model $primary, Model $secondary): void
    {
        foreach (self::STAGE_DATE_COLUMNS as $column) {
            $secondaryDate = $secondary->getAttribute($column);
            if ($secondaryDate === null) {
                continue;
            }

            $primaryDate = $primary->getAttribute($column);
            if ($primaryDate === null || $secondaryDate < $primaryDate) {
                $primary->setAttribute($column, $secondaryDate);
            }
        }
    }

    /**
     * Move the saved primary to the further-along lifecycle stage of the two records
     * (LifecycleStateMachine::furthestStage(), so a customer is never moved backwards), or to
     * the stage the caller chose. The change goes through TransitionLifecycleStageAction with
     * source `merge`, so it is recorded like any other and became_<stage>_at is stamped when
     * neither record had reached the stage. A chosen stage is validated by the state machine;
     * the automatic one is not, because the person already reached it as the other record.
     */
    public function mergeLifecycleStage(Model $primary, Model $secondary, LifecycleStage|string|null $chosen = null): void
    {
        $current = TransitionLifecycleStageAction::stageOf($primary->getAttribute('lifecycle_stage'));

        $target = match (true) {
            $chosen instanceof LifecycleStage => $chosen,
            is_string($chosen) => LifecycleStage::from($chosen),
            default => app(LifecycleStateMachine::class)->furthestStage(
                $current,
                TransitionLifecycleStageAction::stageOf($secondary->getAttribute('lifecycle_stage')),
            ),
        };

        if ($target === null || $target === $current) {
            return;
        }

        app(TransitionLifecycleStageAction::class)->execute($primary, $target, 'merge', force: $chosen === null);
    }

    /**
     * Move activities, associations, list memberships, property history, and lifecycle stage
     * transitions from $secondary to $primary.
     */
    public function moveCoreRecords(Model $primary, Model $secondary): void
    {
        $this->moveMorphOwner(config('odden-core.tables.activities', 'odden_activities'), 'subject', $primary, $secondary);
        $this->moveMorphOwner(config('odden-core.tables.property_history', 'odden_property_history'), 'auditable', $primary, $secondary);
        $this->moveMorphOwner(config('odden-core.tables.lifecycle_stage_transitions', 'odden_lifecycle_stage_transitions'), 'record', $primary, $secondary);
        $this->moveListMemberships($primary, $secondary);
        $this->moveAssociations($primary, $secondary);
    }

    private function moveMorphOwner(string $table, string $morph, Model $primary, Model $secondary): void
    {
        DB::table($table)
            ->where("{$morph}_type", $secondary->getMorphClass())
            ->where("{$morph}_id", $secondary->getKey())
            ->update(["{$morph}_type" => $primary->getMorphClass(), "{$morph}_id" => $primary->getKey()]);
    }

    private function moveListMemberships(Model $primary, Model $secondary): void
    {
        $table = config('odden-core.tables.list_memberships', 'odden_list_memberships');

        $primaryListIds = DB::table($table)
            ->where('member_type', $primary->getMorphClass())
            ->where('member_id', $primary->getKey())
            ->pluck('list_id');

        $secondaryMemberships = DB::table($table)
            ->where('member_type', $secondary->getMorphClass())
            ->where('member_id', $secondary->getKey());

        // The primary is already on these lists: a second row would break the unique index.
        (clone $secondaryMemberships)->whereIn('list_id', $primaryListIds)->delete();

        $secondaryMemberships->update(['member_type' => $primary->getMorphClass(), 'member_id' => $primary->getKey()]);
    }

    /**
     * Move the secondary's links to the primary. A moved link is dropped when it would link the
     * primary to itself, when the primary already has the same link in either direction, or when
     * the primary already holds the one link its association type allows it: the primary's own
     * links are kept.
     */
    private function moveAssociations(Model $primary, Model $secondary): void
    {
        $table = config('odden-core.tables.associations', 'odden_associations');
        $primaryType = $primary->getMorphClass();
        $primaryId = $primary->getKey();
        $types = [];

        foreach (['parent' => 'child', 'child' => 'parent'] as $side => $otherSide) {
            $associations = DB::table($table)
                ->where("{$side}_type", $secondary->getMorphClass())
                ->where("{$side}_id", $secondary->getKey())
                ->orderBy('id')
                ->get();

            foreach ($associations as $association) {
                $otherType = (string) $association->{"{$otherSide}_type"};
                $otherId = $association->{"{$otherSide}_id"};

                // A link between the two merged records would become a link from the primary to itself.
                $isSelfLink = ($otherType === $primaryType && (string) $otherId === (string) $primaryId)
                    || ($otherType === $secondary->getMorphClass() && (string) $otherId === (string) $secondary->getKey());

                $typeId = $association->association_type_id ?? null;
                $type = $typeId === null ? null : ($types[$typeId] ??= AssociationType::query()->find($typeId));

                if ($isSelfLink
                    || $this->primaryHasLink($table, $primaryType, $primaryId, $otherType, $otherId, (string) $association->type)
                    || ($type instanceof AssociationType && $this->primaryIsAtLimit($table, $type, $side, $primary, $secondary))) {
                    DB::table($table)->where('id', $association->id)->delete();

                    continue;
                }

                DB::table($table)
                    ->where('id', $association->id)
                    ->update(["{$side}_type" => $primaryType, "{$side}_id" => $primaryId]);
            }
        }
    }

    /**
     * Whether the primary is already linked to the other record with this type, either way round.
     */
    private function primaryHasLink(string $table, string $primaryType, mixed $primaryId, string $otherType, mixed $otherId, string $type): bool
    {
        return DB::table($table)
            ->where('type', $type)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $link) => $link
                    ->where('parent_type', $primaryType)->where('parent_id', $primaryId)
                    ->where('child_type', $otherType)->where('child_id', $otherId))
                ->orWhere(fn (Builder $link) => $link
                    ->where('parent_type', $otherType)->where('parent_id', $otherId)
                    ->where('child_type', $primaryType)->where('child_id', $primaryId)))
            ->exists();
    }

    /**
     * Whether the primary, taking the secondary's place on $side of a link of this type, already
     * has (to a record other than the secondary) the only link of the type it may have, following AssociateRecordsAction: One-to-One
     * allows one link per record, One-to-Many one parent per child. When the type names two
     * different record types, the record's type decides its side.
     */
    private function primaryIsAtLimit(string $table, AssociationType $type, string $side, Model $primary, Model $secondary): bool
    {
        $primaryType = $primary->getMorphClass();
        $primaryId = $primary->getKey();

        if ($type->cardinality === AssociationCardinality::ManyToMany) {
            return false;
        }

        $oriented = $type->from_record_type !== null && $type->to_record_type !== null
            && $type->from_record_type !== $type->to_record_type;

        $role = $oriented ? ($primaryType === $type->to_record_type ? 'child' : 'parent') : $side;

        if ($type->cardinality === AssociationCardinality::OneToMany && $role === 'parent') {
            return false;
        }

        return DB::table($table)
            ->where('association_type_id', $type->id)
            ->where(function (Builder $query) use ($oriented, $role, $primaryType, $primaryId): void {
                foreach ($oriented ? ['parent', 'child'] : [$role] as $column) {
                    $query->orWhere(fn (Builder $link) => $link
                        ->where("{$column}_type", $primaryType)
                        ->where("{$column}_id", $primaryId));
                }
            })
            // A link to the secondary is about to be dropped as a link to itself.
            ->whereNot(fn (Builder $link) => $link
                ->where('parent_type', $secondary->getMorphClass())->where('parent_id', $secondary->getKey()))
            ->whereNot(fn (Builder $link) => $link
                ->where('child_type', $secondary->getMorphClass())->where('child_id', $secondary->getKey()))
            ->exists();
    }
}
