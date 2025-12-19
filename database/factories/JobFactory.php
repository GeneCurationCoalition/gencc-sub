<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Job;
use App\Models\User;
use App\Models\Submitter;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Job>
 */
class JobFactory extends Factory
{
    protected $model = Job::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get or create a user
        $user = User::first() ?? User::factory()->create();

        // Get or create a submitter
        $submitter = Submitter::first() ?? Submitter::create([
            'name' => 'Test Submitter',
            'curie' => 'TEST:001',
            'type' => 0,
            'status' => 1,
        ]);

        return [
            'user_id' => $user->id,
            'submitter_id' => $submitter->id,
            'submission_date' => now(),
            'status' => Job::STATUS_DRAFT,
        ];
    }
}
