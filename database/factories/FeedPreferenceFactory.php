<?php

namespace Database\Factories;

use App\Models\FeedPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedPreference>
 */
class FeedPreferenceFactory extends Factory
{
    protected $model = FeedPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'behavior_logging' => false,
            'privacy_notice_answered_at' => null,
            'consented_at' => null,
        ];
    }

    /**
     * Notice answered with consent: behavior logging on.
     */
    public function enabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'behavior_logging' => true,
            'privacy_notice_answered_at' => now(),
            'consented_at' => now(),
        ]);
    }

    /**
     * Notice answered declining: behavior logging off.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'behavior_logging' => false,
            'privacy_notice_answered_at' => now(),
            'consented_at' => null,
        ]);
    }
}
