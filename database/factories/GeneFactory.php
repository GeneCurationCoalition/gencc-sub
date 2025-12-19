<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Gene;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Gene>
 */
class GeneFactory extends Factory
{
    protected $model = Gene::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hgncNumber = fake()->unique()->numberBetween(1, 99999);

        return [
            'ident' => Str::uuid()->toString(),
            'hgnc_id' => 'HGNC:' . $hgncNumber,
            'symbol' => strtoupper(fake()->lexify('????')),
            'name' => fake()->words(3, true),
            'locus_group' => 'protein-coding gene',
            'locus_type' => 'gene with protein product',
            'location' => fake()->numerify('##q##.#'),
            'status' => Gene::STATUS_ACTIVE,
        ];
    }
}
