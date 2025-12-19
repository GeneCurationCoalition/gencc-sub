<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Disease;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Disease>
 */
class DiseaseFactory extends Factory
{
    protected $model = Disease::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:' . str_pad($this->faker->unique()->numberBetween(1, 9999999), 7, '0', STR_PAD_LEFT),
            'name' => $this->faker->words(3, true) . ' disease',
            'description' => $this->faker->sentence(),
            'mondo_id' => null, // MONDO diseases have null mondo_id
            'status' => Disease::STATUS_ACTIVE,
            'xrefs' => [], // Empty JSON array (required by migration)
            'synonyms' => null, // nullable
            'scores' => [], // Empty JSON array (required by migration)
            'counts' => [], // Empty JSON array (required by migration)
            'activity' => [], // Empty JSON array (required by migration)
            'events' => [], // Empty JSON array (required by migration)
            'notes' => null, // nullable
        ];
    }

    /**
     * Create a MONDO disease
     */
    public function mondo(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:' . str_pad($this->faker->unique()->numberBetween(1, 9999999), 7, '0', STR_PAD_LEFT),
            'mondo_id' => null,
        ]);
    }

    /**
     * Create an OMIM disease
     */
    public function omim(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Disease::TYPE_OMIM,
            'curie' => 'OMIM:' . $this->faker->unique()->numberBetween(100000, 999999),
            'mondo_id' => null, // Can be set manually after creating MONDO disease
        ]);
    }

    /**
     * Create an Orphanet disease
     */
    public function orphanet(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Disease::TYPE_ORPHANET,
            'curie' => 'Orphanet:' . $this->faker->unique()->numberBetween(1, 999999),
            'mondo_id' => null, // Can be set manually after creating MONDO disease
        ]);
    }

    /**
     * Mark disease as deprecated
     */
    public function deprecated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Disease::STATUS_DEPRECATED,
            'deprecated_name' => $attributes['name'] ?? $this->faker->words(3, true) . ' disease',
        ]);
    }

    /**
     * Mark disease as removed
     */
    public function removed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Disease::STATUS_REMOVED,
        ]);
    }

    /**
     * Add xrefs to the disease
     */
    public function withXrefs(array $xrefs): static
    {
        return $this->state(fn (array $attributes) => [
            'xrefs' => $xrefs,
        ]);
    }
}
