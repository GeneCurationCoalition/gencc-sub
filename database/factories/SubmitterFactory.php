<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Submitter;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Submitter>
 */
class SubmitterFactory extends Factory
{
    protected $model = Submitter::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ident' => Str::uuid()->toString(),
            'name' => fake()->company(),
            'website' => fake()->url(),
            'status' => Submitter::STATUS_ACTIVE,
        ];
    }
}
