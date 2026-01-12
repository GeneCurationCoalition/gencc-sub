<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Job;
use App\Models\Submission;
use App\Models\User;
use App\Models\Submitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class JobModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that job gets uuid ident on instantiation
     */
    public function test_job_gets_uuid_ident_on_instantiation(): void
    {
        $job = new Job();

        $this->assertNotNull($job->ident);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $job->ident
        );
    }

    /**
     * Test that job gets slug auto-generated after creation
     */
    public function test_job_gets_slug_generated_on_create(): void
    {
        $job = Job::factory()->create();

        $this->assertNotNull($job->slug);
        $this->assertStringStartsWith('J-1', $job->slug);
        $this->assertEquals('J-1' . str_pad($job->id, 5, '0', STR_PAD_LEFT), $job->slug);
    }

    /**
     * Test slug format for various IDs
     */
    public function test_slug_format(): void
    {
        $job1 = Job::factory()->create();
        $job2 = Job::factory()->create();
        $job3 = Job::factory()->create();

        $this->assertEquals('J-1' . str_pad($job1->id, 5, '0', STR_PAD_LEFT), $job1->slug);
        $this->assertEquals('J-1' . str_pad($job2->id, 5, '0', STR_PAD_LEFT), $job2->slug);
        $this->assertEquals('J-1' . str_pad($job3->id, 5, '0', STR_PAD_LEFT), $job3->slug);
    }

    /**
     * Test status constants exist
     */
    public function test_status_constants_exist(): void
    {
        $this->assertEquals('draft', Job::STATUS_DRAFT);
        $this->assertEquals('submitted', Job::STATUS_SUBMITTED);
        $this->assertEquals('released', Job::STATUS_RELEASED);
        // STATUS_PROCESSED is deprecated alias for STATUS_RELEASED
        $this->assertEquals('released', Job::STATUS_PROCESSED);
    }

    /**
     * Test type constants exist
     */
    public function test_type_constants_exist(): void
    {
        $this->assertEquals(0, Job::TYPE_NONE);
        $this->assertEquals(1, Job::TYPE_API_SUBMISSION);
        $this->assertEquals(2, Job::TYPE_FILE_SUBMISSION);
        $this->assertEquals(4, Job::TYPE_GENCC_IMPORT);
    }

    /**
     * Test legacy status constants exist (for migrations)
     */
    public function test_legacy_status_constants_exist(): void
    {
        $this->assertEquals(0, Job::LEGACY_STATUS_INITIALIZING);
        $this->assertEquals(1, Job::LEGACY_STATUS_QUEUED);
        $this->assertEquals(2, Job::LEGACY_STATUS_PROCESSING);
        $this->assertEquals(3, Job::LEGACY_STATUS_COMPLETE);
        $this->assertEquals(4, Job::LEGACY_STATUS_ERRORS);
        $this->assertEquals(5, Job::LEGACY_STATUS_STAGED);
        $this->assertEquals(9, Job::LEGACY_STATUS_REMOVED);
        $this->assertEquals(99, Job::LEGACY_STATUS_FAILED);
    }

    /**
     * Test scopeIdent works
     */
    public function test_scope_ident_works(): void
    {
        $job = Job::factory()->create();

        $found = Job::ident($job->ident)->first();

        $this->assertNotNull($found);
        $this->assertEquals($job->id, $found->id);
    }

    /**
     * Test scopeDraft works
     */
    public function test_scope_draft_works(): void
    {
        $draft = Job::factory()->create(['status' => Job::STATUS_DRAFT]);
        $submitted = Job::factory()->create(['status' => Job::STATUS_SUBMITTED]);

        $results = Job::draft()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($draft->id, $results->first()->id);
    }

    /**
     * Test scopeSubmitted works
     */
    public function test_scope_submitted_works(): void
    {
        $draft = Job::factory()->create(['status' => Job::STATUS_DRAFT]);
        $submitted = Job::factory()->create(['status' => Job::STATUS_SUBMITTED]);

        $results = Job::submitted()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($submitted->id, $results->first()->id);
    }

    /**
     * Test scopeProcessed works
     */
    public function test_scope_processed_works(): void
    {
        $processed = Job::factory()->create(['status' => Job::STATUS_PROCESSED]);
        $draft = Job::factory()->create(['status' => Job::STATUS_DRAFT]);

        $results = Job::processed()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($processed->id, $results->first()->id);
    }

    /**
     * Test scopeActive works (draft + submitted)
     */
    public function test_scope_active_works(): void
    {
        $draft = Job::factory()->create(['status' => Job::STATUS_DRAFT]);
        $submitted = Job::factory()->create(['status' => Job::STATUS_SUBMITTED]);
        $processed = Job::factory()->create(['status' => Job::STATUS_PROCESSED]);

        $results = Job::active()->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains($draft));
        $this->assertTrue($results->contains($submitted));
        $this->assertFalse($results->contains($processed));
    }

    /**
     * Test display_status attribute
     */
    public function test_display_status_attribute(): void
    {
        $job = Job::factory()->create(['status' => Job::STATUS_DRAFT]);
        $this->assertEquals('Draft', $job->display_status);

        $job->status = Job::STATUS_SUBMITTED;
        $this->assertEquals('Submitted', $job->display_status);

        $job->status = Job::STATUS_RELEASED;
        $this->assertEquals('Released', $job->display_status);

        // Backwards compatibility - STATUS_PROCESSED also displays as Released
        $job->status = Job::STATUS_PROCESSED;
        $this->assertEquals('Released', $job->display_status);
    }

    /**
     * Test display_status returns Unknown for invalid status
     */
    public function test_display_status_unknown_for_invalid(): void
    {
        $job = Job::factory()->create();
        $job->status = 'invalid_status';

        $this->assertEquals('Unknown', $job->display_status);
    }

    /**
     * Test has many submissions relationship
     */
    public function test_has_many_submissions(): void
    {
        $job = Job::factory()->create();
        $submission1 = Submission::factory()->create(['job_id' => $job->id]);
        $submission2 = Submission::factory()->create(['job_id' => $job->id]);

        $this->assertCount(2, $job->submissions);
        $this->assertTrue($job->submissions->contains($submission1));
        $this->assertTrue($job->submissions->contains($submission2));
    }

    /**
     * Test belongs to user relationship
     */
    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id]);

        $this->assertNotNull($job->user);
        $this->assertEquals($user->id, $job->user->id);
    }

    /**
     * Test belongs to submitter relationship
     */
    public function test_belongs_to_submitter(): void
    {
        $submitter = Submitter::factory()->create();
        $job = Job::factory()->create(['submitter_id' => $submitter->id]);

        $this->assertNotNull($job->submitter);
        $this->assertEquals($submitter->id, $job->submitter->id);
    }

    /**
     * Test addEvent adds to activity array
     */
    public function test_add_event_adds_to_activity(): void
    {
        $job = Job::factory()->create(['activity' => (object)[]]);

        // Convert to array for adding event
        $job->activity = [];
        $job->addEvent('Test event 1');
        $job->addEvent('Test event 2');

        $this->assertCount(2, $job->activity);
        $this->assertEquals('Test event 1', $job->activity[0]);
        $this->assertEquals('Test event 2', $job->activity[1]);
    }

    /**
     * Test released_at is set when status changes to processed
     */
    public function test_released_at_set_on_processed(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED,
            'released_at' => null
        ]);

        $job->status = Job::STATUS_PROCESSED;
        $job->save();

        $job->refresh();
        $this->assertNotNull($job->released_at);
    }

    /**
     * Test released_at not overwritten if already set
     */
    public function test_released_at_not_overwritten(): void
    {
        $originalDate = Carbon::now()->subMonth();

        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED,
            'released_at' => $originalDate
        ]);

        $job->status = Job::STATUS_PROCESSED;
        $job->save();

        $job->refresh();
        $this->assertEquals($originalDate->format('Y-m-d H:i:s'), $job->released_at->format('Y-m-d H:i:s'));
    }

    /**
     * Test soft delete works
     */
    public function test_soft_delete_works(): void
    {
        $job = Job::factory()->create();
        $jobId = $job->id;

        $job->delete();

        $this->assertNull(Job::find($jobId));
        $this->assertNotNull(Job::withTrashed()->find($jobId));
    }

    /**
     * Test casts are properly configured
     */
    public function test_casts_configured(): void
    {
        $job = Job::factory()->create([
            'submission_data' => (object)['key' => 'value'],
            'activity' => (object)['event' => 'test'],
            'is_publishing' => true
        ]);

        $job->refresh();

        $this->assertInstanceOf(Carbon::class, $job->created_at);
        $this->assertIsObject($job->submission_data);
        $this->assertIsObject($job->activity);
        $this->assertTrue($job->is_publishing);
    }
}
