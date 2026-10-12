<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use InvalidArgumentException;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Events\CustomObjectRecordCreated;
use Odden\Core\Models\CustomObjectDefinition;
use Odden\Core\Models\CustomObjectRecord;

class CreateCustomObjectRecordAction
{
    /**
     * Create a record under a custom object definition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(
        CustomObjectDefinition|string $definition,
        array $attributes
    ): CustomObjectRecord {
        if (is_string($definition)) {
            $found = CustomObjectDefinition::findForTeam($definition, $attributes['team_id'] ?? null, app(TenantContext::class)->id());
            if ($found === null) {
                throw new InvalidArgumentException("Custom object definition [{$definition}] does not exist.");
            }
            $definition = $found;
        }

        $attributes['definition_id'] = $definition->getKey();

        // Resolve record display name from attributes or primary display property
        if (empty($attributes['name'])) {
            $primaryProperty = $definition->primary_display_property;
            if (isset($attributes['properties'][$primaryProperty]) && is_scalar($attributes['properties'][$primaryProperty])) {
                $attributes['name'] = (string) $attributes['properties'][$primaryProperty];
            } else {
                $attributes['name'] = "{$definition->singular_label} #".uniqid();
            }
        }

        /** @var CustomObjectRecord $record */
        $record = CustomObjectRecord::create($attributes);

        event(new CustomObjectRecordCreated($record));

        return $record;
    }
}
