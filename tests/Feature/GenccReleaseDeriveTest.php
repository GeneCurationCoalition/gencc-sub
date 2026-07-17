<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Submission;
use App\Models\Job;
use App\Models\Gene;
use App\Models\Disease;
use App\Models\Inheritance;
use App\Models\Classification;
use App\Models\Submitter;
use App\Console\Commands\GenccRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Release-time derive fix for issue #114.
 *
 * The public export reads submitted_as_* from the frozen original_submission_data. The manual-entry
 * form only sets the FK columns and leaves submission_data's gene/moi/classification/
 * additional_information as empty placeholders, which the release freeze used to capture as blanks.
 * GenccRelease::frozenSnapshotFor() now re-derives those four sub-objects from the row's FK
 * relations at freeze time. These tests pin that.
 */
class GenccReleaseDeriveTest extends TestCase
{
    use RefreshDatabase;

    private Gene $gene;
    private Disease $disease;
    private Inheritance $inheritance;
    private Classification $classification;
    private Submitter $submitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submitter = Submitter::create(['name' => 'Test Lab', 'status' => Submitter::STATUS_ACTIVE, 'type' => 0]);
        $this->gene = Gene::create([
            'hgnc_id' => 'HGNC:5', 'symbol' => 'A1BG', 'name' => 'alpha-1-B glycoprotein',
            'locus_group' => 'protein-coding gene', 'locus_type' => 'gene with protein product',
            'location' => '19q13.43', 'status' => Gene::STATUS_ACTIVE,
        ]);
        $this->disease = Disease::create([
            'curie' => 'MONDO:0000001', 'name' => 'Test Disease', 'description' => 'x', 'status' => Disease::STATUS_ACTIVE,
        ]);
        $this->inheritance = Inheritance::create([
            'curie' => 'HP:0000006', 'name' => 'Autosomal dominant', 'description' => 'x',
            'abbreviation' => 'AD', 'status' => Inheritance::STATUS_ACTIVE,
        ]);
        $this->classification = Classification::create([
            'curie' => 'GENCC:100001', 'name' => 'Definitive', 'description' => 'x',
            'abbreviation' => 'DEF', 'status' => Classification::STATUS_ACTIVE,
        ]);
    }

    private function invokeReleaseJob(Job $job): int
    {
        $command = new GenccRelease();
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $method = new \ReflectionMethod(GenccRelease::class, 'releaseJob');
        $method->setAccessible(true);

        return $method->invoke($command, $job->fresh());
    }

    /** submission_data with the FK-derived sub-objects left as empty placeholders (the #114 gap). */
    private function placeholderData(): array
    {
        return [
            'type' => 'Reserved',
            'gene' => ['id' => '', 'symbol' => ''],
            'moi' => ['id' => '', 'name' => ''],
            'classification' => ['id' => '', 'name' => ''],
            'disease' => ['id' => 'MONDO:0000001', 'name' => 'Test Disease'],
            'additional_information' => [['key' => 'values']],
            'notes' => ['display' => 'kept-notes', 'private' => 'kept-private'],
            'criteria' => ['url' => 'https://example.org/c', 'name' => ''],
            'version' => ['display' => '1.0', 'reasons' => ['NEW_CURATION'], 'internal' => '1.0.0.0', 'description' => ''],
        ];
    }

    private function makePendingSubmission(array $submissionData, array $overrides = []): Submission
    {
        $job = Job::factory()->create(['status' => Job::STATUS_SUBMITTED, 'submitter_id' => $this->submitter->id]);

        return Submission::factory()->create(array_merge([
            'job_id' => $job->id,
            'status' => Submission::STATUS_NEW,
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'disease_id' => $this->disease->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'classification_id' => $this->classification->id,
            'local_key' => 'LOCAL-114',
            'submission_data' => $submissionData,
            'original_submission_data' => null,
        ], $overrides));
    }

    public function test_release_derives_fk_backed_subobjects_into_frozen_snapshot(): void
    {
        $submission = $this->makePendingSubmission($this->placeholderData());
        $this->invokeReleaseJob($submission->job);
        $submission->refresh();

        $this->assertEquals(Submission::STATUS_PUBLISHED, $submission->status);

        $frozen = $submission->original_submission_data;
        // the four FK-derived sub-objects are now the resolved values, not the blank placeholders
        $this->assertSame($this->gene->hgnc_id, $frozen->gene->id);
        $this->assertSame($this->gene->symbol, $frozen->gene->symbol);
        $this->assertSame($this->inheritance->curie, $frozen->moi->id);
        $this->assertSame($this->inheritance->name, $frozen->moi->name);
        $this->assertSame($this->classification->curie, $frozen->classification->id);
        $this->assertSame($this->classification->name, $frozen->classification->name);
        $this->assertSame($this->submitter->curie, $frozen->additional_information->submitter_curie);
        $this->assertSame($this->submitter->name, $frozen->additional_information->submitter_title);
        $this->assertSame('LOCAL-114', $frozen->additional_information->submitted_as_submission_id);
    }

    public function test_release_preserves_non_derived_fields(): void
    {
        $submission = $this->makePendingSubmission($this->placeholderData());
        $this->invokeReleaseJob($submission->job);
        $submission->refresh();

        $frozen = $submission->original_submission_data;
        // disease/notes/criteria are NOT re-derived - they come straight from submission_data
        $this->assertSame('Test Disease', $frozen->disease->name);
        $this->assertSame('kept-notes', $frozen->notes->display);
        $this->assertSame('kept-private', $frozen->notes->private);
        $this->assertSame('https://example.org/c', $frozen->criteria->url);
    }

    public function test_release_does_not_fabricate_when_fk_absent(): void
    {
        // no gene FK: the derive must leave the placeholder rather than invent a gene
        $submission = $this->makePendingSubmission($this->placeholderData(), ['gene_id' => null]);

        $this->invokeReleaseJob($submission->job);
        $submission->refresh();

        $this->assertSame('', $submission->original_submission_data->gene->id);
        $this->assertSame('', $submission->original_submission_data->gene->symbol);
        // the other FKs are still present, so they still derive
        $this->assertSame($this->classification->curie, $submission->original_submission_data->classification->id);
    }

    /** submission_data itself is left untouched (immutability guard); only the frozen copy is derived. */
    public function test_live_submission_data_placeholders_left_untouched(): void
    {
        $submission = $this->makePendingSubmission($this->placeholderData());
        $this->invokeReleaseJob($submission->job);
        $submission->refresh();

        $this->assertSame('', $submission->submission_data->gene->id);
        $this->assertSame($this->gene->hgnc_id, $submission->original_submission_data->gene->id);
    }
}
