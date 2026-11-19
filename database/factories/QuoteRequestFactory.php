<?php

namespace Database\Factories;

use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteRequest>
 */
class QuoteRequestFactory extends Factory
{
    protected $model = QuoteRequest::class;

    /**
     * Solicitud abierta con cliente y categoría propios.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_user_id' => User::factory(),
            'service_category_id' => ServiceCategory::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'location' => fake()->optional()->city(),
            'city_id' => null,
            'estimated_budget' => fake()->optional()->randomFloat(2, 10000, 5000000),
            'status' => QuoteRequest::STATUS_ABIERTA,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuoteRequest::STATUS_CERRADA,
        ]);
    }
}
