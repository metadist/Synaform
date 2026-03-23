<?php
require '/var/www/backend/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

$word = new PhpWord();
$word->setDefaultFontName('Arial');
$word->setDefaultFontSize(10);

$section = $word->addSection(['marginTop' => 1000, 'marginBottom' => 1000, 'marginLeft' => 1200, 'marginRight' => 1200]);

$titleStyle = ['bold' => true, 'size' => 16, 'color' => '003366'];
$heading2Style = ['bold' => true, 'size' => 12, 'color' => '003366'];
$labelStyle = ['bold' => true, 'size' => 10];
$valueStyle = ['size' => 10];
$smallStyle = ['size' => 9, 'color' => '666666'];

$section->addText('KANDIDATENPROFIL', $titleStyle, ['alignment' => 'center', 'spaceAfter' => 200]);
$section->addTextBreak();

$section->addText('{{fullname}}', ['bold' => true, 'size' => 14], ['alignment' => 'center']);
$section->addText('fuer die Position: {{target-position}}', $smallStyle, ['alignment' => 'center', 'spaceAfter' => 200]);
$section->addTextBreak();

// Personal Data
$section->addText('PERSOENLICHE DATEN', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);

$table = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);

$rows = [
    ['Adresse:', '{{address1}}, {{zip}} {{address2}}'],
    ['Geburtsdatum:', '{{birthdate}}'],
    ['Nationalitaet:', '{{nationality}}'],
    ['Familienstand:', '{{maritalstatus}}'],
    ['Telefon:', '{{number}}'],
    ['E-Mail:', '{{email}}'],
];

foreach ($rows as $row) {
    $table->addRow();
    $table->addCell(2500)->addText($row[0], $labelStyle);
    $table->addCell(6000)->addText($row[1], $valueStyle);
}

$section->addTextBreak();

// Current Position
$section->addText('AKTUELLE POSITION', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);
$section->addText('{{currentposition}}', $valueStyle);
$section->addTextBreak();

// Education
$section->addText('AUSBILDUNG', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);
$section->addText('{{education}}', $valueStyle);
$section->addTextBreak();

// Career Stations
$section->addText('BERUFLICHE STATIONEN', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);

$stTable = $section->addTable(['borderSize' => 1, 'borderColor' => 'CCCCCC', 'cellMargin' => 60]);
$stTable->addRow();
$stTable->addCell(2500)->addText('Zeitraum', $labelStyle);
$stTable->addCell(2500)->addText('Unternehmen', $labelStyle);
$stTable->addCell(4500)->addText('Details', $labelStyle);

$stTable->addRow();
$stTable->addCell(2500)->addText('{{stations.time.N}}', $valueStyle);
$stTable->addCell(2500)->addText('{{stations.employer.N}}', $valueStyle);
$stTable->addCell(4500)->addText('{{stations.details.N}}', $valueStyle);

$section->addTextBreak();

// Relevant positions
$section->addText('RELEVANTE VORHERIGE POSITIONEN', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);
$section->addText('{{relevantposlist}}', $valueStyle);
$section->addTextBreak();

// Relevant for target position
$section->addText('RELEVANTE ERFAHRUNG FUER DIE POSITION', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);
$section->addText('{{relevantfortargetposlist}}', $valueStyle);
$section->addTextBreak();

// Languages
$section->addText('SPRACHEN', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);
$section->addText('{{languageslist}}', $valueStyle);
$section->addTextBreak();

// Other Skills
$section->addText('SONSTIGE KENNTNISSE', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);
$section->addText('{{otherskillslist}}', $valueStyle);
$section->addTextBreak();

// Conditions
$section->addText('KONDITIONEN', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);

$condTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);
$condRows = [
    ['Kuendigungsfrist:', '{{noticeperiod}}'],
    ['Aktuelles Gehalt:', '{{currentansalary}}'],
    ['Gehaltsvorstellung:', '{{expectedansalary}}'],
    ['Arbeitszeit:', '{{workinghours}}'],
];
foreach ($condRows as $row) {
    $condTable->addRow();
    $condTable->addCell(3000)->addText($row[0], $labelStyle);
    $condTable->addCell(5500)->addText($row[1], $valueStyle);
}

$section->addTextBreak();

// Checkboxes
$section->addText('BEREITSCHAFTEN', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);

$cbTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);

$cbTable->addRow();
$cbTable->addCell(3000)->addText('Umzugsbereitschaft:', $labelStyle);
$cbTable->addCell(2000)->addText('Ja {{checkb.moving.yes}}', $valueStyle);
$cbTable->addCell(2000)->addText('Nein {{checkb.moving.no}}', $valueStyle);

$cbTable->addRow();
$cbTable->addCell(3000)->addText('Pendelbereitschaft:', $labelStyle);
$cbTable->addCell(2000)->addText('Ja {{checkb.commute.yes}}', $valueStyle);
$cbTable->addCell(2000)->addText('Nein {{checkb.commute.no}}', $valueStyle);

$cbTable->addRow();
$cbTable->addCell(3000)->addText('Reisebereitschaft:', $labelStyle);
$cbTable->addCell(2000)->addText('Ja {{checkb.travel.yes}}', $valueStyle);
$cbTable->addCell(2000)->addText('Nein {{checkb.travel.no}}', $valueStyle);

$section->addTextBreak();

// Benefits
$section->addText('SONSTIGE LEISTUNGEN', $heading2Style);
$section->addLine(['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '003366']);
$section->addText('{{benefits}}', $valueStyle);

$writer = IOFactory::createWriter($word, 'Word2007');
$writer->save('/tmp/test_template.docx');
echo "Template created at /tmp/test_template.docx\n";
