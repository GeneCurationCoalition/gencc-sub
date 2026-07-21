<?php

namespace Tests\Feature;

use App\Exports\ReleaseSubmissionExport;
use App\Models\Classification;
use App\Models\Disease;
use App\Models\Gene;
use App\Models\Inheritance;
use App\Models\Job;
use App\Models\Submission;
use App\Models\Submitter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalSubmittedAsBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_repairs_all_portal_placeholders_without_fabricating_labels(): void
    {
        [$submission, $fileSubmission, $submitter] = $this->makeAffectedSubmissions();
        $migration = require database_path('migrations/2026_07_21_120000_backfill_portal_submitted_as_json.php');

        $migration->up();

        $submission->refresh();
        foreach (['submission_data', 'original_submission_data'] as $column) {
            $data = $submission->{$column};
            $this->assertSame('HGNC:5', $data->gene->id);
            $this->assertNull($data->gene->symbol);
            $this->assertSame('MONDO:0000001', $data->disease->id);
            $this->assertNull($data->disease->name);
            $this->assertSame('HP:0000006', $data->moi->id);
            $this->assertNull($data->moi->name);
            $this->assertSame('GENCC:100001', $data->classification->id);
            $this->assertNull($data->classification->name);
            $this->assertSame($submitter->curie, $data->additional_information->submitter_curie);
            $this->assertNull($data->additional_information->submitter_title);
            $this->assertSame('LOCAL-1', $data->additional_information->submitted_as_submission_id);
            $this->assertSame($submission->sid, $data->submission_id);
        }

        $export = new ReleaseSubmissionExport;
        $exported = array_combine($export->headings(), $export->map($submission));
        $this->assertSame('HGNC:5', $exported['submitted_as_hgnc_id']);
        $this->assertSame('', $exported['submitted_as_hgnc_symbol']);
        $this->assertSame($submitter->curie, $exported['submitted_as_submitter_id']);
        $this->assertSame('', $exported['submitted_as_submitter_name']);

        // A structurally identical file submission is outside this portal-only repair.
        $fileSubmission->refresh();
        $this->assertSame('', $fileSubmission->submission_data->gene->id);

        $afterFirstRun = json_encode([
            $submission->submission_data,
            $submission->original_submission_data,
        ]);
        $migration->up();
        $submission->refresh();
        $this->assertSame($afterFirstRun, json_encode([
            $submission->submission_data,
            $submission->original_submission_data,
        ]));
    }

    private function makeAffectedSubmissions(): array
    {
        $submitter = Submitter::create([
            'name' => 'Test Submitter',
            'status' => Submitter::STATUS_ACTIVE,
            'type' => Submitter::TYPE_SUBMITTER,
        ]);
        $user = User::factory()->create(['submitter_id' => $submitter->id]);
        $job = Job::create([
            'user_id' => $user->id,
            'submitter_id' => $submitter->id,
            'status' => Job::STATUS_RELEASED,
            'type' => Job::TYPE_NONE,
        ]);
        $gene = Gene::create([
            'hgnc_id' => 'HGNC:5',
            'symbol' => 'A1BG',
            'name' => 'alpha-1-B glycoprotein',
            'locus_group' => 'protein-coding gene',
            'locus_type' => 'gene with protein product',
            'location' => '19q13.43',
            'status' => Gene::STATUS_ACTIVE,
        ]);
        $disease = Disease::create([
            'curie' => 'MONDO:0000001',
            'name' => 'canonical disease label',
            'status' => Disease::STATUS_ACTIVE,
        ]);
        $inheritance = Inheritance::create([
            'curie' => 'HP:0000006',
            'name' => 'Autosomal dominant',
            'description' => 'Autosomal dominant inheritance',
            'abbreviation' => 'AD',
            'type' => Inheritance::TYPE_MOI,
            'status' => Inheritance::STATUS_ACTIVE,
        ]);
        $classification = Classification::create([
            'curie' => 'GENCC:100001',
            'name' => 'Definitive',
            'description' => 'Definitive classification',
            'abbreviation' => 'DEF',
            'type' => Classification::TYPE_CLASSIFICATION,
            'status' => Classification::STATUS_ACTIVE,
        ]);

        $placeholder = [
            'submission_id' => null,
            'local_key' => null,
            'submission_label' => null,
            'gene' => ['id' => '', 'symbol' => ''],
            'disease' => ['id' => 'MONDO:0000001', 'name' => 'canonical disease label'],
            'moi' => ['id' => '', 'name' => ''],
            'classification' => ['id' => '', 'name' => ''],
            'additional_information' => [['key' => 'values']],
            'notes' => ['display' => 'Public note', 'private' => 'Private note'],
        ];

        $attributes = [
            'job_id' => $job->id,
            'user_id' => $user->id,
            'submitter_id' => $submitter->id,
            'gene_id' => $gene->id,
            'disease_id' => $disease->id,
            'original_disease_id' => $disease->id,
            'inheritance_id' => $inheritance->id,
            'classification_id' => $classification->id,
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
            'friendly' => 'Portal submission',
            'local_key' => 'LOCAL-1',
            'submission_data' => $placeholder,
            'original_submission_data' => $placeholder,
        ];

        $submission = Submission::create(array_merge($attributes, [
            'type' => Submission::TYPE_PORTAL_SUBMISSION,
        ]));
        $fileSubmission = Submission::create(array_merge($attributes, [
            'type' => Submission::TYPE_FILE_SUBMISSION,
            'status' => Submission::STATUS_NEW,
            'is_live' => false,
            'local_key' => 'LOCAL-2',
        ]));

        return [$submission, $fileSubmission, $submitter];
    }
}
