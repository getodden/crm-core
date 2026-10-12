<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\AssociationCardinality;
use Odden\Core\Events\RecordsAssociated;
use Odden\Core\Exceptions\CardinalityViolationException;
use Odden\Core\Exceptions\InvalidAssociationException;
use Odden\Core\Models\Association;
use Odden\Core\Models\AssociationType;
use Odden\Core\Support\TenantGuard;

class AssociateRecordsAction
{
    /**
     * Associate two records together with an optional relationship type, label, and cardinality check.
     *
     * @throws CardinalityViolationException
     * @throws InvalidAssociationException
     */
    public function execute(
        Model $parent,
        Model $child,
        string|AssociationType $type = 'default',
        ?string $label = null
    ): Association {
        app(TenantGuard::class)->assertSameTenant($parent, $child);

        $associationType = null;
        $typeName = 'default';

        if ($type instanceof AssociationType) {
            $associationType = $type;
            $typeName = $associationType->name;
            $label ??= $associationType->label;
        } elseif (is_string($type)) {
            $typeName = $type;
            $associationType = AssociationType::findForRecord($type, $parent);
            if ($associationType !== null) {
                $label ??= $associationType->label;
            }
        }

        if ($associationType !== null) {
            $this->enforceRecordTypes($parent, $child, $associationType);
        }

        // The cardinality check and the write happen under a lock on the type, so two
        // concurrent links can't both pass the check.
        [$association, $isNew] = DB::transaction(function () use ($parent, $child, $associationType, $typeName, $label): array {
            if ($associationType !== null && $associationType->cardinality !== AssociationCardinality::ManyToMany) {
                AssociationType::query()->whereKey($associationType->id)->lockForUpdate()->first();
                $this->enforceCardinality($parent, $child, $associationType);
            }

            /** @var Association $association */
            $association = Association::firstOrNew([
                'parent_type' => $parent->getMorphClass(),
                'parent_id' => $parent->getKey(),
                'child_type' => $child->getMorphClass(),
                'child_id' => $child->getKey(),
                'type' => $typeName,
            ]);

            $isNew = ! $association->exists;

            if ($associationType !== null) {
                $association->association_type_id = $associationType->id;
            }

            if ($label !== null) {
                $association->label = $label;
            }

            $association->save();

            return [$association, $isNew];
        });

        if ($isNew) {
            event(new RecordsAssociated($association));
        }

        return $association;
    }

    /**
     * Enforce the record types an association type allows. The two records must be the type's
     * `from_record_type` and `to_record_type`, in either order; when only one is set, one of the
     * two records must be of that type.
     *
     * @throws InvalidAssociationException
     */
    protected function enforceRecordTypes(Model $parent, Model $child, AssociationType $type): void
    {
        $from = $type->from_record_type;
        $to = $type->to_record_type;

        if ($from === null && $to === null) {
            return;
        }

        $records = [$parent->getMorphClass(), $child->getMorphClass()];

        $allowed = match (true) {
            $from !== null && $to !== null => ($records[0] === $from && $records[1] === $to) || ($records[0] === $to && $records[1] === $from),
            $from !== null => in_array($from, $records, true),
            default => in_array($to, $records, true),
        };

        if (! $allowed) {
            $expected = $from !== null && $to !== null ? "{$from} and {$to}" : ($from ?? $to);

            throw new InvalidAssociationException("Association type [{$type->name}] links {$expected} records, not {$records[0]} and {$records[1]}.");
        }
    }

    /**
     * Enforce cardinality rules for association types.
     *
     * When the type names two different record types, a record's type decides its side, whichever
     * way round the records were passed: linking a contact to a second company is refused whether
     * it's called on the company or on the contact.
     */
    protected function enforceCardinality(Model $parent, Model $child, AssociationType $type): void
    {
        $oriented = $type->from_record_type !== null && $type->to_record_type !== null
            && $type->from_record_type !== $type->to_record_type;

        if ($oriented && $parent->getMorphClass() === $type->to_record_type) {
            [$parent, $child] = [$child, $parent];
        }

        if ($type->cardinality === AssociationCardinality::OneToOne) {
            if ($this->linkedToAnother($type, $parent, 'parent', $child, $oriented)) {
                throw new CardinalityViolationException("Association type [{$type->name}] has One-to-One cardinality and parent record already has an association.");
            }

            if ($this->linkedToAnother($type, $child, 'child', $parent, $oriented)) {
                throw new CardinalityViolationException("Association type [{$type->name}] has One-to-One cardinality and child record is already associated with another record.");
            }
        } elseif ($type->cardinality === AssociationCardinality::OneToMany) {
            // In One-to-Many: a child can only belong to at most one parent
            if ($this->linkedToAnother($type, $child, 'child', $parent, $oriented)) {
                throw new CardinalityViolationException("Association type [{$type->name}] has One-to-Many cardinality and child record already belongs to a parent.");
            }
        }
    }

    /**
     * Whether $record already has a link of this type, on the given side (or on either side when
     * the type is oriented by record type), to a record other than $other.
     */
    protected function linkedToAnother(AssociationType $type, Model $record, string $side, Model $other, bool $eitherSide): bool
    {
        return Association::query()
            ->where('association_type_id', $type->id)
            ->where(function (Builder $query) use ($record, $side, $other, $eitherSide): void {
                foreach ($eitherSide ? ['parent', 'child'] : [$side] as $recordSide) {
                    $otherSide = $recordSide === 'parent' ? 'child' : 'parent';

                    $query->orWhere(fn (Builder $link) => $link
                        ->where("{$recordSide}_type", $record->getMorphClass())
                        ->where("{$recordSide}_id", $record->getKey())
                        ->where(fn (Builder $q) => $q
                            ->where("{$otherSide}_type", '!=', $other->getMorphClass())
                            ->orWhere("{$otherSide}_id", '!=', $other->getKey())));
                }
            })
            ->exists();
    }
}
