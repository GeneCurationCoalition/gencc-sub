<?php

namespace App\Http\Controllers;

use App\Exports\SubmissionsTemplateExport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateDownloadController extends Controller
{
    /**
     * Download the submission template with dynamically populated submitter IDs.
     */
    public function __invoke(): StreamedResponse
    {
        $templatePath = public_path('documents/GenCC Submission Spreadsheet.xlsx');

        if (! file_exists($templatePath)) {
            abort(404, 'Template file not found');
        }

        $spreadsheet = IOFactory::load($templatePath);

        SubmissionsTemplateExport::populateSubmitterSheet($spreadsheet);

        $filename = 'GenCC Submission Spreadsheet.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}
