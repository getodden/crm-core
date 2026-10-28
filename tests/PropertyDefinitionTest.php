<?php

declare(strict_types=1);

namespace Odden\Core\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Models\Contact;
use Odden\Core\Models\PropertyDefinition;

class PropertyDefinitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_property_definition(): void
    {
        $property = PropertyDefinition::create([
            'entity_type' => 'contact',
            'name' => 'lead_score',
            'label' => 'Lead Score',
            'type' => PropertyType::Number,
            'group_name' => 'qualification',
            'is_required' => false,
            'is_searchable' => true,
        ]);

        $this->assertDatabaseHas('odden_properties', [
            'entity_type' => 'contact',
            'name' => 'lead_score',
            'type' => PropertyType::Number->value,
        ]);

        $this->assertSame(PropertyType::Number, $property->type);
        $this->assertTrue($property->is_searchable);
    }

    public function test_can_scope_properties_for_specific_entity(): void
    {
        PropertyDefinition::create([
            'entity_type' => 'contact',
            'name' => 'job_title',
            'label' => 'Job title',
            'type' => PropertyType::Text,
        ]);

        PropertyDefinition::create([
            'entity_type' => 'company',
            'name' => 'annual_revenue',
            'label' => 'Annual Revenue',
            'type' => PropertyType::Number,
        ]);

        $contactProps = PropertyDefinition::forEntity('contact')->get();
        $companyProps = PropertyDefinition::forEntity('company')->get();

        $this->assertCount(1, $contactProps);
        $this->assertSame('job_title', $contactProps->first()->name);

        $this->assertCount(1, $companyProps);
        $this->assertSame('annual_revenue', $companyProps->first()->name);
    }

    private function definePropertiesForValidation(): void
    {
        $entity = (new Contact)->getMorphClass();

        // Definitions saved by the Filament panel use the short key, so it must match too.
        PropertyDefinition::create(['entity_type' => 'contact', 'name' => 'plan', 'label' => 'Plan', 'type' => PropertyType::Select, 'options' => ['starter' => 'Starter', 'growth' => 'Growth']]);
        PropertyDefinition::create(['entity_type' => $entity, 'name' => 'seats', 'label' => 'Seats', 'type' => PropertyType::Number, 'is_required' => true]);
        PropertyDefinition::create(['entity_type' => $entity, 'name' => 'regions', 'label' => 'Regions', 'type' => PropertyType::MultiSelect, 'options' => ['choices' => ['emea', 'apac']]]);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string> The property keys that failed validation.
     */
    private function failedKeys(Contact $contact, array $properties): array
    {
        try {
            $contact->setProperties($properties, validate: true);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    }

    public function test_valid_properties_pass_and_undefined_ones_are_ignored(): void
    {
        $this->definePropertiesForValidation();
        $contact = Contact::factory()->create();

        $contact->setProperties(['plan' => 'growth', 'seats' => '12', 'regions' => ['emea'], 'anything' => 'goes'], validate: true);

        $this->assertSame('growth', $contact->getProperty('plan'));
        $this->assertSame('goes', $contact->getProperty('anything'));
    }

    public function test_missing_required_wrong_type_and_out_of_list_values_are_rejected(): void
    {
        $this->definePropertiesForValidation();
        $contact = Contact::factory()->create();
        $before = $contact->properties;

        $this->assertSame(['properties.seats'], $this->failedKeys($contact, ['plan' => 'growth']));
        $this->assertSame(['properties.seats'], $this->failedKeys($contact, ['seats' => 'many']));
        $this->assertSame(['properties.plan'], $this->failedKeys($contact, ['seats' => 3, 'plan' => 'enterprise']));
        $this->assertSame(['properties.regions.1'], $this->failedKeys($contact, ['seats' => 3, 'regions' => ['emea', 'mars']]));

        // Nothing was written by the failed attempts.
        $this->assertSame($before, $contact->properties);
    }

    public function test_properties_are_not_validated_unless_requested(): void
    {
        $this->definePropertiesForValidation();
        $contact = Contact::factory()->create();

        $contact->setProperties(['plan' => 'enterprise']);

        $this->assertSame('enterprise', $contact->getProperty('plan'));
    }
}
