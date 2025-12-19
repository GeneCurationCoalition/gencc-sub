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
        $submitterNumber = fake()->unique()->numberBetween(1, 9999);

        return [
            'ident' => Str::uuid()->toString(),
            'curie' => 'GENCC_SUB:' . str_pad($submitterNumber, 4, '0', STR_PAD_LEFT),
            'name' => fake()->company(),
            'website' => fake()->url(),
            'status' => Submitter::STATUS_ACTIVE,
        ];
    }
}
