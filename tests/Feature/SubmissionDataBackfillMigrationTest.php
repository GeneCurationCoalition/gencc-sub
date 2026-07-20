<?php

namespace Tests\Feature;

use App\Models\Classification;
use App\Models\Disease;
use App\Models\Gene;
use App\Models\Inheritance;
use App\Models\Job;
use App\Models\Submission;
use App\Models\Submitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises database/migrations/2026_07_14_000000_backfill_submission_data_json_gaps.php:
 *  - fills gene/moi/classification/additional_information placeholders in the frozen
 *    original_submission_data used by the release export, while leaving live placeholders alone;
 *  - moves a legacy mechanism.comment (singular) to the canonical mechanism.comments;
 *  - is guarded (leaves real values alone) and idempotent.
 */
class SubmissionDataBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Gene $gene;

    private Inheritance $inheritance;

    private Classification $classification;

    private Submitter $submitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->submitter = Submitter::create(['name' => 'Test Lab', 'status' => Submitter::STATUS_ACTIVE, 'type' => 0]);
        $this->gene = Gene::create([
            'hgnc_id' => 'HGNC:5', 'symbol' => 'A1BG', 'name' => 'x', 'locus_group' => 'g',
            'locus_type' => 't', 'location' => 'l', 'status' => Gene::STATUS_ACTIVE,
        ]);
        Disease::create(['curie' => 'MONDO:0000001', 'name' => 'D', 'description' => 'x', 'status' => Disease::STATUS_ACTIVE]);
        $this->inheritance = Inheritance::create([
            'curie' => 'HP:0000006', 'name' => 'AD', 'description' => 'x', 'abbreviation' => 'AD', 'status' => Inheritance::STATUS_ACTIVE,
        ]);
        $this->classification = Classification::create([
            'curie' => 'GENCC:100001', 'name' => 'Definitive', 'description' => 'x', 'abbreviation' => 'DEF', 'status' => Classification::STATUS_ACTIVE,
        ]);
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_07_14_000000_backfill_submission_data_json_gaps.php');
        $migration->up();
    }

    private function gapData(?array $mechanism = null): array
    {
        $data = [
            'gene' => ['id' => '', 'symbol' => ''],
            'moi' => ['id' => '', 'name' => ''],
            'classification' => ['id' => '', 'name' => ''],
            'additional_information' => [['key' => 'values']],
        ];
        if ($mechanism !== null) {
            $data['mechanism'] = $mechanism;
        }

        return $data;
    }

    private function seedSubmission(array $submissionData, ?array $originalData = null, array $overrides = []): Submission
    {
        $job = Job::factory()->create(['status' => Job::STATUS_SUBMITTED, 'submitter_id' => $this->submitter->id]);

        return Submission::factory()->create(array_merge([
            'job_id' => $job->id,
            'status' => Submission::STATUS_PUBLISHED,
            'type' => Submission::TYPE_PORTAL_SUBMISSION,
            'is_live' => true,
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'inheritance_id' => $this->inheritance->id,
            'classification_id' => $this->classification->id,
            'local_key' => 'LOCAL-1',
            'submission_data' => $submissionData,
            'original_submission_data' => $originalData,
        ], $overrides));
    }

    public function test_backfills_frozen_snapshot_from_fks_and_leaves_live_placeholders_alone(): void
    {
        $s = $this->seedSubmission($this->gapData(), $this->gapData());
        $this->runMigration();
        $s->refresh();

        $this->assertSame('', $s->submission_data->gene->id);
        $this->assertSame('', $s->submission_data->moi->id);
        $this->assertSame('', $s->submission_data->classification->id);
        $this->assertIsArray($s->submission_data->additional_information);

        $frozen = $s->original_submission_data;
        $this->assertSame($this->gene->hgnc_id, $frozen->gene->id);
        $this->assertSame($this->inheritance->curie, $frozen->moi->id);
        $this->assertSame($this->classification->curie, $frozen->classification->id);
        $this->assertSame($this->submitter->curie, $frozen->additional_information->submitter_curie);
        $this->assertSame('LOCAL-1', $frozen->additional_information->submitted_as_submission_id);
    }

    public function test_moves_legacy_mechanism_comment_to_comments(): void
    {
        $s = $this->seedSubmission(
            $this->gapData(['id' => '', 'name' => '', 'comment' => 'legacy text']),
            $this->gapData(['id' => '', 'name' => '', 'comment' => 'frozen legacy text'])
        );
        $this->runMigration();
        $s->refresh();

        $mech = $s->submission_data->mechanism;
        $this->assertSame('legacy text', $mech->comments);
        $this->assertFalse(property_exists($mech, 'comment'), 'singular comment key should be removed');
        $this->assertSame('frozen legacy text', $s->original_submission_data->mechanism->comments);
        $this->assertFalse(property_exists($s->original_submission_data->mechanism, 'comment'));
    }

    public function test_does_not_clobber_real_values_or_existing_comments(): void
    {
        $good = [
            'gene' => ['id' => 'HGNC:9999', 'symbol' => 'REAL'],
            'moi' => ['id' => '', 'name' => ''],
            'classification' => ['id' => '', 'name' => ''],
            'additional_information' => [['key' => 'values']],
            'mechanism' => ['id' => '', 'name' => '', 'comment' => 'old', 'comments' => 'already set'],
        ];
        $s = $this->seedSubmission($good);
        $this->runMigration();
        $s->refresh();

        // real gene left as-is (not a placeholder)
        $this->assertSame('HGNC:9999', $s->submission_data->gene->id);
        // existing comments not clobbered by the legacy singular value
        $this->assertSame('already set', $s->submission_data->mechanism->comments);
        $this->assertFalse(property_exists($s->submission_data->mechanism, 'comment'));
    }

    public function test_is_idempotent(): void
    {
        $s = $this->seedSubmission($this->gapData(), $this->gapData());
        $this->runMigration();
        $after = json_encode($s->fresh()->original_submission_data);
        $this->runMigration();
        $this->assertSame($after, json_encode($s->fresh()->original_submission_data), 'second run must be a no-op');
    }

    public function test_only_live_published_portal_snapshots_are_backfilled(): void
    {
        $notPortal = $this->seedSubmission($this->gapData(), $this->gapData(), ['type' => Submission::TYPE_FILE_SUBMISSION]);
        $notPublished = $this->seedSubmission($this->gapData(), $this->gapData(), ['status' => Submission::STATUS_NEW]);
        $notLive = $this->seedSubmission($this->gapData(), $this->gapData(), ['is_live' => false]);

        $this->runMigration();

        foreach ([$notPortal, $notPublished, $notLive] as $submission) {
            $frozen = $submission->fresh()->original_submission_data;
            $this->assertSame('', $frozen->gene->id);
            $this->assertSame('', $frozen->moi->id);
            $this->assertSame('', $frozen->classification->id);
            $this->assertIsArray($frozen->additional_information);
        }
    }

    public function test_backfill_processes_rows_beyond_the_first_chunk(): void
    {
        $overrides = [
            'status' => Submission::STATUS_PUBLISHED,
            'type' => Submission::TYPE_PORTAL_SUBMISSION,
            'is_live' => true,
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'inheritance_id' => $this->inheritance->id,
            'classification_id' => $this->classification->id,
            'submission_data' => $this->gapData(),
            'original_submission_data' => $this->gapData(),
        ];

        $submissions = Submission::factory()->count(501)->create($overrides);
        $lastSubmission = $submissions->last();

        $this->runMigration();

        $frozen = $lastSubmission->fresh()->original_submission_data;
        $this->assertSame($this->gene->hgnc_id, $frozen->gene->id);
        $this->assertSame($this->inheritance->curie, $frozen->moi->id);
        $this->assertSame($this->classification->curie, $frozen->classification->id);
    }
}
