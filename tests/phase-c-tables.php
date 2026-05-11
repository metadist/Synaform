<?php

declare(strict_types=1);

/**
 * Phase C regression test — proves that a target template with real
 * Word tables (<w:tbl>) gets filled correctly by Synaform:
 *
 *   1. A header+template-row table with N-suffix placeholders like
 *      {{stations.time.N}} is multiplied by PhpWord's cloneRow() into
 *      one rendered row per input record, with each cell correctly
 *      filled (Zeitraum / Unternehmen / Position).
 *
 *   2. A scalar-in-cell table (plain {{noticeperiod}} etc. inside
 *      <w:tc>) is filled directly by setValue() — no row multiplication
 *      needed, but the cells must end up with the expected text.
 *
 * This is the exact algorithm used by SynaformController::processRowGroups()
 * (see synaform-plugin/backend/Controller/SynaformController.php, the
 * `cloneRow($anchorField, $count)` path). We drive PhpWord directly here
 * so the test has no Symfony / HTTP / DB dependencies.
 *
 * Runs inside the Synaplan backend container (uses its bundled PhpWord):
 *
 *   docker compose exec backend php /plugins/synaform/tests/phase-c-tables.php
 *
 * or, from the repo, via the Makefile target (see below).
 *
 * Exit code: 0 on pass, 1 on any assertion failure.
 */

require '/var/www/backend/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

// -------------------------------------------------------------------
// 0. Regenerate the fixture every run so the test is fully hermetic.
// -------------------------------------------------------------------
$fixtureBuilder = __DIR__ . '/fixtures/create_table_template.php';
if (!is_file($fixtureBuilder)) {
    fwrite(STDERR, "FAIL: fixture builder missing: $fixtureBuilder\n");
    exit(1);
}
passthru('php ' . escapeshellarg($fixtureBuilder), $rc);
if ($rc !== 0) {
    fwrite(STDERR, "FAIL: fixture builder exited with $rc\n");
    exit(1);
}

$fixture = '/tmp/test_table_template.docx';
if (!is_file($fixture)) {
    fwrite(STDERR, "FAIL: fixture not produced at $fixture\n");
    exit(1);
}

$out = '/tmp/test_table_template_filled.docx';
copy($fixture, $out);

// -------------------------------------------------------------------
// 1. Synthetic test data. No customer data — just enough distinct
//    strings per row/cell that we can assert positional correctness.
// -------------------------------------------------------------------
$stations = [
    ['time' => '04/2024 – heute',     'employer' => 'Acme Industries GmbH', 'positions' => 'Senior Director Alpha'],
    ['time' => '02/2021 – 04/2024',   'employer' => 'Globex Retail AG',     'positions' => 'Team Lead Beta'],
    ['time' => '08/2018 – 01/2021',   'employer' => 'Initech Europe SE',    'positions' => 'Manager Gamma'],
];

$scalars = [
    'fullname'         => 'Jane Roe',
    'noticeperiod'     => '3 Monate',
    'currentansalary'  => '120.000 EUR',
    'expectedansalary' => '140.000 EUR',
];

// -------------------------------------------------------------------
// 2. Drive the real TemplateProcessor — exactly what the controller
//    does in processRowGroups() and fillScalars().
// -------------------------------------------------------------------
$tp = new TemplateProcessor($out);
// Synaform uses {{…}} placeholder syntax, not PhpWord's default ${…}.
// See synaform-plugin/backend/Controller/SynaformController.php where
// the same two calls follow `new TemplateProcessor(...)`.
$tp->setMacroOpeningChars('{{');
$tp->setMacroClosingChars('}}');

// 2a. scalars (including fullname in the document title).
foreach ($scalars as $k => $v) {
    $tp->setValue($k, htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
}

// 2b. row clone: anchor on the first N-suffix field of the group,
//     then fill each {{name.suffix#i}} for i = 1..count.
$anchor = 'stations.time.N';
$count  = count($stations);
$tp->cloneRow($anchor, $count);

$suffixes = ['time.N', 'employer.N', 'positions.N'];
foreach ($stations as $i => $row) {
    $num = $i + 1;
    foreach ($suffixes as $suffix) {
        $cleanSuffix = str_replace('.N', '', $suffix);
        $value = htmlspecialchars($row[$cleanSuffix] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $tp->setValue("stations.{$suffix}#{$num}", $value);
    }
}

$tp->saveAs($out);

// -------------------------------------------------------------------
// 3. Inspect the rendered document.xml.
// -------------------------------------------------------------------
$zip = new ZipArchive();
if ($zip->open($out) !== true) {
    fwrite(STDERR, "FAIL: cannot open rendered DOCX\n");
    exit(1);
}
$xml = $zip->getFromName('word/document.xml');
$zip->close();
if ($xml === false) {
    fwrite(STDERR, "FAIL: cannot read word/document.xml from rendered DOCX\n");
    exit(1);
}

// Also count original rows for a delta assertion.
$origZip = new ZipArchive();
$origZip->open($fixture);
$origXml = $origZip->getFromName('word/document.xml');
$origZip->close();

// Count </w:tr> (end tags) rather than <w:tr — the latter also matches <w:trPr>
// which appears inside rows for styling, so it inflates the count spuriously.
$origRows = substr_count($origXml, '</w:tr>');
$origTbls = substr_count($origXml, '<w:tbl>');
$newRows  = substr_count($xml, '</w:tr>');
$newTbls  = substr_count($xml, '<w:tbl>');

printf("  <w:tbl> count: %d (was %d)\n", $newTbls, $origTbls);
printf("  <w:tr>  count: %d (was %d, delta=%d)\n", $newRows, $origRows, $newRows - $origRows);

// -------------------------------------------------------------------
// 4. Assertions.
// -------------------------------------------------------------------
$fails = [];

// --- 4a. Both tables must still be present.
if ($newTbls !== $origTbls || $newTbls < 2) {
    $fails[] = "expected at least 2 <w:tbl> in output (orig=$origTbls, now=$newTbls)";
}

// --- 4b. The stations table must have gained (count-1) rows exactly:
//         original rows = header + 1 template row + 3 rows in the conditions
//         table. cloneRow(3) multiplies the template row into 3, so we
//         expect a delta of (3 - 1) = 2.
$expectedDelta = $count - 1;
if (($newRows - $origRows) !== $expectedDelta) {
    $fails[] = sprintf(
        "expected <w:tr> delta=%d after cloneRow(%d), got %d",
        $expectedDelta,
        $count,
        $newRows - $origRows
    );
}

// --- 4c. No raw placeholders left behind in the output.
$leftovers = [];
foreach (['{{stations.time.N}}', '{{stations.employer.N}}', '{{stations.positions.N}}',
          '{{fullname}}', '{{noticeperiod}}', '{{currentansalary}}', '{{expectedansalary}}'] as $ph) {
    if (str_contains($xml, $ph)) {
        $leftovers[] = $ph;
    }
}
if (!empty($leftovers)) {
    $fails[] = 'unexpanded placeholders: ' . implode(', ', $leftovers);
}

// --- 4d. Every station value must appear in the rendered XML and be
//         contained inside a <w:tc> (table cell).
$allInCells = true;
foreach ($stations as $i => $row) {
    foreach (['time', 'employer', 'positions'] as $col) {
        $needle = htmlspecialchars($row[$col], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        if (!str_contains($xml, $needle)) {
            $fails[] = "row " . ($i + 1) . " col '$col' value not found: '{$row[$col]}'";
            $allInCells = false;
            continue;
        }
        $pattern = '#<w:tc\b[^>]*>(?:(?!</w:tc>).)*?' . preg_quote($needle, '#') . '(?:(?!</w:tc>).)*?</w:tc>#s';
        if (preg_match($pattern, $xml) !== 1) {
            $fails[] = "row " . ($i + 1) . " col '$col' value present but not inside a <w:tc>";
            $allInCells = false;
        }
    }
}

// --- 4e. Scalars in the scalar-in-cell table must also be inside <w:tc>.
foreach (['noticeperiod', 'currentansalary', 'expectedansalary'] as $scalar) {
    $needle = htmlspecialchars($scalars[$scalar], ENT_XML1 | ENT_QUOTES, 'UTF-8');
    if (!str_contains($xml, $needle)) {
        $fails[] = "scalar '$scalar' value not found: '{$scalars[$scalar]}'";
        continue;
    }
    $pattern = '#<w:tc\b[^>]*>(?:(?!</w:tc>).)*?' . preg_quote($needle, '#') . '(?:(?!</w:tc>).)*?</w:tc>#s';
    if (preg_match($pattern, $xml) !== 1) {
        $fails[] = "scalar '$scalar' present but not inside a <w:tc>";
    }
}

// --- 4f. Positional: station #1 time/employer/positions must appear in
//         the SAME <w:tr>. This is what proves cloneRow kept the columns
//         aligned per row rather than smashing them together.
if ($allInCells) {
    foreach ($stations as $i => $row) {
        $t = htmlspecialchars($row['time'],      ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $e = htmlspecialchars($row['employer'],  ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $p = htmlspecialchars($row['positions'], ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $rowPattern = '#<w:tr\b[^>]*>(?:(?!</w:tr>).)*?'
            . preg_quote($t, '#')
            . '(?:(?!</w:tr>).)*?'
            . preg_quote($e, '#')
            . '(?:(?!</w:tr>).)*?'
            . preg_quote($p, '#')
            . '(?:(?!</w:tr>).)*?</w:tr>#s';
        if (preg_match($rowPattern, $xml) !== 1) {
            $fails[] = "row " . ($i + 1) . ": time/employer/positions not co-located in the same <w:tr>";
        }
    }
}

// -------------------------------------------------------------------
// 5. Per-station summary, so the output reads like the other phases.
// -------------------------------------------------------------------
foreach ($stations as $i => $row) {
    printf(
        "  row %d: time='%s' | employer='%s' | positions='%s'\n",
        $i + 1,
        $row['time'],
        $row['employer'],
        $row['positions']
    );
}
printf("\n  output: %s\n", $out);
printf("  size:   %d bytes\n", filesize($out));

if (!empty($fails)) {
    echo "\nFAIL\n";
    foreach ($fails as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "\nPASS — phase C table generation works on the synthetic fixture.\n";
exit(0);
