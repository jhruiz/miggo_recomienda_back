<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto genera una cotización directa (sin solicitud) aceptada, que es la
 * que origina un Trabajo. La categoría se añade en la tarea 2.5.
 *
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_user_id' => User::factory(),
            'client_user_id' => User::factory(),
            'quote_request_id' => null,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => fake()->paragraph(),
            'total_price' => fake()->randomFloat(2, 10000, 5000000),
            'delivery_days' => fake()->numberBetween(1, 60),
            'expires_at' => now()->addDays(15)->toDateString(),
            'status' => Quote::STATUS_ACEPTADA,
        ];
    }

    /**
     * Cotización directa: sin solicitud previa.
     */
    public function direct(ServiceCategory|int|null $category = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => Quote::TYPE_DIRECTA,
            'quote_request_id' => null,
            'service_category_id' => $category instanceof ServiceCategory ? $category->id : $category,
        ]);
    }

    /**
     * Cotización pública: responde a una solicitud creada para el mismo cliente.
     */
    public function public(ServiceCategory|int|null $category = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => Quote::TYPE_PUBLICA,
            'quote_request_id' => fn (array $attributes): int => QuoteRequest::factory()
                ->create(['client_user_id' => $attributes['client_user_id']])
                ->id,
            'service_category_id' => $category instanceof ServiceCategory ? $category->id : $category,
        ]);
    }

    /**
     * Con categor�a (la indicada o una nueva).
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

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Quote::STATUS_PENDIENTE,
        ]);
    }
}
