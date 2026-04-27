<?php

declare(strict_types=1);

/**
 * Phase T regression test — proves that the new expandTableBlocks()
 * pre-pass correctly renders a `table`-typed variable declared with
 * columns[] by cloning the host row once per data row, and that the
 * column values land in the right cells (left-to-right, matching the
 * order declared in columns[]).
 *
 * Fixture: a one-row table with two cells, the first carrying the
 * shorthand `{{stations}}` placeholder (no ".col.N" suffixes needed).
 * The form field declares columns: [{key:'time'}, {key:'employer'}].
 *
 * Run:
 *   docker compose exec backend php /plugins/templatex/tests/phase-t-tableblock.php
 */

require '/var/www/backend/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

$phpWord = new PhpWord();
$s = $phpWord->addSection();
$tbl = $s->addTable();
$tbl->addRow();
$tbl->addCell(2500)->addText('{{stations}}');
$tbl->addCell(4000)->addText('');
$fixture = '/tmp/test_phase_t.docx';
IOFactory::createWriter($phpWord, 'Word2007')->save($fixture);

$out = '/tmp/test_phase_t_filled.docx';
copy($fixture, $out);

$controllerRef = new ReflectionClass(\Plugin\TemplateX\Controller\TemplateXController::class);
$controller = $controllerRef->newInstanceWithoutConstructor();
$logProp = $controllerRef->getProperty('logger');
$logProp->setAccessible(true);
$logProp->setValue($controller, new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void {}
});

$formFields = [
    [
        'key' => 'stations',
        'type' => 'table',
        'columns' => [
            ['key' => 'time', 'label' => 'Time'],
            ['key' => 'employer', 'label' => 'Employer'],
        ],
    ],
];
$arrays = [
    'stations' => [
        ['time' => '2024-present', 'employer' => 'Acme GmbH'],
        ['time' => '2021-2024', 'employer' => 'Globex AG'],
        ['time' => '2018-2021', 'employer' => 'Initech SE'],
    ],
];

$ref = new ReflectionMethod(\Plugin\TemplateX\Controller\TemplateXController::class, 'expandTableBlocks');
$ref->setAccessible(true);
$handled = $ref->invoke($controller, $out, $formFields, $arrays);

$zip = new ZipArchive();
$zip->open($out);
$xml = $zip->getFromName('word/document.xml');
$zip->close();

$fails = [];
if (!isset($handled['stations'])) {
    $fails[] = 'expandTableBlocks did not report stations as handled';
}
if (str_contains($xml, '{{stations}}')) {
    $fails[] = '{{stations}} placeholder still in XML';
}

// Expect at least 3 <w:tr> (no header row in this fixture, one per data row).
$rows = substr_count($xml, '</w:tr>');
if ($rows < 3) {
    $fails[] = "Expected ≥3 <w:tr> after table-block expansion, got $rows";
}

// Every station value must appear inside the XML.
foreach ($arrays['stations'] as $i => $row) {
    foreach ($row as $col => $val) {
        if (!str_contains($xml, $val)) {
            $fails[] = "row $i col $col: value '$val' not in XML";
        }
    }
}

// Columns should land in the correct cell: each rendered row must have the
// `time` value before the `employer` value inside the SAME <w:tr>.
foreach ($arrays['stations'] as $i => $row) {
    $pattern = '#<w:tr\b[^>]*>(?:(?!</w:tr>).)*?'
        . preg_quote($row['time'], '#')
        . '(?:(?!</w:tr>).)*?'
        . preg_quote($row['employer'], '#')
        . '(?:(?!</w:tr>).)*?</w:tr>#s';
    if (preg_match($pattern, $xml) !== 1) {
        $fails[] = "row $i: columns not co-located or out of order inside the same <w:tr>";
    }
}

printf("  handled keys: %s\n", implode(',', array_keys($handled)));
printf("  <w:tr> count after expansion: %d\n", $rows);

if (!empty($fails)) {
    echo "\nFAIL\n";
    foreach ($fails as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "\nPASS — phase T (table-block expansion via designer columns) works.\n";
exit(0);
