<?php

namespace Tests\Feature;

use App\Events\SpreadsheetUpdate;
use App\Jobs\ProcessSubmissionsUpload;
use App\Models\Job;
use App\Models\Submitter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DocumentUploadGateTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = [
        'sgc_id',
        'action',
        'local_key',
        'hgnc_id',
        'hgnc_symbol',
        'disease_id',
        'disease_name',
        'moi_id',
        'moi_name',
        'submitter_id',
        'submitter_name',
        'classification_id',
        'classification_name',
        'date',
        'public_report_url',
        'notes',
        'pmids',
        'assertion_criteria_url',
    ];

    private User $user;

    private Submitter $submitter;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submitter = Submitter::create([
            'curie' => 'GENCC:000101',
            'name' => 'Upload Gate Test Submitter',
            'status' => 1,
            'type' => 0,
        ]);
        $this->user = User::factory()->create(['submitter_id' => $this->submitter->id]);
        $this->job = Job::create([
            'submitter_id' => $this->submitter->id,
            'user_id' => $this->user->id,
            'status' => Job::STATUS_DRAFT,
            'type' => Job::TYPE_FILE_SUBMISSION,
        ]);

        Event::fake([SpreadsheetUpdate::class]);
        Queue::fake();
    }

    public function test_content_errors_pass_the_file_gate_and_are_queued_for_record_validation(): void
    {
        $row = array_fill(0, count(self::COLUMNS), '');
        $row[1] = 'N';
        $row[2] = 'CONTENT-ERRORS';
        $row[3] = 'NOT-AN-HGNC-ID';
        $row[5] = 'NOT-A-DISEASE';
        $row[7] = 'NOT-AN-MOI';
        $row[9] = $this->submitter->curie;
        $row[11] = 'NOT-A-CLASSIFICATION';
        $row[13] = 'not-a-date';
        $row[14] = 'not-a-url';
        $row[16] = 'not-a-pmid';
        $row[17] = 'not-a-url';

        $response = $this->upload($this->workbook(self::COLUMNS, [$row]));

        $response->assertOk()->assertJson([
            'success' => 'true',
            'status_code' => 200,
            'row_count' => 1,
        ]);
        Queue::assertPushed(ProcessSubmissionsUpload::class);
    }

    public function test_structural_errors_stop_before_processing_and_return_no_warnings(): void
    {
        $headers = self::COLUMNS;
        [$headers[3], $headers[5]] = [$headers[5], $headers[3]];
        $row = array_fill(0, count(self::COLUMNS), '');
        $row[1] = 'N';
        $row[9] = $this->submitter->curie;

        $response = $this->upload($this->workbook($headers, [$row]));

        $response->assertStatus(422)->assertJson([
            'success' => 'false',
            'status_code' => 6005,
            'warnings' => [],
        ]);
        $this->assertSame('invalid_header_columns', $response->json('errors.0.error_type'));
        Queue::assertNotPushed(ProcessSubmissionsUpload::class);
    }

    public function test_official_template_examples_do_not_block_a_row_thirteen_submission(): void
    {
        $spreadsheet = IOFactory::load(public_path('documents/GenCC Submission Spreadsheet.xlsx'));
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('R', $sheet->getCell('B9')->getValue());
        $this->assertSame('U', $sheet->getCell('B10')->getValue());
        $this->assertSame('N', $sheet->getCell('B11')->getValue());

        $row = array_fill(0, count(self::COLUMNS), '');
        $row[1] = 'N';
        $row[3] = 'NOT-AN-HGNC-ID';
        $row[5] = 'NOT-A-DISEASE';
        $row[7] = 'NOT-AN-MOI';
        $row[9] = $this->submitter->curie;
        $row[11] = 'NOT-A-CLASSIFICATION';
        $row[13] = 'not-a-date';
        $row[17] = 'not-a-url';
        $sheet->fromArray($row, null, 'A13');

        $path = tempnam(sys_get_temp_dir(), 'official-upload-gate-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $response = $this->upload($path);

        $response->assertOk()->assertJson([
            'success' => 'true',
            'status_code' => 200,
            'row_count' => 1,
        ]);
        Queue::assertPushed(ProcessSubmissionsUpload::class);
    }

    private function upload(string $path)
    {
        $file = UploadedFile::fake()->createWithContent('submission.xlsx', file_get_contents($path));
        unlink($path);

        return $this->actingAs($this->user)
            ->post('/api/documents/'.$this->job->ident, ['file' => $file]);
    }

    public function test_cross_namespace_workbook_rows_pass_the_gate_but_repeated_submitted_ids_do_not(): void
    {
        [$row, $mondo] = $this->relationshipRow();
        $second = $row;
        $second[5] = $mondo->curie;
        $second[2] = 'SECOND';
        $this->upload($this->workbook(self::COLUMNS, [$row, $second]))->assertOk();
        Queue::assertPushed(ProcessSubmissionsUpload::class, 1);

        $second[5] = $row[5];
        $this->upload($this->workbook(self::COLUMNS, [$row, $second]))
            ->assertStatus(422)->assertJsonPath('errors.0.error_type', 'duplicate_submission');
        Queue::assertPushed(ProcessSubmissionsUpload::class, 1);
    }

    public function test_existing_shared_mondo_peer_is_not_a_record_error_but_a_submitted_id_duplicate_is(): void
    {
        [$row, $mondo, $omim, $gene, $moi] = $this->relationshipRow();
        $peer = \App\Models\Submission::factory()->create([
            'submitter_id' => $this->submitter->id, 'user_id' => $this->user->id,
            'gene_id' => $gene->id, 'inheritance_id' => $moi->id,
            'original_disease_id' => $mondo->id, 'disease_id' => $mondo->id,
            'status' => \App\Models\Submission::STATUS_PUBLISHED, 'is_live' => true,
        ]);
        $import = function () use ($row) {
            $response = $this->upload($this->workbook(self::COLUMNS, [$row]));
            $response->assertOk();
            $document = \App\Models\Document::findOrFail($response->json('document_id'));
            (new ProcessSubmissionsUpload($document))->handle();

            return \App\Models\Submission::where('document_id', $document->id)->sole();
        };
        $created = $import();
        $this->assertSame($omim->id, $created->original_disease_id);
        $this->assertSame($mondo->id, $created->disease_id);
        $this->assertNull(data_get($created->submission_errors, 'duplicate_submission'));

        $created->forceDelete();
        \Illuminate\Support\Facades\DB::table('submissions')->where('id', $peer->id)->update(['original_disease_id' => $omim->id]);
        $this->assertStringContainsString($peer->sid, data_get($import()->submission_errors, 'duplicate_submission'));
    }

    public function test_already_unpublished_submitted_id_duplicate_does_not_block_upload_or_record(): void
    {
        [$row, $mondo, $omim, $gene, $moi] = $this->relationshipRow();
        \App\Models\Submission::factory()->create([
            'submitter_id' => $this->submitter->id, 'user_id' => $this->user->id,
            'gene_id' => $gene->id, 'inheritance_id' => $moi->id,
            'original_disease_id' => $omim->id, 'disease_id' => $mondo->id,
            'status' => \App\Models\Submission::STATUS_UNPUBLISHED, 'is_live' => true,
        ]);
        $response = $this->upload($this->workbook(self::COLUMNS, [$row]));
        $response->assertOk();
        $document = \App\Models\Document::findOrFail($response->json('document_id'));
        (new ProcessSubmissionsUpload($document))->handle();
        $created = \App\Models\Submission::where('document_id', $document->id)->sole();
        $this->assertEmpty($created->submission_errors);
        $this->assertFalse($created->has_errors);
    }

    private function relationshipRow(): array
    {
        $gene = \App\Models\Gene::factory()->create(['hgnc_id' => 'HGNC:5']);
        $moi = \App\Models\Inheritance::factory()->create(['curie' => 'HP:0000006']);
        $classification = \App\Models\Classification::factory()->create(['curie' => 'GENCC:100001']);
        $omim = \App\Models\Disease::factory()->create(['curie' => 'OMIM:123456', 'type' => \App\Models\Disease::TYPE_OMIM]);
        $mondo = \App\Models\Disease::factory()->mondo()->create(['curie' => 'MONDO:0000001', 'xrefs' => ['omim_id' => ['123456']]]);
        $row = array_fill(0, count(self::COLUMNS), '');
        foreach ([1 => 'N', 2 => 'FIRST', 3 => $gene->hgnc_id, 5 => $omim->curie, 7 => $moi->curie,
            9 => $this->submitter->curie, 11 => $classification->curie, 13 => '2024-01-01', 17 => 'https://example.org/criteria'] as $index => $value) {
            $row[$index] = $value;
        }

        return [$row, $mondo, $omim, $gene, $moi];
    }

    private function workbook(array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A6');
        $sheet->setCellValue('A12', 'Submission data starts on row 13.');
        $sheet->fromArray($rows, null, 'A13');

        $path = tempnam(sys_get_temp_dir(), 'upload-gate-');
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
