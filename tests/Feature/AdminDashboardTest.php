<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Team;
use App\Models\Job;
use App\Models\Submission;
use App\Models\Submitter;
use App\Models\Pubmed;
use App\Models\AdminLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Feature tests for admin dashboard functionality
 *
 * Tests admin-specific data like pending jobs across all submitters,
 * PubMed statistics, and admin operation logs.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $regularUser;
    protected Submitter $submitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestData();
    }

    protected function createTestData(): void
    {
        // Create submitter
        $this->submitter = Submitter::create([
            'name' => 'Test Submitter',
            'status' => 1,
            'type' => 0
        ]);

        // Create admin user (no selected submitter for admin view)
        $this->adminUser = User::factory()->create([
            'submitter_id' => null,
            'api_token_renewed_at' => now()
        ]);

        // Create admin team and add user
        $adminTeam = Team::create([
            'user_id' => $this->adminUser->id,
            'name' => 'admin',
            'personal_team' => false
        ]);

        $this->adminUser->teams()->attach($adminTeam);

        // Create regular user with submitter
        $this->regularUser = User::factory()->create([
            'submitter_id' => $this->submitter->id,
            'api_token_renewed_at' => now()
        ]);
    }

    /**
     * Test admin dashboard includes admin-specific props
     */
    public function test_admin_dashboard_includes_admin_props(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('is_admin')
                ->where('is_admin', true)
                ->has('all_pending_jobs')
                ->has('submitted_jobs_count')
                ->has('pubmed_status')
                ->has('admin_logs')
            );
    }

    /**
     * Test regular user dashboard does not include admin props
     */
    public function test_regular_user_dashboard_excludes_admin_data(): void
    {
        $response = $this->actingAs($this->regularUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('is_admin', false)
                ->where('all_pending_jobs', [])
                ->where('submitted_jobs_count', 0)
                ->where('pubmed_status', null)
                ->where('admin_logs', null)
            );
    }

    /**
     * Test admin dashboard shows all pending jobs across submitters
     *
     * @group mysql
     */
    public function test_admin_sees_all_pending_jobs(): void
    {
        // Skip if using SQLite (doesn't support JSON_LENGTH used by Job model)
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires MySQL due to JSON_LENGTH function usage');
        }

        // Create jobs for different submitters
        $submitter2 = Submitter::create([
            'name' => 'Another Submitter',
            'status' => 1,
            'type' => 0
        ]);

        $job1 = Job::factory()->create([
            'submitter_id' => $this->submitter->id,
            'status' => Job::STATUS_DRAFT
        ]);

        $job2 = Job::factory()->create([
            'submitter_id' => $submitter2->id,
            'status' => Job::STATUS_SUBMITTED
        ]);

        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('all_pending_jobs', 2)
                ->where('all_pending_jobs.0.submitter_name', 'Another Submitter')
                ->where('all_pending_jobs.1.submitter_name', 'Test Submitter')
            );
    }

    /**
     * Test submitted_jobs_count is accurate
     *
     * @group mysql
     */
    public function test_submitted_jobs_count_is_accurate(): void
    {
        // Skip if using SQLite (doesn't support JSON_LENGTH used by Job model)
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires MySQL due to JSON_LENGTH function usage');
        }

        // Create draft jobs (shouldn't count)
        Job::factory()->count(2)->create([
            'submitter_id' => $this->submitter->id,
            'status' => Job::STATUS_DRAFT
        ]);

        // Create submitted jobs (should count)
        Job::factory()->count(3)->create([
            'submitter_id' => $this->submitter->id,
            'status' => Job::STATUS_SUBMITTED
        ]);

        // Create released jobs (shouldn't count)
        Job::factory()->create([
            'submitter_id' => $this->submitter->id,
            'status' => Job::STATUS_RELEASED
        ]);

        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('submitted_jobs_count', 3)
            );
    }

    /**
     * Test pubmed_status shows correct counts
     */
    public function test_pubmed_status_shows_correct_counts(): void
    {
        // Create synced pubmeds
        Pubmed::create([
            'pmid' => '11111111',
            'uid' => '11111111',
            'status' => Pubmed::STATUS_SUMMARY_COMPLETE
        ]);
        Pubmed::create([
            'pmid' => '22222222',
            'uid' => '22222222',
            'status' => Pubmed::STATUS_SUMMARY_COMPLETE
        ]);

        // Create pending pubmed (valid)
        Pubmed::create([
            'pmid' => '33333333',
            'uid' => '33333333',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        // Create invalid pubmed (PMID = 0)
        Pubmed::create([
            'pmid' => '0',
            'uid' => '0',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('pubmed_status.complete', 2)
                ->where('pubmed_status.pending', 1)  // Excludes PMID=0
                ->where('pubmed_status.invalid', 1)
                ->where('pubmed_status.total', 4)
            );
    }

    /**
     * Test pubmed_status excludes PMID=0 from pending count
     */
    public function test_pubmed_status_excludes_zero_pmid_from_pending(): void
    {
        // Create only invalid PMIDs
        Pubmed::create([
            'pmid' => '0',
            'uid' => '0',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);
        Pubmed::create([
            'pmid' => 0,
            'uid' => '0',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('pubmed_status.pending', 0)  // Zero because all are PMID=0
                ->where('pubmed_status.invalid', 2)
            );
    }

    /**
     * Test admin_logs contains latest operation data
     */
    public function test_admin_logs_contains_latest_operations(): void
    {
        // Create some admin logs
        AdminLog::create([
            'operation' => AdminLog::OP_RUN_PUBLISH,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0,
            'summary' => 'Publish completed',
            'executed_at' => now()->subHours(1),
            'duration_seconds' => 30
        ]);

        AdminLog::create([
            'operation' => AdminLog::OP_UPDATE_DISEASES,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0,
            'summary' => 'Diseases updated',
            'executed_at' => now()->subDays(1),
            'duration_seconds' => 120
        ]);

        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('admin_logs.run_publish')
                ->has('admin_logs.update_diseases')
                ->where('admin_logs.run_publish.success', true)
                ->where('admin_logs.update_diseases.success', true)
            );
    }

    /**
     * Test admin_logs is empty when no operations have been run
     */
    public function test_admin_logs_empty_when_no_operations(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('admin_logs', [])
            );
    }

    /**
     * Test admin with selected submitter sees regular dashboard
     */
    public function test_admin_with_selected_submitter_sees_regular_view(): void
    {
        // Set submitter for admin user (simulating they selected a submitter)
        $this->adminUser->update(['submitter_id' => $this->submitter->id]);

        // Also need to set session
        session(['selected_submitter_id' => $this->submitter->id]);

        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        // When admin has selected submitter, they see the regular view
        // all_pending_jobs should be empty, pubmed_status null, etc.
        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('is_admin', true)  // Still an admin
                ->where('all_pending_jobs', [])  // But empty because submitter is selected
                ->where('pubmed_status', null)
                ->where('admin_logs', null)
            );
    }

    /**
     * Test pending jobs include submission counts
     *
     * @group mysql
     */
    public function test_pending_jobs_include_submission_counts(): void
    {
        // Skip if using SQLite (doesn't support JSON_LENGTH used by Job model)
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('This test requires MySQL due to JSON_LENGTH function usage');
        }

        $job = Job::factory()->create([
            'submitter_id' => $this->submitter->id,
            'status' => Job::STATUS_DRAFT
        ]);

        // Add submissions with different statuses
        Submission::factory()->count(3)->create([
            'job_id' => $job->id,
            'submitter_id' => $this->submitter->id,
            'status' => Submission::STATUS_DRAFT_NEW
        ]);
        Submission::factory()->count(2)->create([
            'job_id' => $job->id,
            'submitter_id' => $this->submitter->id,
            'status' => Submission::STATUS_DRAFT_REPUBLISH
        ]);

        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->has('all_pending_jobs', 1)
                ->where('all_pending_jobs.0.new_count', 3)
                ->where('all_pending_jobs.0.republish_count', 2)
                ->where('all_pending_jobs.0.total_count', 5)
            );
    }

    /**
     * Test submissions_affected counts submissions with pending PMIDs
     */
    public function test_submissions_affected_count(): void
    {
        // Create a pending pubmed (valid)
        $pubmed = Pubmed::create([
            'pmid' => '99999999',
            'uid' => '99999999',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        // Create submissions and link to pubmed
        $submission = Submission::factory()->create();
        $pubmed->submissions()->attach($submission->id);

        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('pubmed_status.submissions_affected', 1)
            );
    }
}
