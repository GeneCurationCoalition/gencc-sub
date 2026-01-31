<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Team;
use App\Models\AdminLog;
use App\Jobs\RunAdminCommand;
use App\Services\AdminProgressTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Feature tests for the AdminController API endpoints
 *
 * These tests verify that admin actions dispatch background jobs correctly
 * and that proper authorization is enforced.
 */
class AdminControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestUsers();
    }

    protected function createTestUsers(): void
    {
        // Create admin user
        $this->adminUser = User::factory()->create([
            'api_token_renewed_at' => now()
        ]);

        // Create admin team and add user to it
        $adminTeam = Team::create([
            'user_id' => $this->adminUser->id,
            'name' => 'admin',
            'personal_team' => false
        ]);

        $this->adminUser->teams()->attach($adminTeam);

        // Create regular (non-admin) user
        $this->regularUser = User::factory()->create([
            'api_token_renewed_at' => now()
        ]);
    }

    /**
     * Test that non-admin users cannot access admin endpoints
     */
    public function test_non_admin_cannot_access_run_publish(): void
    {
        $response = $this->actingAs($this->regularUser)
            ->postJson('/api/admin/run-publish');

        $response->assertStatus(403);
    }

    /**
     * Test that non-admin users cannot access update-diseases
     */
    public function test_non_admin_cannot_access_update_diseases(): void
    {
        $response = $this->actingAs($this->regularUser)
            ->postJson('/api/admin/update-diseases');

        $response->assertStatus(403);
    }

    /**
     * Test that non-admin users cannot access update-genes
     */
    public function test_non_admin_cannot_access_update_genes(): void
    {
        $response = $this->actingAs($this->regularUser)
            ->postJson('/api/admin/update-genes');

        $response->assertStatus(403);
    }

    /**
     * Test that non-admin users cannot access sync-pubmed
     */
    public function test_non_admin_cannot_access_sync_pubmed(): void
    {
        $response = $this->actingAs($this->regularUser)
            ->postJson('/api/admin/sync-pubmed');

        $response->assertStatus(403);
    }

    /**
     * Test that unauthenticated users cannot access admin endpoints
     */
    public function test_unauthenticated_cannot_access_admin_endpoints(): void
    {
        $response = $this->postJson('/api/admin/run-publish');
        $response->assertStatus(403);

        $response = $this->postJson('/api/admin/update-diseases');
        $response->assertStatus(403);

        $response = $this->postJson('/api/admin/update-genes');
        $response->assertStatus(403);

        $response = $this->postJson('/api/admin/sync-pubmed');
        $response->assertStatus(403);
    }

    /**
     * Test admin can dispatch run-publish job
     */
    public function test_admin_can_run_publish_and_dispatches_job(): void
    {
        Bus::fake([RunAdminCommand::class]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/admin/run-publish');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'started' => true,
            ]);

        Bus::assertDispatched(RunAdminCommand::class, function ($job) {
            return $job->operation === AdminLog::OP_RUN_PUBLISH
                && $job->command === 'gencc:release'
                && $job->userId === $this->adminUser->id;
        });
    }

    /**
     * Test admin can dispatch update-diseases job
     */
    public function test_admin_can_update_diseases_and_dispatches_job(): void
    {
        Bus::fake([RunAdminCommand::class]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/admin/update-diseases');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'started' => true,
            ]);

        Bus::assertDispatched(RunAdminCommand::class, function ($job) {
            return $job->operation === AdminLog::OP_UPDATE_DISEASES
                && $job->command === 'update:diseases';
        });
    }

    /**
     * Test admin can dispatch update-genes job
     */
    public function test_admin_can_update_genes_and_dispatches_job(): void
    {
        Bus::fake([RunAdminCommand::class]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/admin/update-genes');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'started' => true,
            ]);

        Bus::assertDispatched(RunAdminCommand::class, function ($job) {
            return $job->operation === AdminLog::OP_UPDATE_GENES
                && $job->command === 'update:genes';
        });
    }

    /**
     * Test admin can dispatch sync-pubmed job
     */
    public function test_admin_can_sync_pubmed_and_dispatches_job(): void
    {
        Bus::fake([RunAdminCommand::class]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/admin/sync-pubmed');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'started' => true,
            ]);

        Bus::assertDispatched(RunAdminCommand::class, function ($job) {
            return $job->operation === AdminLog::OP_SYNC_PUBMED
                && $job->command === 'pubmed:sync';
        });
    }

    /**
     * Test duplicate operation is rejected when already running
     */
    public function test_duplicate_operation_rejected_when_running(): void
    {
        Bus::fake([RunAdminCommand::class]);

        // Simulate a running operation
        AdminProgressTracker::start(AdminLog::OP_RUN_PUBLISH);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/admin/run-publish');

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'started' => false,
            ]);

        Bus::assertNotDispatched(RunAdminCommand::class);
    }

    /**
     * Test progress tracking is initialized on dispatch
     */
    public function test_progress_tracking_initialized_on_dispatch(): void
    {
        Bus::fake([RunAdminCommand::class]);

        $this->actingAs($this->adminUser)
            ->postJson('/api/admin/update-genes');

        $progress = AdminProgressTracker::get(AdminLog::OP_UPDATE_GENES);
        $this->assertNotNull($progress);
        $this->assertEquals('running', $progress['status']);
    }

    /**
     * Test RunAdminCommand job logs success to database
     */
    public function test_job_logs_success_to_database(): void
    {
        // Run the job synchronously with a simple command
        $job = new RunAdminCommand(
            AdminLog::OP_SYNC_PUBMED,
            'inspire',
            $this->adminUser->id,
            'default'
        );

        // Initialize progress like the controller would
        AdminProgressTracker::start(AdminLog::OP_SYNC_PUBMED);

        $job->handle();

        // Verify log was created
        $this->assertDatabaseHas('admin_logs', [
            'operation' => AdminLog::OP_SYNC_PUBMED,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0
        ]);

        // Verify progress tracker was updated
        $progress = AdminProgressTracker::get(AdminLog::OP_SYNC_PUBMED);
        $this->assertEquals('complete', $progress['status']);
        $this->assertArrayHasKey('result', $progress);
        $this->assertTrue($progress['result']['success']);
    }

    /**
     * Test RunAdminCommand summary generators
     */
    public function test_publish_summary_generator(): void
    {
        $summary = RunAdminCommand::publishSummary("Found 2 submitted jobs\nUpdate counts completed", 0);
        $this->assertStringContainsString('**Publish completed successfully**', $summary);
        $this->assertStringContainsString('Jobs processed: 2', $summary);
        $this->assertStringContainsString('Classification counts updated', $summary);
    }

    public function test_diseases_summary_generator(): void
    {
        $summary = RunAdminCommand::diseasesSummary("3 new diseases added\n5 updated", 0);
        $this->assertStringContainsString('**Disease update completed successfully**', $summary);
        $this->assertStringContainsString('New diseases: 3', $summary);
    }

    public function test_genes_summary_generator(): void
    {
        $summary = RunAdminCommand::genesSummary("2 new genes\n10 updated\nTotal: 45000", 0);
        $this->assertStringContainsString('**Gene update completed successfully**', $summary);
        $this->assertStringContainsString('New genes: 2', $summary);
    }

    public function test_pubmed_summary_generator(): void
    {
        $summary = RunAdminCommand::pubmedSummary("No PMIDs to process", 0);
        $this->assertStringContainsString('**PubMed sync completed successfully**', $summary);
        $this->assertStringContainsString('No PMIDs needed processing', $summary);
    }

    /**
     * Test isGenccAdmin returns true for admin user
     */
    public function test_is_gencc_admin_returns_true_for_admin(): void
    {
        $this->assertTrue($this->adminUser->isGenccAdmin());
    }

    /**
     * Test isGenccAdmin returns false for regular user
     */
    public function test_is_gencc_admin_returns_false_for_regular_user(): void
    {
        $this->assertFalse($this->regularUser->isGenccAdmin());
    }
}
