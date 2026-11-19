<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\Rating;
use App\Services\RatingService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto genera la calificación del Cliente hacia el Prestador de un
 * Trabajo completado, ya emitida pero aún oculta por el doble ciego.
 *
 * @extends Factory<Rating>
 */
class RatingFactory extends Factory
{
    protected $model = Rating::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $enabledAt = now()->subDay();

        return [
            'job_id' => Job::factory()->completed(),
            'rater_user_id' => fn (array $attributes): int => Job::query()
                ->findOrFail($attributes['job_id'])
                ->client_user_id,
            'rated_user_id' => fn (array $attributes): int => Job::query()
                ->findOrFail($attributes['job_id'])
                ->provider_user_id,
            'score' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional()->sentence(),
            'is_visible' => false,
            'rated_at' => now(),
            'enabled_at' => $enabledAt,
            'window_closes_at' => $enabledAt->copy()->addDays(RatingService::WINDOW_DAYS),
        ];
    }

    /**
     * Fila habilitada pero sin emitir, como la crea RatingService::enableRatings().
     */
    public function placeholder(): static
    {
        return $this->state(fn (array $attributes): array => [
            'score' => RatingService::UNRATED_SCORE,
            'comment' => null,
            'is_visible' => false,
            'rated_at' => null,
        ]);
    }

    /**
     * Emitida y todavía oculta por el doble ciego.
     */
    public function issued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'score' => fake()->numberBetween(1, 5),
            'is_visible' => false,
            'rated_at' => now(),
        ]);
    }

    /**
     * Emitida y revelada.
     */
    public function revealed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'score' => fake()->numberBetween(1, 5),
            'is_visible' => true,
            'rated_at' => now(),
        ]);
    }
}
