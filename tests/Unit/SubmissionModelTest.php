<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Submission;
use App\Models\Job;
use App\Models\Gene;
use App\Models\Disease;
use App\Models\Classification;
use App\Models\Inheritance;
use App\Models\Submitter;
use App\Models\Pubmed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class SubmissionModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that submission gets uuid ident on instantiation
     */
    public function test_submission_gets_uuid_ident_on_instantiation(): void
    {
        $submission = new Submission();

        $this->assertNotNull($submission->ident);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $submission->ident
        );
    }

    /**
     * Test that submission gets sid auto-generated after creation
     */
    public function test_submission_gets_sid_generated_on_create(): void
    {
        $submission = Submission::factory()->create();

        $this->assertNotNull($submission->sid);
        $this->assertStringStartsWith('SGC-1', $submission->sid);
        $this->assertEquals('SGC-1' . str_pad($submission->id, 5, '0', STR_PAD_LEFT), $submission->sid);
    }

    /**
     * Test sid format for various IDs
     */
    public function test_sid_format(): void
    {
        // Create submissions and check their SIDs
        $submission1 = Submission::factory()->create();
        $submission2 = Submission::factory()->create();
        $submission3 = Submission::factory()->create();

        // Each should have sequential SIDs
        $this->assertEquals('SGC-1' . str_pad($submission1->id, 5, '0', STR_PAD_LEFT), $submission1->sid);
        $this->assertEquals('SGC-1' . str_pad($submission2->id, 5, '0', STR_PAD_LEFT), $submission2->sid);
        $this->assertEquals('SGC-1' . str_pad($submission3->id, 5, '0', STR_PAD_LEFT), $submission3->sid);
    }

    /**
     * Test status constants exist
     *
     * With the simplified status model (Phase 2), we have 5 status values:
     * - Pending (action-based): new, republish, unpublish
     * - Released (visibility-based): published, unpublished
     *
     * The deprecated compound constants (STATUS_DRAFT_*, STATUS_SUBMITTED_*) are
     * aliased to the new simplified values for backwards compatibility.
     */
    public function test_status_constants_exist(): void
    {
        // New simplified status constants
        $this->assertEquals('new', Submission::STATUS_NEW);
        $this->assertEquals('republish', Submission::STATUS_REPUBLISH);
        $this->assertEquals('unpublish', Submission::STATUS_UNPUBLISH);
        $this->assertEquals('published', Submission::STATUS_PUBLISHED);
        $this->assertEquals('unpublished', Submission::STATUS_UNPUBLISHED);

        // Deprecated aliases should map to new values
        $this->assertEquals('new', Submission::STATUS_DRAFT_NEW);
        $this->assertEquals('new', Submission::STATUS_SUBMITTED_NEW);
        $this->assertEquals('republish', Submission::STATUS_DRAFT_REPUBLISH);
        $this->assertEquals('republish', Submission::STATUS_SUBMITTED_REPUBLISH);
        $this->assertEquals('unpublish', Submission::STATUS_DRAFT_UNPUBLISH);
        $this->assertEquals('unpublish', Submission::STATUS_SUBMITTED_UNPUBLISH);
    }

    /**
     * Test type constants exist
     */
    public function test_type_constants_exist(): void
    {
        $this->assertEquals(0, Submission::TYPE_NONE);
        $this->assertEquals(1, Submission::TYPE_API_SUBMISSION);
        $this->assertEquals(2, Submission::TYPE_FILE_SUBMISSION);
        $this->assertEquals(3, Submission::TYPE_PORTAL_SUBMISSION);
        $this->assertEquals(4, Submission::TYPE_OMIM_SUBMISSION);
        $this->assertEquals(7, Submission::TYPE_GENCC_IMPORT);
    }

    /**
     * Test scopeIdent works
     */
    public function test_scope_ident_works(): void
    {
        $submission = Submission::factory()->create();

        $found = Submission::ident($submission->ident)->first();

        $this->assertNotNull($found);
        $this->assertEquals($submission->id, $found->id);
    }

    /**
     * Test scopeSid works
     */
    public function test_scope_sid_works(): void
    {
        $submission = Submission::factory()->create();

        $found = Submission::sid($submission->sid)->first();

        $this->assertNotNull($found);
        $this->assertEquals($submission->id, $found->id);
    }

    /**
     * Test scopePublished works
     */
    public function test_scope_published_works(): void
    {
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);
        $draft = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_NEW]);

        $results = Submission::published()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($published->id, $results->first()->id);
    }

    /**
     * Test scopeDraftStates works
     */
    public function test_scope_draft_states_works(): void
    {
        $draftNew = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_NEW]);
        $draftRepublish = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_REPUBLISH]);
        $draftUnpublish = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_UNPUBLISH]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);

        $results = Submission::draftStates()->get();

        $this->assertCount(3, $results);
        $this->assertTrue($results->contains($draftNew));
        $this->assertTrue($results->contains($draftRepublish));
        $this->assertTrue($results->contains($draftUnpublish));
        $this->assertFalse($results->contains($published));
    }

    /**
     * Test scopeSubmittedStates works
     *
     * Note: With the simplified status model, scopeSubmittedStates is deprecated
     * and now returns pending submissions (same as scopeDraftStates) because
     * stage (draft/submitted) is derived from Job.status, not submission.status.
     *
     * To find submissions in a submitted job, join with jobs table.
     */
    public function test_scope_submitted_states_works(): void
    {
        // Create pending submissions (all use the same simplified statuses now)
        $newSubmission = Submission::factory()->create(['status' => Submission::STATUS_NEW]);
        $republishSubmission = Submission::factory()->create(['status' => Submission::STATUS_REPUBLISH]);
        $unpublishSubmission = Submission::factory()->create(['status' => Submission::STATUS_UNPUBLISH]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);

        // With simplified model, submittedStates returns all pending submissions
        // (same as draftStates - both are deprecated and map to pending)
        $results = Submission::submittedStates()->get();

        $this->assertCount(3, $results);
        $this->assertTrue($results->contains($newSubmission));
        $this->assertTrue($results->contains($republishSubmission));
        $this->assertTrue($results->contains($unpublishSubmission));
        $this->assertFalse($results->contains($published));
    }

    /**
     * Test scopeUnpublished works
     */
    public function test_scope_unpublished_works(): void
    {
        $unpublished = Submission::factory()->create(['status' => Submission::STATUS_UNPUBLISHED]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);

        $results = Submission::unpublished()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($unpublished->id, $results->first()->id);
    }

    /**
     * Test scopeByStatus works
     */
    public function test_scope_by_status_works(): void
    {
        $draft = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_NEW]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);

        $results = Submission::byStatus(Submission::STATUS_DRAFT_NEW)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($draft->id, $results->first()->id);
    }

    /**
     * Test display_status attribute
     */
    public function test_display_status_attribute(): void
    {
        $submission = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_NEW]);
        $this->assertEquals('Draft (New)', $submission->display_status);

        $submission->status = Submission::STATUS_PUBLISHED;
        $this->assertEquals('Published', $submission->display_status);

        $submission->status = Submission::STATUS_DRAFT_REPUBLISH;
        $this->assertEquals('Draft (Republish)', $submission->display_status);
    }

    /**
     * Test has_errors attribute when no errors
     */
    public function test_has_errors_false_when_no_errors(): void
    {
        $submission = Submission::factory()->create(['submission_errors' => null]);

        $this->assertFalse($submission->has_errors);
    }

    /**
     * Test has_errors attribute when has errors
     */
    public function test_has_errors_true_when_has_errors(): void
    {
        $submission = Submission::factory()->create([
            'submission_errors' => (object)['gene' => 'Invalid gene']
        ]);

        $this->assertTrue($submission->has_errors);
    }

    /**
     * Test has_errors attribute with empty object
     */
    public function test_has_errors_false_with_empty_errors(): void
    {
        $submission = Submission::factory()->create(['submission_errors' => (object)[]]);

        $this->assertFalse($submission->has_errors);
    }

    /**
     * Test relationships
     */
    public function test_belongs_to_job(): void
    {
        $job = Job::factory()->create();
        $submission = Submission::factory()->create(['job_id' => $job->id]);

        $this->assertNotNull($submission->job);
        $this->assertEquals($job->id, $submission->job->id);
    }

    /**
     * Test belongs to gene
     */
    public function test_belongs_to_gene(): void
    {
        $gene = Gene::factory()->create();
        $submission = Submission::factory()->create(['gene_id' => $gene->id]);

        $this->assertNotNull($submission->gene);
        $this->assertEquals($gene->id, $submission->gene->id);
    }

    /**
     * Test belongs to disease
     */
    public function test_belongs_to_disease(): void
    {
        $disease = Disease::factory()->create();
        $submission = Submission::factory()->create(['disease_id' => $disease->id]);

        $this->assertNotNull($submission->disease);
        $this->assertEquals($disease->id, $submission->disease->id);
    }

    /**
     * Test initialize_submission_errors
     */
    public function test_initialize_submission_errors(): void
    {
        $submission = new Submission();
        $submission->initialize_submission_errors();

        $errors = (array)$submission->submission_errors;

        $this->assertArrayHasKey('gene_hgnc_id', $errors);
        $this->assertArrayHasKey('disease_curie_id', $errors);
        $this->assertArrayHasKey('moi_curie_id', $errors);
        $this->assertArrayHasKey('classification_curie_id', $errors);
        $this->assertArrayHasKey('criteria_url', $errors);
        $this->assertArrayHasKey('report_date', $errors);
    }

    /**
     * Test initialize_submission_data
     */
    public function test_initialize_submission_data(): void
    {
        $submission = new Submission();
        $submission->sid = 'SGC-100001';
        $submission->local_key = 'TEST-KEY';
        $submission->friendly = 'Test Friendly Name';
        $submission->initialize_submission_data();

        $data = $submission->submission_data;

        // submission_data is cast as object, so check properties exist
        $this->assertNotNull($data);
        $this->assertTrue(property_exists($data, 'disease') || isset($data->disease));
        $this->assertTrue(property_exists($data, 'report') || isset($data->report));
        $this->assertTrue(property_exists($data, 'evidence') || isset($data->evidence));
        $this->assertFalse(property_exists($data, 'gene'));
        $this->assertFalse(property_exists($data, 'moi'));
        $this->assertFalse(property_exists($data, 'classification'));
        $this->assertFalse(property_exists($data, 'additional_information'));
        $this->assertEquals('SGC-100001', $data->submission_id);
        $this->assertEquals('TEST-KEY', $data->local_key);
    }

    /**
     * Test normalizeJsonField handles stdClass (from DB load)
     */
    public function test_normalize_json_field_handles_object(): void
    {
        $obj = new \stdClass();
        $obj->version = new \stdClass();
        $obj->version->internal = '1.0.0';
        $obj->version->description = 'test';

        $result = Submission::normalizeJsonField($obj);

        $this->assertIsArray($result);
        $this->assertEquals('1.0.0', $result['version']['internal']);
        $this->assertEquals('test', $result['version']['description']);
    }

    /**
     * Test normalizeJsonField handles array (from programmatic set)
     */
    public function test_normalize_json_field_handles_array(): void
    {
        $array = [
            'version' => [
                'internal' => '2.0.0',
                'description' => 'array test'
            ]
        ];

        $result = Submission::normalizeJsonField($array);

        $this->assertIsArray($result);
        $this->assertEquals('2.0.0', $result['version']['internal']);
        $this->assertEquals('array test', $result['version']['description']);
    }

    /**
     * Test normalizeJsonField handles null
     */
    public function test_normalize_json_field_handles_null(): void
    {
        $result = Submission::normalizeJsonField(null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test soft delete works
     */
    public function test_soft_delete_works(): void
    {
        $submission = Submission::factory()->create();
        $submissionId = $submission->id;

        $submission->delete();

        $this->assertNull(Submission::find($submissionId));
        $this->assertNotNull(Submission::withTrashed()->find($submissionId));
    }

    /**
     * Test released_at is set when status changes to published
     */
    public function test_released_at_set_on_publish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_SUBMITTED_NEW,
            'released_at' => null
        ]);

        $submission->status = Submission::STATUS_PUBLISHED;
        $submission->save();

        $this->assertNotNull($submission->released_at);
    }

    /**
     * Test released_at not overwritten if already set
     */
    public function test_released_at_not_overwritten(): void
    {
        $originalDate = Carbon::now()->subMonth();

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'released_at' => $originalDate
        ]);

        // Force a re-save
        $submission->status = Submission::STATUS_PUBLISHED;
        $submission->save();

        $this->assertEquals($originalDate->format('Y-m-d H:i:s'), $submission->released_at->format('Y-m-d H:i:s'));
    }

    /**
     * Test pubmeds relationship (many-to-many)
     */
    public function test_pubmeds_relationship(): void
    {
        $submission = Submission::factory()->create();
        $pubmed1 = Pubmed::create([
            'pmid' => '12345678',
            'uid' => '12345678',
            'status' => Pubmed::STATUS_ACTIVE
        ]);
        $pubmed2 = Pubmed::create([
            'pmid' => '87654321',
            'uid' => '87654321',
            'status' => Pubmed::STATUS_ACTIVE
        ]);

        $submission->pubmeds()->attach([$pubmed1->id, $pubmed2->id]);

        $submission->refresh();
        $this->assertCount(2, $submission->pubmeds);
        $this->assertTrue($submission->pubmeds->contains($pubmed1));
        $this->assertTrue($submission->pubmeds->contains($pubmed2));
    }

    /**
     * Test is_live defaults to false for new submissions
     */
    public function test_is_live_defaults_to_false(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_NEW
        ]);

        $this->assertFalse($submission->is_live);
    }

    /**
     * Test is_live can be set to true for published submissions
     */
    public function test_is_live_can_be_set_to_true(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true
        ]);

        $this->assertTrue($submission->is_live);
    }

    /**
     * Test isArchived returns false for live published submission
     */
    public function test_is_archived_false_for_live_published(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true
        ]);

        $this->assertFalse($submission->isArchived());
        $this->assertFalse($submission->is_archived);
    }

    /**
     * Test isArchived returns true for non-live published submission
     */
    public function test_is_archived_true_for_non_live_published(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => false
        ]);

        $this->assertTrue($submission->isArchived());
        $this->assertTrue($submission->is_archived);
    }

    /**
     * Test isArchived returns true for non-live unpublished submission (archived by newer release)
     */
    public function test_is_archived_true_for_non_live_unpublished(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED,
            'is_live' => false
        ]);

        $this->assertTrue($submission->isArchived());
        $this->assertTrue($submission->is_archived);
    }

    /**
     * Test isArchived returns false for live unpublished submission
     *
     * An unpublished submission that is live represents the current state
     * of that SGC ID (hidden from public view, but that IS the live state).
     */
    public function test_is_archived_false_for_live_unpublished(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED,
            'is_live' => true
        ]);

        $this->assertFalse($submission->isArchived());
        $this->assertFalse($submission->is_archived);
        $this->assertTrue($submission->isLive());
    }

    /**
     * Test isArchived returns false for draft submissions (regardless of is_live)
     */
    public function test_is_archived_false_for_draft_submissions(): void
    {
        $draftNew = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_NEW,
            'is_live' => false
        ]);
        $draftRepublish = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_REPUBLISH,
            'is_live' => false
        ]);
        $draftUnpublish = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_UNPUBLISH,
            'is_live' => false
        ]);

        $this->assertFalse($draftNew->isArchived());
        $this->assertFalse($draftRepublish->isArchived());
        $this->assertFalse($draftUnpublish->isArchived());
    }

    /**
     * Test isArchived returns false for submitted submissions (regardless of is_live)
     */
    public function test_is_archived_false_for_submitted_submissions(): void
    {
        $submittedNew = Submission::factory()->create([
            'status' => Submission::STATUS_SUBMITTED_NEW,
            'is_live' => false
        ]);
        $submittedRepublish = Submission::factory()->create([
            'status' => Submission::STATUS_SUBMITTED_REPUBLISH,
            'is_live' => false
        ]);
        $submittedUnpublish = Submission::factory()->create([
            'status' => Submission::STATUS_SUBMITTED_UNPUBLISH,
            'is_live' => false
        ]);

        $this->assertFalse($submittedNew->isArchived());
        $this->assertFalse($submittedRepublish->isArchived());
        $this->assertFalse($submittedUnpublish->isArchived());
    }

    /**
     * Test isLive helper method
     */
    public function test_is_live_helper_method(): void
    {
        $liveSubmission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true
        ]);
        $archivedSubmission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => false
        ]);

        $this->assertTrue($liveSubmission->isLive());
        $this->assertFalse($archivedSubmission->isLive());
    }

    /**
     * Test scopeLive returns only live submissions
     */
    public function test_scope_live(): void
    {
        $live = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true
        ]);
        $archived = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => false
        ]);
        $draft = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_NEW,
            'is_live' => false
        ]);

        $results = Submission::live()->get();

        $this->assertCount(1, $results);
        $this->assertEquals($live->id, $results->first()->id);
    }

    /**
     * Test scopeArchived returns only archived released submissions
     */
    public function test_scope_archived(): void
    {
        $live = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true
        ]);
        $archivedPublished = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => false
        ]);
        $archivedUnpublished = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED,
            'is_live' => false
        ]);
        $draft = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_NEW,
            'is_live' => false
        ]);

        $results = Submission::archived()->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains($archivedPublished));
        $this->assertTrue($results->contains($archivedUnpublished));
        $this->assertFalse($results->contains($live));
        $this->assertFalse($results->contains($draft));
    }

    /**
     * Test is_archived is included in JSON serialization
     */
    public function test_is_archived_in_json(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => false
        ]);

        $json = $submission->toArray();

        $this->assertArrayHasKey('is_archived', $json);
        $this->assertTrue($json['is_archived']);
    }
}
