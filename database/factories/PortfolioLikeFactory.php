<?php

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\PortfolioLike;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioLike>
 */
class PortfolioLikeFactory extends Factory
{
    protected $model = PortfolioLike::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'portfolio_id' => Portfolio::factory(),
        ];
    }
}
