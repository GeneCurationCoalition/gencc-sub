<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\AdminLog;
use App\Models\User;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class AdminLogModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAdminUser();
    }

    protected function createAdminUser(): void
    {
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
    }

    /**
     * Test operation constants exist
     */
    public function test_operation_constants_exist(): void
    {
        $this->assertEquals('run_publish', AdminLog::OP_RUN_PUBLISH);
        $this->assertEquals('sync_pubmed', AdminLog::OP_SYNC_PUBMED);
        $this->assertEquals('update_diseases', AdminLog::OP_UPDATE_DISEASES);
        $this->assertEquals('update_genes', AdminLog::OP_UPDATE_GENES);
    }

    /**
     * Test admin log can be created
     */
    public function test_admin_log_can_be_created(): void
    {
        $log = AdminLog::create([
            'operation' => AdminLog::OP_RUN_PUBLISH,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0,
            'output' => 'Test output',
            'summary' => '**Test summary**',
            'executed_at' => now(),
            'duration_seconds' => 10
        ]);

        $this->assertNotNull($log->id);
        $this->assertEquals(AdminLog::OP_RUN_PUBLISH, $log->operation);
        $this->assertTrue($log->success);
        $this->assertEquals(0, $log->exit_code);
        $this->assertEquals('Test output', $log->output);
        $this->assertEquals('**Test summary**', $log->summary);
        $this->assertEquals(10, $log->duration_seconds);
    }

    /**
     * Test admin log belongs to user
     */
    public function test_admin_log_belongs_to_user(): void
    {
        $log = AdminLog::create([
            'operation' => AdminLog::OP_UPDATE_GENES,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0,
            'executed_at' => now()
        ]);

        $this->assertNotNull($log->user);
        $this->assertEquals($this->adminUser->id, $log->user->id);
    }

    /**
     * Test latestFor returns most recent log for operation
     */
    public function test_latest_for_returns_most_recent_log(): void
    {
        // Create older log
        AdminLog::create([
            'operation' => AdminLog::OP_UPDATE_DISEASES,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0,
            'summary' => 'Old log',
            'executed_at' => Carbon::now()->subHours(2)
        ]);

        // Create newer log
        $newerLog = AdminLog::create([
            'operation' => AdminLog::OP_UPDATE_DISEASES,
            'user_id' => $this->adminUser->id,
            'success' => false,
            'exit_code' => 1,
            'summary' => 'New log',
            'executed_at' => Carbon::now()
        ]);

        $latest = AdminLog::latestFor(AdminLog::OP_UPDATE_DISEASES);

        $this->assertEquals($newerLog->id, $latest->id);
        $this->assertEquals('New log', $latest->summary);
    }

    /**
     * Test latestFor returns null when no logs exist
     */
    public function test_latest_for_returns_null_when_no_logs(): void
    {
        $latest = AdminLog::latestFor(AdminLog::OP_SYNC_PUBMED);

        $this->assertNull($latest);
    }

    /**
     * Test latestForAll returns logs for all operations
     */
    public function test_latest_for_all_returns_all_operations(): void
    {
        // Create logs for each operation
        foreach ([AdminLog::OP_RUN_PUBLISH, AdminLog::OP_SYNC_PUBMED, AdminLog::OP_UPDATE_DISEASES] as $op) {
            AdminLog::create([
                'operation' => $op,
                'user_id' => $this->adminUser->id,
                'success' => true,
                'exit_code' => 0,
                'summary' => "Summary for {$op}",
                'executed_at' => now(),
                'duration_seconds' => 5
            ]);
        }

        $latest = AdminLog::latestForAll();

        $this->assertArrayHasKey(AdminLog::OP_RUN_PUBLISH, $latest);
        $this->assertArrayHasKey(AdminLog::OP_SYNC_PUBMED, $latest);
        $this->assertArrayHasKey(AdminLog::OP_UPDATE_DISEASES, $latest);
        $this->assertArrayNotHasKey(AdminLog::OP_UPDATE_GENES, $latest); // No log created

        // Verify structure of returned data
        $this->assertArrayHasKey('success', $latest[AdminLog::OP_RUN_PUBLISH]);
        $this->assertArrayHasKey('executed_at', $latest[AdminLog::OP_RUN_PUBLISH]);
        $this->assertArrayHasKey('executed_at_human', $latest[AdminLog::OP_RUN_PUBLISH]);
        $this->assertArrayHasKey('summary', $latest[AdminLog::OP_RUN_PUBLISH]);
        $this->assertArrayHasKey('duration_seconds', $latest[AdminLog::OP_RUN_PUBLISH]);
        $this->assertArrayHasKey('user_name', $latest[AdminLog::OP_RUN_PUBLISH]);
    }

    /**
     * Test success boolean cast works correctly
     */
    public function test_success_is_cast_to_boolean(): void
    {
        $successLog = AdminLog::create([
            'operation' => AdminLog::OP_RUN_PUBLISH,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0,
            'executed_at' => now()
        ]);

        $failLog = AdminLog::create([
            'operation' => AdminLog::OP_SYNC_PUBMED,
            'user_id' => $this->adminUser->id,
            'success' => false,
            'exit_code' => 1,
            'executed_at' => now()
        ]);

        $this->assertTrue($successLog->success);
        $this->assertFalse($failLog->success);
        $this->assertIsBool($successLog->success);
        $this->assertIsBool($failLog->success);
    }

    /**
     * Test executed_at is cast to datetime
     */
    public function test_executed_at_is_cast_to_datetime(): void
    {
        $executedAt = Carbon::now();

        $log = AdminLog::create([
            'operation' => AdminLog::OP_UPDATE_GENES,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0,
            'executed_at' => $executedAt
        ]);

        $this->assertInstanceOf(Carbon::class, $log->executed_at);
        $this->assertEquals($executedAt->toDateTimeString(), $log->executed_at->toDateTimeString());
    }

    /**
     * Test can store long output text
     */
    public function test_can_store_long_output(): void
    {
        $longOutput = str_repeat("Line of output\n", 1000);

        $log = AdminLog::create([
            'operation' => AdminLog::OP_RUN_PUBLISH,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0,
            'output' => $longOutput,
            'executed_at' => now()
        ]);

        $log->refresh();
        $this->assertEquals($longOutput, $log->output);
    }

    /**
     * Test can store markdown summary
     */
    public function test_can_store_markdown_summary(): void
    {
        $summary = <<<MARKDOWN
**Publish completed successfully**

- Jobs processed: 5
- Submissions synced: 250
- Failed submissions: 0

```
Processing complete!
```
MARKDOWN;

        $log = AdminLog::create([
            'operation' => AdminLog::OP_RUN_PUBLISH,
            'user_id' => $this->adminUser->id,
            'success' => true,
            'exit_code' => 0,
            'summary' => $summary,
            'executed_at' => now()
        ]);

        $log->refresh();
        $this->assertEquals($summary, $log->summary);
        $this->assertStringContainsString('**Publish completed successfully**', $log->summary);
    }
}
