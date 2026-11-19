<?php

namespace Database\Factories;

use App\Enums\FeedEventType;
use App\Enums\FeedSubjectType;
use App\Models\FeedEvent;
use App\Models\Job;
use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedEvent>
 */
class FeedEventFactory extends Factory
{
    protected $model = FeedEvent::class;

    /**
     * Por defecto el sujeto es un Portafolio.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject_type' => FeedSubjectType::Portfolio,
            'portfolio_id' => Portfolio::factory(),
            'job_id' => null,
            'service_category_id' => null,
            'type' => FeedEventType::View,
            'created_at' => now(),
        ];
    }

    public function forPortfolio(Portfolio $portfolio): static
    {
        return $this->state(fn (array $attributes): array => [
            'subject_type' => FeedSubjectType::Portfolio,
            'portfolio_id' => $portfolio->id,
            'job_id' => null,
        ]);
    }

    public function forJob(Job $job): static
    {
        return $this->state(fn (array $attributes): array => [
            'subject_type' => FeedSubjectType::Job,
            'job_id' => $job->id,
            'portfolio_id' => null,
        ]);
    }

    public function ofType(FeedEventType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }
}
