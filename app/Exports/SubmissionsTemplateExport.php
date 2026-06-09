<?php

namespace App\Exports;

use App\Models\Classification;
use App\Models\Inheritance;
use App\Models\Submitter;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class SubmissionsTemplateExport
{
    protected array $submissions;

    public function __construct(array $submissions)
    {
        $this->submissions = $submissions;
    }

    /**
     * Generate the Excel file with submissions appended to the template
     */
    public function generate(): Spreadsheet
    {
        Log::info('SubmissionsTemplateExport::generate called with '.count($this->submissions).' submissions');

        // Load the template file
        $templatePath = public_path('documents/GenCC Submission Spreadsheet.xlsx');

        Log::info('Template path: '.$templatePath);

        if (! file_exists($templatePath)) {
            Log::error('Template file not found at: '.$templatePath);
            throw new \Exception('Template file not found');
        }

        Log::info('Loading template...');
        $spreadsheet = IOFactory::load($templatePath);
        Log::info('Template loaded successfully');

        // Populate help sheets with current data from the database
        self::populateHelpSheets($spreadsheet);

        $worksheet = $spreadsheet->getActiveSheet();

        // Start writing data at row 13
        $startRow = 13;

        foreach ($this->submissions as $index => $submission) {
            $rowNum = $startRow + $index;

            // Column A: SGC ID
            $worksheet->setCellValue("A{$rowNum}", $submission['sid'] ?? '');

            // Column B: Action (default to R for Republish)
            $worksheet->setCellValue("B{$rowNum}", 'R');

            // Column C: Local Key
            $worksheet->setCellValue("C{$rowNum}", $submission['local_key'] ?? '');

            // Column D: HGNC ID
            $hgncId = $submission['submission_data']['gene']['id'] ?? null;
            if (! $hgncId || $hgncId === '-') {
                $hgncId = $submission['gene']['hgnc_id'] ?? '';
            }
            $worksheet->setCellValue("D{$rowNum}", $hgncId);

            // Column E: Gene Symbol
            $geneSymbol = $submission['submission_data']['gene']['symbol'] ?? null;
            if (! $geneSymbol || $geneSymbol === '-') {
                $geneSymbol = $submission['gene']['symbol'] ?? '';
            }
            $worksheet->setCellValue("E{$rowNum}", $geneSymbol);

            // Column F: Disease ID
            $diseaseId = $submission['submission_data']['disease']['id'] ?? ($submission['disease']['curie'] ?? '');
            $worksheet->setCellValue("F{$rowNum}", $diseaseId);

            // Column G: Disease Name
            $diseaseName = $submission['submission_data']['disease']['name'] ?? ($submission['disease']['name'] ?? '');
            $worksheet->setCellValue("G{$rowNum}", $diseaseName);

            // Column H: MOI ID
            $moiId = $submission['submission_data']['moi']['id'] ?? ($submission['inheritance']['curie'] ?? '');
            $worksheet->setCellValue("H{$rowNum}", $moiId);

            // Column I: MOI Name
            $moiName = $submission['submission_data']['moi']['name'] ?? ($submission['inheritance']['name'] ?? '');
            $worksheet->setCellValue("I{$rowNum}", $moiName);

            // Column J: Submitter ID
            $submitterId = $submission['submitter']['curie'] ?? ($submission['submission_data']['additional_information']['submitter_curie'] ?? '');
            $worksheet->setCellValue("J{$rowNum}", $submitterId);

            // Column K: Submitter Name
            $submitterName = $submission['submitter']['name'] ?? ($submission['submission_data']['additional_information']['submitter_title'] ?? '');
            $worksheet->setCellValue("K{$rowNum}", $submitterName);

            // Column L: Classification ID
            $classificationId = $submission['submission_data']['classification']['id'] ?? ($submission['classification']['curie'] ?? '');
            $worksheet->setCellValue("L{$rowNum}", $classificationId);

            // Column M: Classification Name
            $classificationName = $submission['submission_data']['classification']['name'] ?? ($submission['classification']['name'] ?? '');
            $worksheet->setCellValue("M{$rowNum}", $classificationName);

            // Column N: Report Date (ISO8601)
            $reportDate = $submission['submission_data']['report']['display_date'] ?? '';
            if ($reportDate) {
                try {
                    $date = new \DateTime($reportDate);
                    $reportDate = $date->format('c'); // ISO8601 format
                } catch (\Exception $e) {
                    // Keep original value if parsing fails
                }
            }
            $worksheet->setCellValue("N{$rowNum}", $reportDate);

            // Column O: Report URL
            $reportUrl = $submission['submission_data']['report']['ext_url'] ?? '';
            $worksheet->setCellValue("O{$rowNum}", $reportUrl);

            // Column P: Notes (preserves whitespace)
            $notes = $submission['submission_data']['notes']['display'] ?? '';
            $worksheet->setCellValue("P{$rowNum}", $notes);

            // Column Q: PMIDs
            $pmids = $submission['evidence'] ?? [];
            $worksheet->setCellValue("Q{$rowNum}", implode(', ', $pmids));

            // Column R: Assertion Criteria URL
            $criteriaUrl = $submission['submission_data']['criteria']['url'] ?? '';
            $worksheet->setCellValue("R{$rowNum}", $criteriaUrl);
        }

        return $spreadsheet;
    }

    /**
     * Populate all help sheets with current data from the database.
     */
    public static function populateHelpSheets(Spreadsheet $spreadsheet): void
    {
        self::populateSubmitterSheet($spreadsheet);
        self::populateClassificationSheet($spreadsheet);
        self::populateMoiSheet($spreadsheet);
    }

    /**
     * Populate the "HELP - Submitters" sheet with current submitter data.
     *
     * Clears existing data rows and writes active submitters from the database.
     */
    public static function populateSubmitterSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('HELP - Submitters');

        if ($sheet === null) {
            Log::warning("Template sheet 'HELP - Submitters' not found, skipping");

            return;
        }

        self::clearDataRows($sheet, 4);

        $submitters = Submitter::where('status', Submitter::STATUS_ACTIVE)
            ->where('allow_submissions', true)
            ->orderBy('curie')
            ->get(['curie', 'name']);

        $row = 4;
        foreach ($submitters as $submitter) {
            $sheet->setCellValue("A{$row}", $submitter->curie);
            $sheet->setCellValue("B{$row}", $submitter->name);
            $row++;
        }
    }

    /**
     * Populate the "HELP - Classifications" sheet with current classification data.
     */
    public static function populateClassificationSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('HELP - Classifications');

        if ($sheet === null) {
            Log::warning("Template sheet 'HELP - Classifications' not found, skipping");

            return;
        }

        self::clearDataRows($sheet, 4);

        $classifications = Classification::where('status', Classification::STATUS_ACTIVE)
            ->orderBy('order')
            ->get(['curie', 'name']);

        $row = 4;
        foreach ($classifications as $classification) {
            $sheet->setCellValue("A{$row}", $classification->curie);
            $sheet->setCellValue("B{$row}", $classification->name);
            $row++;
        }
    }

    /**
     * Populate the "HELP - MOI" sheet with current mode of inheritance data.
     */
    public static function populateMoiSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('HELP - MOI');

        if ($sheet === null) {
            Log::warning("Template sheet 'HELP - MOI' not found, skipping");

            return;
        }

        self::clearDataRows($sheet, 4);

        $inheritances = Inheritance::where('status', Inheritance::STATUS_ACTIVE)
            ->orderBy('curie')
            ->get(['curie', 'name']);

        $row = 4;
        foreach ($inheritances as $inheritance) {
            $sheet->setCellValue("A{$row}", $inheritance->curie);
            $sheet->setCellValue("B{$row}", $inheritance->name);
            $row++;
        }
    }

    /**
     * Clear data rows from a sheet, preserving header rows.
     */
    private static function clearDataRows($sheet, int $firstDataRow): void
    {
        $highestRow = $sheet->getHighestRow();
        for ($row = $firstDataRow; $row <= $highestRow; $row++) {
            $sheet->removeRow($firstDataRow);
        }
    }
}
