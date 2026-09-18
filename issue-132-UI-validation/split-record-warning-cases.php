<?php

// Split the workbook into the two upload paths supported by the application.
require dirname(__DIR__).'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$sourcePath = $argv[1] ?? __DIR__.'/ambry-record-warning-cases.xlsx';
$recordsPath = $argv[2] ?? __DIR__.'/ambry-record-warning-cases-records.xlsx';
$blockedPath = $argv[3] ?? __DIR__.'/ambry-record-warning-cases-upload-blocked.xlsx';

$source = IOFactory::load($sourcePath);
$records = $source->getSheetByName('Record cases');
$fixtures = $source->getSheetByName('Fixture cases');
$blocked = clone $source->getSheetByName('Upload-blocked cases');

// The records workbook has one worksheet because the importer reads only the
// first worksheet. Append fixture-dependent record cases after current-data
// cases so all uploadable scenarios are in one uploadable file.
$appendAt = $records->getHighestDataRow() + 1;
for ($sourceRow = 13; $sourceRow <= $fixtures->getHighestDataRow(); $sourceRow++, $appendAt++) {
    for ($column = 1; $column <= 18; $column++) {
        $from = $fixtures->getCell([$column, $sourceRow]);
        $to = $records->getCell([$column, $appendAt]);
        $to->setValue($from->getValue());
        $records->getStyle($to->getCoordinate())->applyFromArray($from->getStyle()->exportArray());
        $fromComment = $fixtures->getComment($from->getCoordinate());
        if ($fromComment->getText()->getPlainText() !== '') {
            $records->getComment($to->getCoordinate())->getText()->createTextRun($fromComment->getText()->getPlainText());
        }
    }
    $records->getRowDimension($appendAt)->setRowHeight($fixtures->getRowDimension($sourceRow)->getRowHeight());
}
$records->setTitle('Submission records');
$records->setCellValue('A1', 'GenCC v2 — UI TEST ONLY — UPLOADABLE RECORD CASES — DO NOT PUBLISH');
$records->setCellValue('A2', 'This is the only worksheet. Upload this file to exercise record-level errors and warnings.');
$records->setCellValue('A3', '14 submission rows: 8 current-data cases and 6 fixture-dependent cases.');
$records->setAutoFilter('A6:R'.($appendAt - 1));

$blocked->setTitle('Upload-blocked cases');
$blocked->setCellValue('A1', 'GenCC v2 — UI TEST ONLY — UPLOAD-BLOCKED CASES — DO NOT PUBLISH');
$blocked->setCellValue('A2', 'This is the only worksheet. Upload this file to exercise errors that prevent record creation.');
$blocked->setCellValue('A3', '6 submission rows. The application should reject these before creating individual records.');

$recordsBook = $source;
$recordsBook->getSheetByName('Submission records')->setTitle('Submission records');
for ($index = $recordsBook->getSheetCount() - 1; $index >= 0; $index--) {
    if ($recordsBook->getSheet($index)->getTitle() !== 'Submission records') $recordsBook->removeSheetByIndex($index);
}
$recordsBook->setActiveSheetIndex(0);
IOFactory::createWriter($recordsBook, 'Xlsx')->save($recordsPath);

$blockedBook = IOFactory::load($sourcePath);
$blockedBook->getSheetByName('Upload-blocked cases')->setTitle('Upload-blocked cases');
for ($index = $blockedBook->getSheetCount() - 1; $index >= 0; $index--) {
    if ($blockedBook->getSheet($index)->getTitle() !== 'Upload-blocked cases') $blockedBook->removeSheetByIndex($index);
}
$blockedBook->setActiveSheetIndex(0);
IOFactory::createWriter($blockedBook, 'Xlsx')->save($blockedPath);

echo json_encode([
    'records' => [$recordsPath, $records->getHighestDataRow()],
    'upload_blocked' => [$blockedPath, $blocked->getHighestDataRow()],
])."\n";
