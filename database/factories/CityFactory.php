<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->unique()->city(),
        ];
    }

    /**
     * Attach the city to an existing department.
     */
    public function forDepartment(Department $department): static
    {
        return $this->state(fn (array $attributes): array => [
            'department_id' => $department->id,
        ]);
    }
}
