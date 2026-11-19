<?php

namespace Database\Factories;

use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderProfile>
 */
class ProviderProfileFactory extends Factory
{
    protected $model = ProviderProfile::class;

    /**
     * Perfil activo sin ciudad ni métricas calculadas.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'professional_description' => fake()->optional()->paragraph(),
            'location' => fake()->optional()->city(),
            'city_id' => null,
            'average_rating' => null,
            'compliance_index' => null,
            'completed_jobs_count' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
