<?php

namespace Tests\Feature;

use App\Http\Controllers\API\DocumentController;
use App\Models\Classification;
use App\Models\Disease;
use App\Models\Document;
use App\Models\Gene;
use App\Models\Inheritance;
use App\Models\Job;
use App\Models\Pubmed;
use App\Models\Submission;
use App\Models\Submitter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The PubMed links an uploaded file leaves on each submission version, by row
 * action: a new or republished row is linked to the PMIDs in the sheet, and an
 * unpublish row keeps the links of the version it unpublishes.
 */
class DocumentParserPubmedLinksTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = [
        'sgc_id', 'action', 'local_key', 'hgnc_id', 'hgnc_symbol',
        'disease_id', 'disease_name', 'moi_id', 'moi_name',
        'submitter_id', 'submitter_name', 'classification_id', 'classification_name',
        'date', 'public_report_url', 'notes', 'pmids', 'assertion_criteria_url',
    ];

    private Submitter $submitter;

    private User $user;

    private Job $job;

    /** @var array<string, Pubmed> keyed by PMID */
    private array $pubmeds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->submitter = Submitter::factory()->create(['curie' => 'GENCC:000199']);
        $this->user = User::factory()->create(['submitter_id' => $this->submitter->id]);
        $this->job = Job::factory()->create([
            'submitter_id' => $this->submitter->id,
            'user_id' => $this->user->id,
            'status' => Job::STATUS_DRAFT,
        ]);

        Gene::factory()->create(['hgnc_id' => 'HGNC:5', 'symbol' => 'A1BG']);
        Disease::factory()->mondo()->create(['curie' => 'MONDO:0000002']);
        Inheritance::factory()->create(['curie' => 'HP:0000006']);
        Classification::factory()->create(['curie' => 'GENCC:100001']);

        foreach (['111', '222', '333'] as $pmid) {
            $this->pubmeds[$pmid] = Pubmed::create(['pmid' => $pmid, 'uid' => $pmid, 'status' => Pubmed::STATUS_INITIALIZING]);
        }
    }

    public function test_a_new_row_is_linked_to_the_pmids_in_the_sheet(): void
    {
        $this->parse([$this->row('N', pmids: '111, 222')]);

        $created = Submission::where('job_id', $this->job->id)->sole();
        $this->assertSame(['111', '222'], array_column(
            json_decode(json_encode($created->submission_data->evidence), true),
            'pmid'
        ));
        $this->assertSame(['111', '222'], $this->linkedPmids($created));
    }

    public function test_a_republish_row_is_linked_only_to_the_pmids_in_the_sheet(): void
    {
        $original = $this->publishedSubmission('SGC-100001', ['333']);

        $this->parse([$this->row('R', sgcId: 'SGC-100001', pmids: '111')]);

        $version = Submission::where('sid', 'SGC-100001')->where('version_number', 2)->sole();
        $this->assertSame(['111'], $this->linkedPmids($version));
        $this->assertSame(['333'], $this->linkedPmids($original));
    }

    public function test_a_republish_row_replaces_the_links_of_a_reused_draft(): void
    {
        $this->publishedSubmission('SGC-100001', ['333']);
        $draft = Submission::factory()->create([
            'sid' => 'SGC-100001',
            'submitter_id' => $this->submitter->id,
            'job_id' => $this->job->id,
            'status' => Submission::STATUS_DRAFT_REPUBLISH,
            'version_number' => 2,
        ]);
        $draft->pubmeds()->attach([$this->pubmeds['222']->id]);

        $this->parse([$this->row('R', sgcId: 'SGC-100001', pmids: '111')]);

        $this->assertSame(['111'], $this->linkedPmids($draft));
    }

    public function test_an_unpublish_row_keeps_the_links_of_the_version_it_unpublishes(): void
    {
        $original = $this->publishedSubmission('SGC-100002', ['222', '333']);

        $this->parse([$this->row('U', sgcId: 'SGC-100002')]);

        $version = Submission::where('sid', 'SGC-100002')->where('version_number', 2)->sole();
        $this->assertSame(Submission::STATUS_DRAFT_UNPUBLISH, $version->status);
        $this->assertSame(['222', '333'], $this->linkedPmids($version));
        $this->assertSame(['222', '333'], $this->linkedPmids($original));
    }

    public function test_record_validation_preserves_pmid_cleanup_warnings_from_the_raw_cell(): void
    {
        $this->parse([$this->row('N', pmids: '111; NULL; not-a-pmid')]);

        $created = Submission::where('job_id', $this->job->id)->sole();
        $this->assertSame(['111'], $this->linkedPmids($created));
        $this->assertSame(['literal_null', 'non_numeric'], array_column($created->pmid_issues, 'reason'));
    }

    public function test_content_errors_create_an_editable_record_with_submission_errors(): void
    {
        $this->parse([$this->row('N', overrides: [
            'hgnc_id' => '',
            'disease_id' => 'NOT_A_DISEASE',
            'moi_id' => 'HP:9999999',
            'classification_id' => 'GENCC:999999',
            'date' => '2024-02-30',
            'public_report_url' => 'not-a-url',
            'pmids' => 'not-a-pmid',
            'assertion_criteria_url' => 'not-a-url',
        ])]);

        $created = Submission::where('job_id', $this->job->id)->sole();
        $this->assertSame(Submission::STATUS_DRAFT_NEW, $created->status);
        $this->assertSame('2024-02-30', $created->submission_data->report->display_date);
        $this->assertStringContainsString(
            'Not a date',
            $created->submission_errors->report_date
        );
        $this->assertEqualsCanonicalizing([
            'gene_hgnc_id',
            'disease_curie_id',
            'moi_curie_id',
            'classification_curie_id',
            'report_date',
            'report_url',
            'criteria_url',
            'invalid_pmid',
        ], array_keys((array) $created->submission_errors));
    }

    public function test_existing_duplicate_becomes_a_record_error_instead_of_preventing_creation(): void
    {
        $gene = Gene::where('hgnc_id', 'HGNC:5')->firstOrFail();
        $disease = Disease::where('curie', 'MONDO:0000002')->firstOrFail();
        $inheritance = Inheritance::where('curie', 'HP:0000006')->firstOrFail();

        $existing = Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $gene->id,
            'disease_id' => $disease->id,
            'original_disease_id' => $disease->id,
            'inheritance_id' => $inheritance->id,
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
        ]);

        $this->parse([$this->row('N')]);

        $created = Submission::where('id', '!=', $existing->id)->where('job_id', $this->job->id)->sole();
        $this->assertArrayHasKey('duplicate_submission', (array) $created->submission_errors);
    }

    public function test_invalid_date_keeps_the_submitted_value_and_specific_reason(): void
    {
        $this->parse([$this->row('N', overrides: ['date' => '2999-01-01'])]);

        $created = Submission::where('job_id', $this->job->id)->sole();
        $this->assertSame('2999-01-01', $created->submission_data->report->display_date);
        $this->assertStringContainsString(
            'outside the allowed date range',
            $created->submission_errors->report_date
        );
    }

    public function test_a_date_formatted_excel_cell_is_normalized_in_stored_submission_data(): void
    {
        $this->parse(
            [$this->row('N', overrides: ['date' => ExcelDate::stringToExcel('2023-07-13')])],
            fn ($sheet) => $sheet->getStyle('N13')->getNumberFormat()->setFormatCode('yyyy-mm-dd')
        );

        $created = Submission::where('job_id', $this->job->id)->sole();
        $this->assertSame('2023-07-13', substr((string) $created->report_date, 0, 10));
        $this->assertSame('2023-07-13', $created->submission_data->report->display_date);
        $this->assertSame('2023-07-13', $created->original_submission_data->report->display_date);
        $this->assertArrayNotHasKey('report_date', (array) $created->submission_errors);
    }

    private function publishedSubmission(string $sid, array $pmids): Submission
    {
        $submission = Submission::factory()->create([
            'sid' => $sid,
            'submitter_id' => $this->submitter->id,
            'status' => Submission::STATUS_PUBLISHED,
            'is_live' => true,
            'is_most_recent' => true,
            'version_number' => 1,
            'submission_data' => ['evidence' => array_map(fn ($pmid) => ['pmid' => $pmid], $pmids)],
        ]);
        $submission->pubmeds()->attach(array_map(fn ($pmid) => $this->pubmeds[$pmid]->id, $pmids));

        return $submission;
    }

    /**
     * A data row; an unpublish row carries only its action and SGC ID.
     */
    private function row(string $action, string $sgcId = '', string $pmids = '', array $overrides = []): array
    {
        if ($action === 'U') {
            return array_merge(array_fill_keys(self::COLUMNS, ''), ['sgc_id' => $sgcId, 'action' => 'U']);
        }

        return array_replace([
            'sgc_id' => $sgcId,
            'action' => $action,
            'local_key' => '',
            'hgnc_id' => 'HGNC:5',
            'hgnc_symbol' => 'A1BG',
            'disease_id' => 'MONDO:0000002',
            'disease_name' => 'a disease',
            'moi_id' => 'HP:0000006',
            'moi_name' => 'Autosomal dominant',
            'submitter_id' => $this->submitter->curie,
            'submitter_name' => $this->submitter->name,
            'classification_id' => 'GENCC:100001',
            'classification_name' => 'Definitive',
            'date' => '2024-01-15',
            'public_report_url' => '',
            'notes' => '',
            'pmids' => $pmids,
            'assertion_criteria_url' => 'https://example.com/criteria',
        ], $overrides);
    }

    /**
     * Write the rows into the template layout (headings on row 6, data from
     * row 13) and run the upload parser over them.
     */
    private function parse(array $rows, ?callable $configureSheet = null): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray(self::COLUMNS, null, 'A6');
        for ($r = 7; $r <= 12; $r++) {
            $sheet->setCellValue("A{$r}", 'help text');
        }
        $sheet->fromArray(array_map(fn ($row) => array_values(array_merge(array_fill_keys(self::COLUMNS, ''), $row)), $rows), null, 'A13');
        $configureSheet?->__invoke($sheet);

        $path = tempnam(sys_get_temp_dir(), 'upload');
        (new Xlsx($sheet->getParent()))->save($path);

        $contents = file_get_contents($path);
        $document = Document::create([
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'job_id' => $this->job->id,
            'file_name' => 'upload.xlsx',
            'extension' => 'xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => strlen($contents),
            'file_contents' => base64_encode($contents),
            'total_submissions' => count($rows),
        ]);
        unlink($path);

        $result = (new DocumentController)->parser($document);

        $this->assertSame(count($rows), $result['processed_rows']);
    }

    /** @return string[] */
    private function linkedPmids(Submission $submission): array
    {
        return $submission->pubmeds()->orderBy('pmid')->pluck('pmid')->all();
    }
}
