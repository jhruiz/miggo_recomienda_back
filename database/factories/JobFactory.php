<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\Quote;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Las partes del Trabajo se toman de la cotizaci√≥n que lo origina.
 *
 * @extends Factory<Job>
 */
class JobFactory extends Factory
{
    protected $model = Job::class;

    /**
     * Trabajo en curso (sin finalizaci√≥n marcada por el Prestador).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'client_user_id' => fn (array $attributes): int => Quote::query()
                ->findOrFail($attributes['quote_id'])
                ->client_user_id,
            'provider_user_id' => fn (array $attributes): int => Quote::query()
                ->findOrFail($attributes['quote_id'])
                ->provider_user_id,
            'status' => Job::STATUS_EN_PROGRESO,
            'start_date' => now()->subDays(10)->toDateString(),
            'estimated_delivery_date' => now()->addDays(5)->toDateString(),
            'actual_completion_date' => null,
            'cancellation_reason' => null,
            'rejection_reason' => null,
            'client_requested_cancel' => false,
            'provider_requested_cancel' => false,
        ];
    }

    /**
     * Con categorÌa (la indicada o una nueva).
     */
    public function withCategory(ServiceCategory|int|null $category = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'service_category_id' => $category instanceof ServiceCategory
                ? $category->id
                : ($category ?? ServiceCategory::factory()),
        ]);
    }

    public function withoutCategory(): static
    {
        return $this->state(fn (array $attributes): array => [
            'service_category_id' => null,
        ]);
    }

    /**
     * Originado en una CotizaciÛn directa; hereda su categorÌa (como har· createJob).
     */
    public function fromDirectQuote(ServiceCategory|int|null $category = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'quote_id' => Quote::factory()->direct($category),
            'service_category_id' => fn (array $attributes): ?int => Quote::query()
                ->findOrFail($attributes['quote_id'])
                ->service_category_id,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Job::STATUS_EN_PROGRESO,
            'actual_completion_date' => null,
        ]);
    }

    /**
     * En curso con la finalizaci√≥n marcada por el Prestador, a la espera del Cliente.
     */
    public function closedByProvider(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Job::STATUS_EN_PROGRESO,
            'actual_completion_date' => now()->toDateString(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Job::STATUS_COMPLETADO,
            'actual_completion_date' => now()->toDateString(),
        ]);
    }

    /**
     * Cancelado por mutuo acuerdo, con el motivo de cada parte.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Job::STATUS_CANCELADO,
            'actual_completion_date' => null,
            'client_requested_cancel' => true,
            'provider_requested_cancel' => true,
            'cancellation_reason' => [
                'client' => fake()->sentence(),
                'provider' => fake()->sentence(),
            ],
        ]);
    }
}
