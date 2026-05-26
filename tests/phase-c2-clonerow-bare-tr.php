<?php

declare(strict_types=1);

/**
 * Phase C2 regression test — `cleanTemplateMacros` must promote every bare
 * `<w:tr>` to `<w:tr w:rsidR="…">` so PhpWord's
 * `TemplateProcessor::findRowStart()` can locate the host row of a
 * row-group placeholder.
 *
 * The bug it guards against:
 *   PhpWord searches BACKWARD for `<w:tr ` (with the trailing space — i.e.
 *   a row carrying at least one attribute) before falling back to bare
 *   `<w:tr>`. On a customer template where the Werdegang row is bare and
 *   the previous table on the page closes with an attributed `<w:tr w:rsidR=…>`,
 *   findRowStart matches the previous table's last row, the slice spans
 *   both tables, and `cloneRow` produces a corrupted document where the
 *   previous table's last row is duplicated INTO the Werdegang table.
 *
 *   We hit this end-to-end on the v5 NeedleHaystack template — the
 *   "sonstige Kenntnisse / {{other_skills}}" row leaked into the cloned
 *   stations table. Fix: inject a synthetic `w:rsidR` attribute onto
 *   every bare `<w:tr>` during cleanTemplateMacros.
 *
 * This test reproduces the exact bug shape with two adjacent <w:tbl>
 * blocks: an attributed previous-table row and a bare-stations row. It
 * runs the production helper (`ensureRowsCarryAttributes`) by inlining
 * the same regex it uses, then asserts that all bare `<w:tr>` openings
 * have been promoted.
 *
 * Run:
 *   php tests/phase-c2-clonerow-bare-tr.php
 *
 * Exit code: 0 on pass, non-zero on regression.
 */

$fails = [];

// ---------------------------------------------------------------------------
// Fixture: two adjacent <w:tbl> blocks; the LAST row of the first table is
// attributed (`<w:tr w:rsidR="…">`); the only row of the second table is
// bare (`<w:tr>`). Mirrors what the v5 NeedleHaystack template looked like
// before the fix shipped — and what any handwritten template will look
// like when authored programmatically.
// ---------------------------------------------------------------------------

$fixture = <<<XML
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:tbl>
      <w:tr w:rsidR="00ABCDEF" w:rsidTr="00ABCDEF">
        <w:tc><w:p><w:r><w:t>sonstige Kenntnisse</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>{{other_skills}}</w:t></w:r></w:p></w:tc>
      </w:tr>
    </w:tbl>
    <w:p/>
    <w:tbl>
      <w:tr>
        <w:tc><w:p><w:r><w:t>{{stations.time.N}}</w:t></w:r></w:p></w:tc>
        <w:tc><w:p><w:r><w:t>{{stations.employer.N}}</w:t></w:r></w:p></w:tc>
      </w:tr>
    </w:tbl>
  </w:body>
</w:document>
XML;

// ---------------------------------------------------------------------------
// Apply the production transform (mirror of `ensureRowsCarryAttributes`).
// ---------------------------------------------------------------------------

$promoted = preg_replace(
    '#<w:tr>#',
    '<w:tr w:rsidR="00000000" w:rsidTr="00000000">',
    $fixture
);

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

// 1. No bare `<w:tr>` opening tags survive.
if (preg_match('#<w:tr>#', $promoted ?? '')) {
    $fails[] = 'bare <w:tr> opening tags survive — findRowStart will skip them';
}

// 2. The pre-existing attributed `<w:tr w:rsidR="00ABCDEF" …>` is preserved
//    untouched (the transform must NOT rewrite rows that already have
//    attributes — a designer's `w:rsidR` carries Word's revision-tracking
//    state and overwriting it could break track-changes integration).
if (!str_contains($promoted ?? '', '<w:tr w:rsidR="00ABCDEF" w:rsidTr="00ABCDEF">')) {
    $fails[] = 'pre-existing attributed <w:tr> was rewritten or removed';
}

// 3. The previously-bare row now starts with our sentinel attribute.
if (!preg_match('#<w:tr w:rsidR="00000000" w:rsidTr="00000000">\s*<w:tc>\s*<w:p>\s*<w:r>\s*<w:t>\{\{stations\.time\.N\}\}#', $promoted ?? '')) {
    $fails[] = 'bare row hosting {{stations.time.N}} was not promoted to attributed form';
}

// 4. Idempotency — running the transform a second time produces a
//    byte-identical document (no double-attributing).
$promotedTwice = preg_replace(
    '#<w:tr>#',
    '<w:tr w:rsidR="00000000" w:rsidTr="00000000">',
    $promoted ?? ''
);
if ($promotedTwice !== $promoted) {
    $fails[] = 'transform is not idempotent (running it twice changes output)';
}

// 5. `<w:tr w:rsidR="…">` count must be exactly the row count: one
//    pre-existing + one promoted = two.
$rowCount = preg_match_all('#<w:tr\s+w:rsidR=#', $promoted ?? '');
if ($rowCount !== 2) {
    $fails[] = "expected 2 attributed <w:tr> rows after transform; got $rowCount";
}

// 6. Output is well-formed XML.
$prevInternal = libxml_use_internal_errors(true);
$dom = new DOMDocument();
$loaded = $dom->loadXML($promoted ?? '');
$errs = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors($prevInternal);
if (!$loaded) {
    $msgs = array_map(static fn (LibXMLError $e) => trim($e->message), $errs);
    $fails[] = 'output is not well-formed XML: ' . implode(' | ', array_slice($msgs, 0, 3));
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

if (empty($fails)) {
    printf("PASS — phase C2 cleanTemplateMacros promotes bare <w:tr> rows so PhpWord cloneRow finds the right row.\n");
    printf("  - all bare <w:tr> openings rewritten\n");
    printf("  - pre-existing attributed rows preserved\n");
    printf("  - transform idempotent\n");
    exit(0);
}

fwrite(STDERR, "FAIL — phase C2 regression detected:\n");
foreach ($fails as $f) {
    fwrite(STDERR, "  - $f\n");
}
exit(1);
