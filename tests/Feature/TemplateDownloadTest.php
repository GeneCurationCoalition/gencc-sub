<?php

namespace Tests\Feature;

use App\Exports\SubmissionsTemplateExport;
use App\Models\Submitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class TemplateDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_download_returns_xlsx(): void
    {
        // Create some submitters
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

        $templatePath = public_path('documents/GenCC Submission Spreadsheet.xlsx');
        $spreadsheet = IOFactory::load($templatePath);
        SubmissionsTemplateExport::populateSubmitterSheet($spreadsheet);

        $sheet = $spreadsheet->getSheetByName('HELP - Submitters');

        // Row 4 should have the active submitter
        $this->assertEquals($active->curie, $sheet->getCell('A4')->getValue());
        $this->assertEquals('Active Submitter', $sheet->getCell('B4')->getValue());

        // Row 5 should be empty (inactive and no-submissions submitters excluded)
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

        $templatePath = public_path('documents/GenCC Submission Spreadsheet.xlsx');
        $spreadsheet = IOFactory::load($templatePath);
        SubmissionsTemplateExport::populateSubmitterSheet($spreadsheet);

        $sheet = $spreadsheet->getSheetByName('HELP - Submitters');

        // Header rows should be preserved
        $this->assertStringContainsString('SUBMITTER ID', $sheet->getCell('A1')->getValue());
        $this->assertEquals('submitter_id', $sheet->getCell('A3')->getValue());
        $this->assertEquals('submitter_name', $sheet->getCell('B3')->getValue());

        $spreadsheet->disconnectWorksheets();
    }
}
