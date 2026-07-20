<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Submission;
use App\Models\Job;
use App\Models\Gene;
use App\Models\Disease;
use App\Models\Classification;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Submission>
 */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get or create required related models
        $job = Job::first() ?? Job::factory()->create();
        $gene = Gene::first();
        $disease = Disease::first();
        $classification = Classification::first();

        return [
            'job_id' => $job->id,
            'user_id' => $job->user_id,
            'submitter_id' => $job->submitter_id,
            'gene_id' => $gene?->id ?? 1,
            'disease_id' => $disease?->id ?? 1,
            'original_disease_id' => $disease?->id ?? 1,  // Usually same as disease_id for new submissions
            'classification_id' => $classification?->id ?? 1,
            'local_key' => 'TEST-' . fake()->unique()->numberBetween(1000, 9999),
            // created_at is auto-set by Laravel
            'status' => Submission::STATUS_DRAFT_NEW,
            'submission_data' => [],
            'released_submission_data' => [],
            'submission_errors' => null,
            'history' => null,
            'evidence' => null,
            'tags' => null,
            'is_live' => false,  // Default for new submissions (only true when published and current)
        ];
    }
}
