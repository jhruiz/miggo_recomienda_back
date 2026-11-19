<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobSave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobSave>
 */
class JobSaveFactory extends Factory
{
    protected $model = JobSave::class;

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
