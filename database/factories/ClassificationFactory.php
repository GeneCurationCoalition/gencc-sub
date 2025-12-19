<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Classification;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Classification>
 */
class ClassificationFactory extends Factory
{
    protected $model = Classification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $genccNumber = fake()->unique()->numberBetween(1, 999);

        return [
            'ident' => Str::uuid()->toString(),
            'curie' => 'GENCC:' . str_pad($genccNumber, 6, '0', STR_PAD_LEFT),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'abbreviation' => strtoupper(fake()->lexify('???')),
            'order' => fake()->numberBetween(1, 10),
            'status' => Classification::STATUS_ACTIVE,
        ];
    }
}
