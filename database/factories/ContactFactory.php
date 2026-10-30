<?php

declare(strict_types=1);

namespace Odden\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Contact>
     */
    protected $model = Contact::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('(###) ###-####'),
            'lifecycle_stage' => fake()->randomElement(LifecycleStage::cases()),
            'properties' => [
                'annual_revenue' => fake()->numberBetween(10000, 500000),
                'preferred_contact_method' => fake()->randomElement(['email', 'phone', 'slack']),
            ],
            'owner_id' => null,
        ];
    }

    /**
     * Indicate that the contact is a customer.
     */
    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'lifecycle_stage' => LifecycleStage::Customer,
        ]);
    }

    /**
     * Indicate that the contact is an active lead.
     */
    public function lead(): static
    {
        return $this->state(fn (array $attributes) => [
            'lifecycle_stage' => LifecycleStage::Lead,
        ]);
    }
}
