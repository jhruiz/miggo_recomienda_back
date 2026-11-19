<?php

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\PortfolioItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioItem>
 */
class PortfolioItemFactory extends Factory
{
    protected $model = PortfolioItem::class;

    /**
     * Por defecto la foto está pendiente (sin conversiones listas).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portfolio_id' => Portfolio::factory(),
            'description' => fake()->optional()->sentence(),
            'conversions_ready_at' => null,
            'thumb_width' => null,
            'thumb_height' => null,
            'medium_width' => null,
            'medium_height' => null,
        ];
    }

    /**
     * Conversiones generadas: elegible para el Muro. Miniatura 400x400 y mediana con proporción coherente.
     */
    public function ready(): static
    {
        return $this->state(function (array $attributes): array {
            $mediumWidth = fake()->numberBetween(400, 1200);
            $mediumHeight = fake()->numberBetween(300, 1600);

            return [
                'conversions_ready_at' => now(),
                'thumb_width' => 400,
                'thumb_height' => 400,
                'medium_width' => $mediumWidth,
                'medium_height' => $mediumHeight,
            ];
        });
    }

    /**
     * Conversiones aún no generadas: fuera del Muro.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'conversions_ready_at' => null,
            'thumb_width' => null,
            'thumb_height' => null,
            'medium_width' => null,
            'medium_height' => null,
        ]);
    }
}
