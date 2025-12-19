<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Inheritance;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Inheritance>
 */
class InheritanceFactory extends Factory
{
    protected $model = Inheritance::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hpNumber = fake()->unique()->numberBetween(1, 99999);

        return [
            'ident' => Str::uuid()->toString(),
            'curie' => 'HP:' . str_pad($hpNumber, 7, '0', STR_PAD_LEFT),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'abbreviation' => strtoupper(fake()->lexify('??')),
            'status' => Inheritance::STATUS_ACTIVE,
        ];
    }
}
