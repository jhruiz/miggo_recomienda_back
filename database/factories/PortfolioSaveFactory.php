<?php

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\PortfolioSave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioSave>
 */
class PortfolioSaveFactory extends Factory
{
    protected $model = PortfolioSave::class;

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
