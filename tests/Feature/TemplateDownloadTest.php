<?php

namespace Tests\Feature;

use App\Exports\SubmissionsTemplateExport;
use App\Models\Classification;
use App\Models\Inheritance;
use App\Models\Submission;
use App\Models\Submitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class TemplateDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_download_returns_xlsx(): void
    {
        Submitter::create([
            'name' => 'Test Submitter A',
            'status' => Submitter::STATUS_ACTIVE,
            'allow_submissions' => true,
        ]);

        $response = $this->get('/download/template');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_template_download_contains_active_submitters(): void
    {
        $active = Submitter::create([
            'name' => 'Active Submitter',
            'status' => Submitter::STATUS_ACTIVE,
            'allow_submissions' => true,
        ]);

        Submitter::create([
            'name' => 'Inactive Submitter',
            'status' => Submitter::STATUS_REMOVED,
            'allow_submissions' => true,
        ]);

        Submitter::create([
            'name' => 'No Submissions Submitter',
            'status' => Submitter::STATUS_ACTIVE,
            'allow_submissions' => false,
        ]);

        $spreadsheet = $this->loadAndPopulate();
        $sheet = $spreadsheet->getSheetByName('HELP - Submitters');

        $this->assertEquals($active->curie, $sheet->getCell('A4')->getValue());
        $this->assertEquals('Active Submitter', $sheet->getCell('B4')->getValue());
        $this->assertNull($sheet->getCell('A5')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_populate_submitter_sheet_preserves_header_rows(): void
    {
        Submitter::create([
            'name' => 'Test Submitter',
            'status' => Submitter::STATUS_ACTIVE,
            'allow_submissions' => true,
        ]);

        $spreadsheet = $this->loadAndPopulate();
        $sheet = $spreadsheet->getSheetByName('HELP - Submitters');

        $this->assertStringContainsString('SUBMITTER ID', $sheet->getCell('A1')->getValue());
        $this->assertEquals('submitter_id', $sheet->getCell('A3')->getValue());
        $this->assertEquals('submitter_name', $sheet->getCell('B3')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_classification_sheet_populated_from_database(): void
    {
        $first = Classification::factory()->create([
            'curie' => 'GENCC:100001',
            'name' => 'Definitive',
            'status' => Classification::STATUS_ACTIVE,
            'order' => 10,
        ]);
        $second = Classification::factory()->create([
            'curie' => 'GENCC:100002',
            'name' => 'Strong',
            'status' => Classification::STATUS_ACTIVE,
            'order' => 20,
        ]);
        Classification::factory()->create([
            'curie' => 'GENCC:100099',
            'name' => 'Removed Classification',
            'status' => Classification::STATUS_REMOVED,
            'order' => 99,
        ]);

        $spreadsheet = $this->loadAndPopulate();
        $sheet = $spreadsheet->getSheetByName('HELP - Classifications');

        // Header preserved
        $this->assertStringContainsString('CLASSIFICATION_ID', $sheet->getCell('A1')->getValue());
        $this->assertEquals('classification_id', $sheet->getCell('A3')->getValue());

        // Active classifications present, ordered by order column
        $this->assertEquals('GENCC:100001', $sheet->getCell('A4')->getValue());
        $this->assertEquals('Definitive', $sheet->getCell('B4')->getValue());
        $this->assertEquals('GENCC:100002', $sheet->getCell('A5')->getValue());
        $this->assertEquals('Strong', $sheet->getCell('B5')->getValue());

        // Removed classification excluded
        $this->assertNull($sheet->getCell('A6')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_moi_sheet_only_includes_used_inheritances(): void
    {
        $used = Inheritance::factory()->create([
            'curie' => 'HP:0000006',
            'name' => 'Autosomal dominant',
            'status' => Inheritance::STATUS_ACTIVE,
        ]);
        Inheritance::factory()->create([
            'curie' => 'HP:0000007',
            'name' => 'Autosomal recessive (unused)',
            'status' => Inheritance::STATUS_ACTIVE,
        ]);
        Inheritance::factory()->create([
            'curie' => 'HP:9999999',
            'name' => 'Removed MOI',
            'status' => Inheritance::STATUS_REMOVED,
        ]);

        // Create a submission linked to the "used" inheritance
        Submission::factory()->create(['inheritance_id' => $used->id]);

        $spreadsheet = $this->loadAndPopulate();
        $sheet = $spreadsheet->getSheetByName('HELP - MOI');

        // Header preserved
        $this->assertEquals('moi_id', $sheet->getCell('A3')->getValue());
        $this->assertEquals('moi_name', $sheet->getCell('B3')->getValue());

        // Only the used MOI appears
        $this->assertEquals('HP:0000006', $sheet->getCell('A4')->getValue());
        $this->assertEquals('Autosomal dominant', $sheet->getCell('B4')->getValue());

        // Unused and removed MOIs excluded
        $this->assertNull($sheet->getCell('A5')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_submission_export_uses_relations_instead_of_blank_live_json_mirrors(): void
    {
        $export = new SubmissionsTemplateExport([[
            'sid' => 'SGC-100001',
            'local_key' => 'LOCAL-1',
            'report_date' => '2026-07-14',
            'report_url' => 'https://example.org/report',
            'submission_data' => [
                'gene' => ['id' => '', 'symbol' => ''],
                'disease' => ['id' => 'OMIM:123456', 'name' => 'Historical disease label'],
                'moi' => ['id' => '', 'name' => ''],
                'classification' => ['id' => '', 'name' => ''],
                'additional_information' => [['key' => 'values']],
                'notes' => ['display' => 'Public note'],
                'criteria' => ['url' => 'https://example.org/criteria'],
            ],
            'gene' => ['hgnc_id' => 'HGNC:5', 'symbol' => 'A1BG'],
            'disease' => ['curie' => 'MONDO:0000001', 'name' => 'Current normalized disease'],
            'inheritance' => ['curie' => 'HP:0000006', 'name' => 'Autosomal dominant'],
            'classification' => ['curie' => 'GENCC:100001', 'name' => 'Definitive'],
            'submitter' => ['curie' => 'GENCC:000102', 'name' => 'ClinGen'],
            'evidence' => ['12345678'],
        ]]);

        $spreadsheet = $export->generate();
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('HGNC:5', $sheet->getCell('D13')->getValue());
        $this->assertSame('A1BG', $sheet->getCell('E13')->getValue());
        $this->assertSame('OMIM:123456', $sheet->getCell('F13')->getValue());
        $this->assertSame('Historical disease label', $sheet->getCell('G13')->getValue());
        $this->assertSame('HP:0000006', $sheet->getCell('H13')->getValue());
        $this->assertSame('Autosomal dominant', $sheet->getCell('I13')->getValue());
        $this->assertSame('GENCC:000102', $sheet->getCell('J13')->getValue());
        $this->assertSame('ClinGen', $sheet->getCell('K13')->getValue());
        $this->assertSame('GENCC:100001', $sheet->getCell('L13')->getValue());
        $this->assertSame('Definitive', $sheet->getCell('M13')->getValue());
        $this->assertSame('https://example.org/report', $sheet->getCell('O13')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    /**
     * Load the template and populate all help sheets.
     */
    private function loadAndPopulate(): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $templatePath = public_path('documents/GenCC Submission Spreadsheet.xlsx');
        $spreadsheet = IOFactory::load($templatePath);
        SubmissionsTemplateExport::populateHelpSheets($spreadsheet);

        return $spreadsheet;
    }
}
