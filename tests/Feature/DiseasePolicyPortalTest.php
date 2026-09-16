<?php

namespace Tests\Feature;

use App\Models\Disease;
use App\Models\Job;
use App\Models\Submission;
use App\Models\Submitter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The non-blocking warning for a submission curated against an obsolete MONDO
 * term, which stays acceptable under the disease mapping policy.
 */
class DiseasePolicyPortalTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Submitter $submitter;

    protected Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submitter = Submitter::create([
            'name' => 'Test Submitter',
            'curie' => 'GENCC:000101',
            'type' => 0,
            'status' => 1,
        ]);

        $this->user = User::factory()->create(['submitter_id' => $this->submitter->id]);

        $this->job = Job::factory()->create([
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'status' => Job::STATUS_DRAFT,
        ]);
    }

    /**
     * A submission against an obsolete MONDO term is warned about, and the
     * warning names the successor MONDO provides.
     */
    public function test_obsolete_disease_warning_names_the_successor(): void
    {
        $successor = Disease::factory()->mondo()->create([
            'curie' => 'MONDO:0009299',
            'name' => 'Current term',
        ]);

        $obsolete = Disease::factory()->mondo()->deprecated()->withXrefs([
            'omim_id' => [],
            'orpha_id' => [],
            'replaced_by' => 'MONDO:0009299',
        ])->create(['curie' => 'MONDO:0000002', 'name' => 'Obsolete term']);

        $submission = $this->submissionFor($obsolete);

        $this->actingAs($this->user)
            ->get('/submissions/'.$submission->ident)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deprecatedDiseaseWarning.curie', 'MONDO:0000002')
                ->where('deprecatedDiseaseWarning.replaced_by', 'MONDO:0009299')
                ->where('deprecatedDiseaseWarning.message', fn ($message) => str_contains($message, $successor->curie)
                    && str_contains($message, 'remains valid'))
            );
    }

    /**
     * MONDO names a successor for only about one obsolete term in seven, so the
     * warning has to stand on its own without one.
     */
    public function test_obsolete_disease_warning_without_a_successor(): void
    {
        $obsolete = Disease::factory()->mondo()->deprecated()
            ->create(['curie' => 'MONDO:0000003', 'name' => 'Obsolete term']);

        $submission = $this->submissionFor($obsolete);

        $this->actingAs($this->user)
            ->get('/submissions/'.$submission->ident)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deprecatedDiseaseWarning.replaced_by', null)
                ->where('deprecatedDiseaseWarning.message', fn ($message) => str_contains($message, 'does not name a replacement'))
            );
    }

    public function test_no_warning_for_a_current_disease(): void
    {
        $submission = $this->submissionFor(Disease::factory()->mondo()->create(['curie' => 'MONDO:0000004']));

        $this->actingAs($this->user)
            ->get('/submissions/'.$submission->ident)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('deprecatedDiseaseWarning', null));
    }

    private function submissionFor(Disease $disease): Submission
    {
        return Submission::factory()->create([
            'job_id' => $this->job->id,
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'disease_id' => $disease->id,
            'original_disease_id' => $disease->id,
        ]);
    }
}
