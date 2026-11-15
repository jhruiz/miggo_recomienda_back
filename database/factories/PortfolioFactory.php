<?php

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Portfolio>
 */
class PortfolioFactory extends Factory
{
    protected $model = Portfolio::class;

    /**
     * provider_profile_id no tiene valor por defecto (no existe ProviderProfileFactory):
     * usar forProvider() o pasar el atributo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function forProvider(ProviderProfile $profile): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider_profile_id' => $profile->id,
        ]);
    }
}
