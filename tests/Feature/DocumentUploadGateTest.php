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

    private function upload(string $path)
    {
        $file = UploadedFile::fake()->createWithContent('submission.xlsx', file_get_contents($path));
        unlink($path);

        return $this->actingAs($this->user)
            ->post('/api/documents/'.$this->job->ident, ['file' => $file]);
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
