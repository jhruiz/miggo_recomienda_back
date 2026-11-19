<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPhoto>
 */
class JobPhotoFactory extends Factory
{
    protected $model = JobPhoto::class;

    /**
     * Por defecto la foto está pendiente (sin conversiones listas).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'conversions_ready_at' => null,
            'thumb_width' => null,
            'thumb_height' => null,
            'medium_width' => null,
            'medium_height' => null,
        ];
    }

    /**
     * Conversiones generadas. Miniatura 400x400 y mediana con proporción coherente.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'conversions_ready_at' => now(),
            'thumb_width' => 400,
            'thumb_height' => 400,
            'medium_width' => fake()->numberBetween(400, 1200),
            'medium_height' => fake()->numberBetween(300, 1600),
        ]);
    }

    /**
     * Conversiones aún no generadas.
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
