<?php

declare(strict_types=1);

/**
 * Phase D regression test — proves that the generator honours the new
 * designer_config flags for:
 *
 *   - list style:   {{skillslist}} can be rendered either as a bullet (UL)
 *                   or ordered (OL) paragraph by flipping list_style
 *   - list orphan:  prevent_orphans adds <w:keepNext/> to every item
 *                   except the last one
 *   - table layout: prevent_row_break adds <w:cantSplit/> to every <w:tr>,
 *                   repeat_header adds <w:tblHeader/> to the first row
 *
 * The test drives the controller's private helpers directly via reflection
 * so it doesn't need an HTTP stack or a real candidate record.
 *
 * Run:
 *   docker compose exec backend php /plugins/templatex/tests/phase-d-layout.php
 *
 * Exit code: 0 on pass, 1 on any assertion failure.
 */

require '/var/www/backend/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

// ------------------------------------------------------------------
// 1. Build a fixture DOCX with:
//    - an OL numbering (decimal) and a UL numbering (bullet)
//    - a placeholder `{{items}}` inside an OL paragraph
//    - a 3-row stations table with {{stations.time.N}} / {{stations.employer.N}}
// ------------------------------------------------------------------
$phpWord = new PhpWord();

// Inject both a bullet and a decimal numbering style.
$phpWord->addNumberingStyle('myBullet', [
    'type' => 'multilevel',
    'levels' => [[
        'format' => 'bullet',
        'text' => '•',
        'alignment' => 'left',
        'left' => 360,
        'hanging' => 360,
    ]],
]);
$phpWord->addNumberingStyle('myDecimal', [
    'type' => 'multilevel',
    'levels' => [[
        'format' => 'decimal',
        'text' => '%1.',
        'alignment' => 'left',
        'left' => 360,
        'hanging' => 360,
    ]],
]);

$s = $phpWord->addSection();
$s->addListItem('{{items}}', 0, null, 'myBullet');

$t = $s->addTable();
$t->addRow();
$t->addCell(2500)->addText('Time');
$t->addCell(4000)->addText('Employer');
$t->addRow();
$t->addCell(2500)->addText('{{stations.time.N}}');
$t->addCell(4000)->addText('{{stations.employer.N}}');

$fixture = '/tmp/test_phase_d.docx';
IOFactory::createWriter($phpWord, 'Word2007')->save($fixture);

// ------------------------------------------------------------------
// 2. Copy the fixture twice so we can test UL vs OL in parallel.
// ------------------------------------------------------------------
$ulPath = '/tmp/test_phase_d_ul.docx';
$olPath = '/tmp/test_phase_d_ol.docx';
copy($fixture, $ulPath);
copy($fixture, $olPath);

// ------------------------------------------------------------------
// 3. Drive the controller's private helpers via reflection.
// ------------------------------------------------------------------
$controllerRef = new ReflectionClass(\Plugin\TemplateX\Controller\TemplateXController::class);

/** Instantiate without invoking the ctor (we only need instance methods). */
$controller = $controllerRef->newInstanceWithoutConstructor();

/** Disable the logger field via reflection — the helpers call $this->logger. */
$logProp = $controllerRef->getProperty('logger');
$logProp->setAccessible(true);
$logProp->setValue($controller, new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void {}
});

function callPriv($controller, string $method, array $args)
{
    $ref = new ReflectionMethod(\Plugin\TemplateX\Controller\TemplateXController::class, $method);
    $ref->setAccessible(true);
    return $ref->invoke($controller, ...$args);
}

// ------------------------------------------------------------------
// 4. UL path: default designer (list_style=ul) should keep bullet numPr.
// ------------------------------------------------------------------
$items = ['Alpha', 'Beta', 'Gamma'];
$designerUl = [
    'items' => ['_type' => 'list', 'list_style' => 'ul', 'prevent_orphans' => true],
];
$expandedUl = callPriv($controller, 'expandListParagraphs', [
    $ulPath,
    ['items'],
    ['items' => $items],
    ['items' => $items],
    $designerUl,
]);

$zip = new ZipArchive();
$zip->open($ulPath);
$ulXml = $zip->getFromName('word/document.xml');
$zip->close();

$fails = [];

// After expansion, 3 paragraphs with bullet numPr remain.
$paraWithNumPr = preg_match_all('#<w:p\b[^>]*>.*?<w:numPr\b.*?</w:p>#s', $ulXml);
if ($paraWithNumPr < 3) {
    $fails[] = "UL: expected ≥3 paragraphs with numPr, got $paraWithNumPr";
}
// First two items should have <w:keepNext/> (orphan prevention).
$keepNextCount = preg_match_all('#<w:p\b[^>]*>(?:(?!</w:p>).)*?<w:keepNext\s*/>(?:(?!</w:p>).)*?(?:Alpha|Beta|Gamma)(?:(?!</w:p>).)*?</w:p>#s', $ulXml);
if ($keepNextCount !== 2) {
    $fails[] = "UL: expected 2 keepNext-decorated items, got $keepNextCount";
}
// "items" placeholder should be gone.
if (str_contains($ulXml, '{{items}}')) {
    $fails[] = 'UL: {{items}} placeholder was not substituted';
}
// The item text must appear.
foreach ($items as $it) {
    if (!str_contains($ulXml, $it)) {
        $fails[] = "UL: item '$it' not present in output";
    }
}

// ------------------------------------------------------------------
// 5. OL path: designer list_style=ol flips numPr to the decimal numId.
// ------------------------------------------------------------------
$designerOl = [
    'items' => ['_type' => 'list', 'list_style' => 'ol'],
];
$expandedOl = callPriv($controller, 'expandListParagraphs', [
    $olPath,
    ['items'],
    ['items' => $items],
    ['items' => $items],
    $designerOl,
]);

$zip = new ZipArchive();
$zip->open($olPath);
$olXml = $zip->getFromName('word/document.xml');
$numberingXml = $zip->getFromName('word/numbering.xml');
$zip->close();

// Detect what ordered numId the controller should have targeted.
$orderedNumId = callPriv($controller, 'detectOrderedNumId', [(string) $numberingXml]);
if ($orderedNumId === null) {
    $fails[] = 'OL: detectOrderedNumId returned null (no decimal numbering found)';
} else {
    // At least 3 paragraphs should carry numId=$orderedNumId.
    $pattern = '#<w:numId w:val="' . $orderedNumId . '"\s*/>#';
    $count = preg_match_all($pattern, $olXml);
    if ($count < 3) {
        $fails[] = "OL: expected ≥3 paragraphs with numId=$orderedNumId, got $count";
    }
}
// Items must be present.
foreach ($items as $it) {
    if (!str_contains($olXml, $it)) {
        $fails[] = "OL: item '$it' not present in output";
    }
}

// ------------------------------------------------------------------
// 6. Table layout helpers via applyTableLayoutHelpers().
// ------------------------------------------------------------------
// Build a cleanly-generated docx with stations already filled (otherwise the
// helper's 2-row-guard fires). We skip the generator and test the helper
// directly on a hand-crafted table with 3 body rows + 1 header.
$layoutPath = '/tmp/test_phase_d_layout.docx';
$lw = new PhpWord();
$ls = $lw->addSection();
$lt = $ls->addTable();
foreach ([['Time', 'Employer'], ['2024-present', 'Acme'], ['2021-2024', 'Globex'], ['2018-2021', 'Initech']] as $row) {
    $lt->addRow();
    $lt->addCell(2500)->addText($row[0]);
    $lt->addCell(4000)->addText($row[1]);
}
IOFactory::createWriter($lw, 'Word2007')->save($layoutPath);

// designerMap + arrays inputs: `stations` is a table-like array of 3 rows.
$stations = [
    ['time' => '2024-present', 'employer' => 'Acme'],
    ['time' => '2021-2024', 'employer' => 'Globex'],
    ['time' => '2018-2021', 'employer' => 'Initech'],
];
$designerMap = [
    'stations' => [
        '_type' => 'table',
        'repeat_header' => true,
        'prevent_row_break' => true,
    ],
];
callPriv($controller, 'applyTableLayoutHelpers', [
    $layoutPath,
    ['stations' => $stations],
    $designerMap,
]);

$zip = new ZipArchive();
$zip->open($layoutPath);
$layXml = $zip->getFromName('word/document.xml');
$zip->close();

// Every <w:tr> should now have <w:cantSplit/> in its trPr.
$allTrs = [];
preg_match_all('#<w:tr\b[^>]*>(?:(?!</w:tr>).)*?</w:tr>#s', $layXml, $allTrs);
$trs = $allTrs[0];
$missingCantSplit = 0;
foreach ($trs as $tr) {
    if (!str_contains($tr, '<w:cantSplit/>')) {
        $missingCantSplit++;
    }
}
if ($missingCantSplit > 0) {
    $fails[] = "LAYOUT: $missingCantSplit / " . count($trs) . ' <w:tr> missing <w:cantSplit/>';
}
// Exactly one <w:tblHeader/> (first row).
$tblHeaderCount = substr_count($layXml, '<w:tblHeader/>');
if ($tblHeaderCount !== 1) {
    $fails[] = "LAYOUT: expected exactly 1 <w:tblHeader/>, got $tblHeaderCount";
}

// ------------------------------------------------------------------
// 7. Summary / result.
// ------------------------------------------------------------------
printf("  UL expand keys: %s\n", implode(', ', $expandedUl));
printf("  OL expand keys: %s\n", implode(', ', $expandedOl));
printf("  OL ordered numId: %s\n", $orderedNumId ?? 'null');
printf("  Layout <w:tr>: %d (cantSplit missing=%d), tblHeader=%d\n", count($trs), $missingCantSplit, $tblHeaderCount);

if (!empty($fails)) {
    echo "\nFAIL\n";
    foreach ($fails as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "\nPASS — phase D (list UL/OL + table layout helpers) works.\n";
exit(0);
