<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobLike;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobLike>
 */
class JobLikeFactory extends Factory
{
    protected $model = JobLike::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'job_id' => Job::factory(),
        ];
    }
}
