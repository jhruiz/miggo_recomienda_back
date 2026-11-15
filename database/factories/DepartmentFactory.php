<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'name' => fake()->unique()->state(),
        ];
    }

    /**
     * Attach the department to an existing country.
     */
    public function forCountry(Country $country): static
    {
        return $this->state(fn (array $attributes): array => [
            'country_id' => $country->id,
        ]);
    }
}
