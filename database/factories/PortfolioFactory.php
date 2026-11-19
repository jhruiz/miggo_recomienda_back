<?php

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\ProviderProfile;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Portfolio>
 */
class PortfolioFactory extends Factory
{
    protected $model = Portfolio::class;

    /**
     * Por defecto crea un Perfil_Prestador activo; usar forProvider() para uno concreto.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_profile_id' => ProviderProfile::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'service_category_id' => ServiceCategory::factory(),
        ];
    }

    public function forProvider(ProviderProfile $profile): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider_profile_id' => $profile->id,
        ]);
    }
}
