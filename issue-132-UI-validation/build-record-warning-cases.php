<?php

// Generate the workbook artifact only. Never connect to or modify a database.
require dirname(__DIR__).'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$output = $argv[1] ?? null;
if (!$output || !str_ends_with($output, '.xlsx')) {
    throw new RuntimeException('Usage: php build-record-warning-cases.php OUTPUT.xlsx');
}
$base = [
    'sgc_id' => '', 'action' => 'N', 'local_key' => '',
    'hgnc_id' => 'HGNC:5', 'hgnc_symbol' => 'A1BG',
    'disease_id' => 'MONDO:0007947', 'disease_name' => 'Marfan syndrome',
    'moi_id' => 'HP:0000006', 'moi_name' => 'Autosomal dominant',
    'submitter_id' => 'GENCC:000101', 'submitter_name' => 'Ambry Genetics',
    'classification_id' => 'GENCC:100009', 'classification_name' => 'Supportive',
    'date' => '2024-01-15', 'public_report_url' => '', 'notes' => '', 'pmids' => '',
    'assertion_criteria_url' => 'https://www.orpha.net/orphacom/cahiers/docs/GB/Orphanet_Genes_inventory_R1_Ann_gen_EP_02.pdf',
];
$cases = [
    ['Record cases', 'R01-PRIORITY-CONTROL', ['disease_id'=>'Orphanet:1941','disease_name'=>'Juvenile absence epilepsy'],
        'CONTROL: resolves to MONDO:0800453 via MONDO assertion. Lower-priority alternatives do not cause an error.', 'Current imported sources.'],
    ['Record cases', 'R02-ORPHA-NO-MONDO', ['disease_id'=>'Orphanet:716903','disease_name'=>'Autosomal recessive congenital myasthenic syndrome due to defective synaptic vesicles exocytosis','moi_id'=>'HP:0000007','moi_name'=>'Autosomal recessive'],
        'RECORD ERROR: known Orphanet term with no exact MONDO mapping; submitted ID remains visible.', 'Current imported sources.'],
    ['Record cases', 'R03-UNKNOWN-DISEASE', ['disease_id'=>'Orphanet:999999999','disease_name'=>'Deliberately unknown test ID'],
        'RECORD ERROR: unknown disease ID, no resolved disease references.', 'Confirm this deliberately unknown identifier is absent.'],
    ['Record cases', 'R04-REPORT-URL', ['public_report_url'=>'https://'],
        'RECORD ERROR: invalid report URL. Current spreadsheet prefix check accepts https://, but record URL validation rejects it.', 'No special fixture.'],
    ['Record cases', 'R05-DEPRECATED-SUCCESSOR', ['disease_id'=>'MONDO:0015872','disease_name'=>'giant adenofibroma of the breast'],
        'RECORD WARNING: deprecated term, replacement MONDO:0004150 exists; remains publishable.', 'Current imported sources.'],
    ['Record cases', 'R06-DEPRECATED-NO-SUCCESSOR', ['disease_id'=>'MONDO:0011876','disease_name'=>'juvenile absence epilepsy'],
        'RECORD WARNING: deprecated term; source does not name a replacement; remains publishable.', 'Current imported sources.'],
    ['Record cases', 'R07-OMIM-NO-MONDO', ['disease_id'=>'OMIM:100675','disease_name'=>'ACETAMINOPHEN METABOLISM'],
        'RECORD ERROR: known OMIM identifier without an exact MONDO mapping.', 'Current imported sources.'],
    ['Record cases', 'R08-PMID-SIX-REASONS', ['disease_id'=>'MONDO:0004150','disease_name'=>'breast giant fibroadenoma','pmids'=>'PMID:31566583; 00031566583; NULL; 1.2E+7; abc; 0; 123456789'],
        'UPLOAD WARNING: six PMID cleanup reasons; valid 31566583 retained. Raw API input produces persistent pmid_issues; current spreadsheet preprocessing discards those issues.', 'See Coverage and record-warning-cases.md for this current limitation.'],
    ['Fixture cases', 'F09-MONDO-AMBIGUITY', ['disease_id'=>'OMIM:999901','disease_name'=>'FIXTURE ONLY: two MONDO claimants'],
        'RECORD ERROR: step-1 ambiguity; candidates MONDO:9999001 and MONDO:9999002 [deprecated].', 'Seed both candidates with exact OMIM:999901; see fixture recipe.'],
    ['Fixture cases', 'F10-ORPHA-AMBIGUITY', ['disease_id'=>'Orphanet:999902','disease_name'=>'FIXTURE ONLY: direct ambiguity plus bridge'],
        'RECORD ERROR: step-2 ambiguity; candidates 9999001 and 9999002. Must not fall through to 9999003.', 'Seed exact MONDO pair and OMIM bridge, with no MONDO-side match to this Orphanet ID.'],
    ['Fixture cases', 'F11-BRIDGE-AMBIGUITY', ['disease_id'=>'Orphanet:999903','disease_name'=>'FIXTURE ONLY: ambiguous and unique bridges'],
        'RECORD ERROR: step-3 ambiguity; all three candidates 9999001, 9999002 and 9999003 retained.', 'Seed one ambiguous OMIM reference plus a separate unique bridge; no earlier mapping.'],
    ['Fixture cases', 'F12-ORIGINAL-ABSENT', ['disease_id'=>'OMIM:999904','disease_name'=>'FIXTURE ONLY: original OMIM record absent'],
        'RECORD ERROR: unique mapping to MONDO:9999003 but original record is absent; disease_id resolves and original_disease_id stays null.', 'MONDO:9999003 exact-matches OMIM:999904; deliberately do not create OMIM:999904.'],
    ['Fixture cases', 'F13-SUCCESSOR-ABSENT', ['disease_id'=>'MONDO:9999004','disease_name'=>'FIXTURE ONLY: deprecated with absent successor'],
        'RECORD WARNING: source names replacement MONDO:9999005, but that replacement is absent locally.', 'Deprecated MONDO:9999004 with replaced_by MONDO:9999005; replacement absent.'],
    ['Fixture cases', 'F14-DUPLICATE-STATES', ['moi_id'=>'HP:0000007','moi_name'=>'Autosomal recessive'],
        'RECORD/UPLOAD WARNING with matching live unpublished record. Alternate prerequisite: pending or live published duplicate produces blocking upload/edit error.', 'Ambry + HGNC:5 + original MONDO:0007947 + HP:0000007. Requires matching existing record in the intended state.'],
    ['Upload-blocked cases', 'B15-MISSING-FIELDS', ['hgnc_id'=>'','hgnc_symbol'=>'','disease_id'=>'','disease_name'=>'','moi_id'=>'','moi_name'=>'','classification_id'=>'','classification_name'=>'','date'=>'','assertion_criteria_url'=>''],
        'UPLOAD BLOCKED: missing required fields. Raw API input: missing gene, disease, MOI, classification and report date. Blank UI form additionally initializes criteria_url error.', 'No record is created by normal spreadsheet upload. See guide for blank-form variant.'],
    ['Upload-blocked cases', 'B16-INVALID-FIELDS-PMIDS', ['hgnc_id'=>'HGNC:999999999','hgnc_symbol'=>'Unknown test gene','disease_id'=>'NOT_A_DISEASE','disease_name'=>'Invalid test value','moi_id'=>'HP:9999999','moi_name'=>'Unknown test MOI','classification_id'=>'GENCC:999999','classification_name'=>'Unknown test classification','date'=>'08/26/2024','public_report_url'=>'not-a-url','pmids'=>'NULL; 1.2E+7; abc; 0; 123456789'],
        'UPLOAD BLOCKED: invalid/unknown references, ambiguous date format, invalid URL and no valid PMID. Raw API input exercises corresponding record errors, including invalid_pmid.', 'Combined cases intentionally conserve the 20-row limit.'],
    ['Upload-blocked cases', 'B17-MALFORMED-TIMESTAMP', ['disease_id'=>'MONDO:0004150','disease_name'=>'breast giant fibroadenoma','date'=>'2024-01-15T99:99garbage'],
        'UPLOAD BLOCKED: malformed complete timestamp; valid date prefix must not be accepted.', 'Expected date-format rejection.'],
    ['Upload-blocked cases', 'B18-DATE-TOO-EARLY', ['disease_id'=>'MONDO:0800453','disease_name'=>'juvenile absence epilepsy','date'=>'1969-12-31'],
        'UPLOAD BLOCKED: date before 1970-01-01; details explain allowed range, not merely format.', 'Expected range rejection.'],
    ['Upload-blocked cases', 'B19-DATE-FUTURE', ['disease_id'=>'MONDO:0015872','disease_name'=>'giant adenofibroma of the breast','date'=>'2999-01-01'],
        'UPLOAD BLOCKED: date beyond today plus one-day timezone allowance; details explain allowed range.', 'Expected range rejection.'],
    ['Upload-blocked cases', 'B20-IMPOSSIBLE-DATE', ['disease_id'=>'MONDO:0011876','disease_name'=>'juvenile absence epilepsy','date'=>'2024-02-30'],
        'UPLOAD BLOCKED: impossible calendar date, despite correct YYYY-MM-DD spelling.', 'Expected date-format rejection.'],
];
if (count($cases) > 20) throw new RuntimeException('More than 20 submission rows');

$book = IOFactory::load(__DIR__.'/ambry-record-warning-cases.xlsx');
$template = clone $book->getSheet(0);
while ($book->getSheetCount()) $book->removeSheetByIndex(0);
$groups = ['Record cases'=>'E8F3E8', 'Fixture cases'=>'FFF2CC', 'Upload-blocked cases'=>'FCE4D6'];
foreach ($groups as $title => $color) {
    $sheet = clone $template;
    $sheet->setTitle($title);
    $book->addSheet($sheet);
    if ($sheet->getHighestRow() >= 13) $sheet->removeRow(13, $sheet->getHighestRow()-12);
    foreach (range(8, 11) as $row) foreach (range('A', 'R') as $column) $sheet->setCellValue($column.$row, null);
    $sheet->setCellValue('A1', 'GenCC v2 — UI TEST ONLY — '.$title.' — DO NOT PUBLISH');
    $sheet->setCellValue('A2', 'Only the FIRST worksheet is imported. Other sheets are separate scenarios; see Coverage.');
    $sheet->setCellValue('A3', '20 submission rows total: 8 record cases, 6 fixture-dependent, 6 upload-blocked. No fixtures are installed.');
    $sheet->setCellValue('A4', 'Ambry Genetics / GENCC:000101. Case key and expected result are recorded in local_key and notes.');
    $sheet->setCellValue('A5', 'VERSION2');
    $sheet->fromArray(array_keys($base), null, 'A6');
    $sheet->setCellValue('A12', 'SUBMISSION DATA STARTS ON ROW 13. Synthetic UI testing only.');
    $row = 13;
    foreach ($cases as [$group, $key, $overrides, $expected, $setup]) {
        if ($group !== $title) continue;
        $record = array_replace($base, $overrides, ['local_key'=>$key, 'notes'=>'UI TEST ONLY — DO NOT PUBLISH. '.$expected.' Prerequisite: '.$setup]);
        foreach (array_values($record) as $i=>$value) $sheet->setCellValueExplicit([$i+1,$row], $value, DataType::TYPE_STRING);
        $sheet->getStyle('A'.$row.':R'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
        $sheet->getRowDimension($row)->setRowHeight(72);
        $sheet->getComment('C'.$row)->getText()->createTextRun($expected."\n".$setup);
        $row++;
    }
    $sheet->freezePane('D13');
    $sheet->setSelectedCell('C13');
    $sheet->setAutoFilter('A6:R'.($row-1));
    $sheet->getStyle('A13:R'.($row-1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    foreach (range('A','R') as $column) $sheet->getColumnDimension($column)->setWidth(22);
    foreach (['C'=>32,'G'=>42,'P'=>90,'Q'=>40,'R'=>45] as $column=>$width) $sheet->getColumnDimension($column)->setWidth($width);
    $sheet->getRowDimension(1)->setRowHeight(24);
    foreach ([2,3,4,12] as $r) $sheet->getRowDimension($r)->setRowHeight(20);
}
$guide = new Worksheet($book, 'Coverage');
$book->addSheet($guide);
$guideRows = [
    ['Submission record validation coverage — 20 test rows; local only; do not publish'],
    ['Only the first worksheet is imported. Use a separate copy with another submission sheet first to test it.'],
    ['Fixture cases require the recipe below. No database fixtures are installed by this workbook.'],
    ['PMID limitation: spreadsheet processing cleans PMIDs before record validation; R08 does not currently persist its six issues on the record.'],
    ['Missing fields/dates and invalid PMID-only input block upload. Use equivalent raw API packets in an isolated test environment for record UI cases.'],
    ['Case','Sheet / Excel row','Expected outcome','Prerequisite'],
];
$rowCounters=array_fill_keys(array_keys($groups),13);
foreach ($cases as [$group,$key,$overrides,$expected,$setup]) $guideRows[]=[$key,$group.' / '.$rowCounters[$group]++,$expected,$setup];
$guideRows[]=['Inventory, prerequisites and exclusions (also available in record-warning-cases.md)'];
foreach (explode("\n",file_get_contents(__DIR__.'/record-warning-cases.md')) as $line) {
    if (trim($line)==='' || str_starts_with($line, '| ---')) continue;
    $guideRows[] = str_starts_with($line, '|')
        ? array_map('trim', explode('|', trim($line, '| ')))
        : [ltrim($line, '# ')];
}
$guide->fromArray($guideRows,null,'A1');
foreach (['A'=>48,'B'=>30,'C'=>80,'D'=>80] as $column=>$width) $guide->getColumnDimension($column)->setWidth($width);
$guide->getStyle('A1:D'.$guide->getHighestRow())->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
$guide->getStyle('A6:D6')->getFont()->setBold(true);
foreach ($guideRows as $index => $values) {
    $row = $index + 1;
    if (count($values) === 1) $guide->mergeCells('A'.$row.':D'.$row);
    $guide->getRowDimension($row)->setRowHeight($row>=7 && $row<=26 ? 110 : (count($values) === 1 ? 30 : 75));
}
$guide->freezePane('C7');
$book->setActiveSheetIndex(0);
IOFactory::createWriter($book,'Xlsx')->save($output);
echo json_encode(['output'=>$output,'submission_rows'=>count($cases),'sheets'=>$book->getSheetNames()]),"\n";
