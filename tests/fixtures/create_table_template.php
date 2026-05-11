<?php

declare(strict_types=1);

/**
 * Generates a synthetic DOCX fixture whose core structure is a real Word
 * TABLE (<w:tbl>) containing one data row with N-suffix placeholders —
 * exactly the layout PhpWord's TemplateProcessor::cloneRow() is designed
 * to multiply. Used by `tests/phase-c-tables.php` to prove that Synaform
 * can fill a target template with tables.
 *
 *   ┌──────────────┬──────────────────────────┬──────────────────────────┐
 *   │ Zeitraum     │ Unternehmen              │ Position                 │   ← header row
 *   ├──────────────┼──────────────────────────┼──────────────────────────┤
 *   │ {{stations.  │ {{stations.employer.N}}  │ {{stations.positions.N}} │   ← template row
 *   │   time.N}}   │                          │                          │
 *   └──────────────┴──────────────────────────┴──────────────────────────┘
 *
 * Runs inside the Synaplan backend container (uses its bundled PhpWord).
 * Output: /tmp/test_table_template.docx
 */
require '/var/www/backend/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

$word = new PhpWord();
$word->setDefaultFontName('Arial');
$word->setDefaultFontSize(10);

$section = $word->addSection([
    'marginTop'    => 1000,
    'marginBottom' => 1000,
    'marginLeft'   => 1200,
    'marginRight'  => 1200,
]);

$titleStyle    = ['bold' => true, 'size' => 16, 'color' => '003366'];
$heading2Style = ['bold' => true, 'size' => 12, 'color' => '003366'];
$labelStyle    = ['bold' => true, 'size' => 10];
$valueStyle    = ['size' => 10];

$section->addText('TABLE FIXTURE — {{fullname}}', $titleStyle, ['alignment' => 'center']);
$section->addTextBreak();

// -----------------------------------------------------------------------
// Test 1: career stations table (cloneRow target — one data row with
//         N-suffix placeholders that must multiply into 3 rows).
// -----------------------------------------------------------------------
$section->addText('BERUFLICHE STATIONEN (cloneRow test)', $heading2Style);

$stTable = $section->addTable([
    'borderSize'  => 6,
    'borderColor' => '333333',
    'cellMargin'  => 60,
]);

$stTable->addRow(400, ['tblHeader' => true]);
$stTable->addCell(2500)->addText('Zeitraum', $labelStyle);
$stTable->addCell(3500)->addText('Unternehmen', $labelStyle);
$stTable->addCell(3500)->addText('Position', $labelStyle);

$stTable->addRow();
$stTable->addCell(2500)->addText('{{stations.time.N}}', $valueStyle);
$stTable->addCell(3500)->addText('{{stations.employer.N}}', $valueStyle);
$stTable->addCell(3500)->addText('{{stations.positions.N}}', $valueStyle);

$section->addTextBreak();

// -----------------------------------------------------------------------
// Test 2: a second table with plain (non-N) scalar placeholders in cells
//         — proves that the engine also handles the simpler “scalar in a
//         table cell” case, not only the row-clone case.
// -----------------------------------------------------------------------
$section->addText('KONDITIONEN (scalar-in-cell test)', $heading2Style);

$condTable = $section->addTable([
    'borderSize'  => 6,
    'borderColor' => '333333',
    'cellMargin'  => 60,
]);

$condRows = [
    ['Kündigungsfrist',      '{{noticeperiod}}'],
    ['Aktuelles Gehalt',     '{{currentansalary}}'],
    ['Gehaltsvorstellung',   '{{expectedansalary}}'],
];
foreach ($condRows as $row) {
    $condTable->addRow();
    $condTable->addCell(3500)->addText($row[0], $labelStyle);
    $condTable->addCell(5000)->addText($row[1], $valueStyle);
}

$writer = IOFactory::createWriter($word, 'Word2007');
$out = '/tmp/test_table_template.docx';
$writer->save($out);

echo "Fixture written: $out\n";
echo "  size: " . filesize($out) . " bytes\n";
