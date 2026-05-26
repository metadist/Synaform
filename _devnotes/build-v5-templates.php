<?php

declare(strict_types=1);

/**
 * Build the v5 hhff customer-release templates from the v4 master copies.
 *
 * What it does (deterministic, idempotent):
 *
 *   1. Open `/wwwroot/hhff/word-files/v4/Profil hhff DE v4.docx`.
 *   2. Defragment {{placeholders}} the same way `cleanTemplateMacros()` does
 *      so the source XML can be located by literal substring match.
 *   3. Replace the Werdegang anchor paragraphs (the inline
 *      `{{stations.time.N}}`+`{{stations.employer.N}}` paragraph and the
 *      following `{{stations.details.N}}` paragraph) with ONE single-row
 *      2-column table. PhpWord's `TemplateProcessor::cloneRow()` then
 *      replicates that single row per station at generation time and the
 *      visual layout matches the customer-canonical
 *      `Profil A.Findeisen Deichmann MH.docx` reference (time on the left,
 *      employer/position/details stacked on the right).
 *   4. Remove the inline-styled list-paragraph residue ({{stations.details.N}}
 *      lived in a numPr ListParagraph paragraph with a left indent of 1988
 *      twips — that vertical bullet-rail still showed inside the right cell
 *      after we wrap it in a table; the v5 details paragraph drops the
 *      ListParagraph style and keeps the engine's runtime bullet rendering
 *      via `expandRichRowColumns`).
 *   5. Save as `/wwwroot/hhff/word-files/v5/Profil hhff DE v5.docx` and
 *      `/wwwroot/hhff/word-files/v5/Profil NeedleHaystack DE v5.docx`.
 *
 * The transformation only touches the Werdegang region; every other
 * paragraph, header, footer, image, list and content-control is preserved
 * byte-for-byte from the v4 source.
 *
 * Re-running is safe: it overwrites the v5 outputs.
 *
 * Usage:
 *   php _devnotes/build-v5-templates.php
 */

$ROOT = '/wwwroot/hhff/word-files';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function defragmentMacros(string $xml): string
{
    $xml = preg_replace('/\{(<[^>]*>)*\{/', '{{', $xml);
    $xml = preg_replace('/\}(<[^>]*>)*\}/', '}}', $xml);
    $xml = preg_replace_callback('/\{\{(.*?)\}\}/s', function ($m) {
        $inner = strip_tags($m[1]);
        $inner = preg_replace('/\s+/', '', $inner);
        return '{{' . trim($inner) . '}}';
    }, $xml);
    return $xml;
}

/**
 * Find the start offset of the <w:p ...> element that contains $needle.
 */
function findEnclosingParagraph(string $xml, string $needle, int $startFrom = 0): array
{
    $idx = strpos($xml, $needle, $startFrom);
    if ($idx === false) {
        throw new RuntimeException("needle not found: $needle");
    }
    $pStart = max(strrpos(substr($xml, 0, $idx), '<w:p '), strrpos(substr($xml, 0, $idx), '<w:p>'));
    if ($pStart === false) {
        throw new RuntimeException("paragraph open not found before: $needle");
    }
    $pEnd = strpos($xml, '</w:p>', $idx);
    if ($pEnd === false) {
        throw new RuntimeException("paragraph close not found after: $needle");
    }
    $pEnd += strlen('</w:p>');
    return [$pStart, $pEnd];
}

/**
 * Find the smallest <w:tbl>…</w:tbl> region containing the offset $idx.
 */
function findEnclosingTable(string $xml, int $idx): array
{
    $tStart = strrpos(substr($xml, 0, $idx), '<w:tbl>');
    if ($tStart === false) {
        $tStart = strrpos(substr($xml, 0, $idx), '<w:tbl ');
    }
    if ($tStart === false) {
        throw new RuntimeException('no enclosing <w:tbl> found');
    }
    $tEnd = strpos($xml, '</w:tbl>', $idx);
    if ($tEnd === false) {
        throw new RuntimeException('no enclosing </w:tbl> found');
    }
    $tEnd += strlen('</w:tbl>');
    return [$tStart, $tEnd];
}

/**
 * Build a single-row 2-column table that PhpWord's cloneRow can replicate
 * per station. Left cell holds the time placeholder; right cell stacks
 * employer (bold), position, and details (rich) — same order the customer
 * uses in the canonical Profil A.Findeisen Deichmann MH.docx reference.
 *
 * Font/size/colour: Arial 12pt (w:sz=24) which matches every other body
 * paragraph in Profil hhff DE v4.docx. Cell borders: invisible
 * (`val="nil"`) so the printed result reads as flowed text, not a grid.
 *
 * Column widths in twips (1/1440 of an inch):
 *   - left:  2300 (~4.0 cm) — fits "02/2021 – heute" on one line
 *   - right: 6900 (~12.0 cm) — remaining usable width on A4 with the
 *           customer's existing 2.5 cm margins.
 */
function buildStationsTableHhff(): string
{
    $rPr = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
         . '<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="de-DE"/></w:rPr>';
    $rPrBold = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
             . '<w:b/><w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="de-DE"/></w:rPr>';
    $tcBorders = '<w:tcBorders>'
        . '<w:top w:val="nil"/><w:left w:val="nil"/>'
        . '<w:bottom w:val="nil"/><w:right w:val="nil"/>'
        . '<w:insideH w:val="nil"/><w:insideV w:val="nil"/>'
        . '</w:tcBorders>';
    $cellBefore = '<w:tcPr>'
        . '<w:tcW w:w="2300" w:type="dxa"/>'
        . $tcBorders
        . '</w:tcPr>';
    $cellAfter = '<w:tcPr>'
        . '<w:tcW w:w="6900" w:type="dxa"/>'
        . $tcBorders
        . '</w:tcPr>';

    $pPrLeft = '<w:pPr>'
        . '<w:spacing w:after="0"/>'
        . '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
        . '<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="de-DE"/></w:rPr>'
        . '</w:pPr>';
    $pPrRight = '<w:pPr>'
        . '<w:spacing w:after="0"/>'
        . '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
        . '<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="de-DE"/></w:rPr>'
        . '</w:pPr>';
    $pPrDetails = '<w:pPr>'
        . '<w:spacing w:before="40" w:after="40"/>'
        . '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
        . '<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="de-DE"/></w:rPr>'
        . '</w:pPr>';

    $tblPr = '<w:tblPr>'
        . '<w:tblW w:w="9200" w:type="dxa"/>'
        . '<w:tblInd w:w="0" w:type="dxa"/>'
        . '<w:tblBorders>'
        .   '<w:top w:val="nil"/><w:left w:val="nil"/>'
        .   '<w:bottom w:val="nil"/><w:right w:val="nil"/>'
        .   '<w:insideH w:val="nil"/><w:insideV w:val="nil"/>'
        . '</w:tblBorders>'
        . '<w:tblLayout w:type="fixed"/>'
        . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" '
        .   'w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/>'
        . '</w:tblPr>';

    $tblGrid = '<w:tblGrid>'
        . '<w:gridCol w:w="2300"/>'
        . '<w:gridCol w:w="6900"/>'
        . '</w:tblGrid>';

    // CRITICAL: open the row as `<w:tr w:rsidR="…">` (with attributes), not
    // bare `<w:tr>`. PhpWord's TemplateProcessor::findRowStart() searches
    // backward for `<w:tr ` (with the trailing space) before falling back
    // to `<w:tr>`. When this row uses `<w:tr>`, findRowStart skips it and
    // matches the LAST attributed `<w:tr ` ABOVE the current table — which
    // is the closing row of the previous benefits/skills table. The slice
    // then spans both tables and the cloned content includes the
    // `{{other_skills}}` row, the empty spacer paragraphs and the table
    // boundary itself, producing the corrupted "sonstige Kenntnisse leaks
    // into stations" we saw on the first v5 NH attempt. Adding any
    // attribute fixes the lookup.
    $row = '<w:tr w:rsidR="00000001" w:rsidTr="00000001">'
        . '<w:trPr><w:cantSplit/></w:trPr>'
        // -------- LEFT cell: time --------
        . '<w:tc>' . $cellBefore
        . '<w:p>' . $pPrLeft
        .   '<w:r>' . $rPr . '<w:t xml:space="preserve">{{stations.time.N}}</w:t></w:r>'
        . '</w:p>'
        . '</w:tc>'
        // -------- RIGHT cell: employer (bold), position, details --------
        . '<w:tc>' . $cellAfter
        . '<w:p>' . $pPrRight
        .   '<w:r>' . $rPrBold . '<w:t xml:space="preserve">{{stations.employer.N}}</w:t></w:r>'
        . '</w:p>'
        . '<w:p>' . $pPrRight
        .   '<w:r>' . $rPr . '<w:t xml:space="preserve">{{stations.position.N}}</w:t></w:r>'
        . '</w:p>'
        . '<w:p>' . $pPrDetails
        .   '<w:r>' . $rPr . '<w:t xml:space="preserve">{{stations.details.N}}</w:t></w:r>'
        . '</w:p>'
        . '</w:tc>'
        . '</w:tr>';

    // Trailing empty paragraph keeps the next section (Generated month/year
    // line) readable; without it Word rams the table flush against whatever
    // comes next.
    $trailingP = '<w:p>'
        . '<w:pPr><w:spacing w:after="0"/></w:pPr>'
        . '</w:p>';

    return '<w:tbl>' . $tblPr . $tblGrid . $row . '</w:tbl>' . $trailingP;
}

// ---------------------------------------------------------------------------
// hhff DE
// ---------------------------------------------------------------------------

$hhffSrc = $ROOT . '/v4/Profil hhff DE v4.docx';
$hhffDst = $ROOT . '/v5/Profil hhff DE v5.docx';

if (!is_file($hhffSrc)) {
    fwrite(STDERR, "missing: $hhffSrc\n"); exit(1);
}

@unlink($hhffDst);
copy($hhffSrc, $hhffDst);

$zip = new ZipArchive();
if ($zip->open($hhffDst) !== true) {
    fwrite(STDERR, "cannot open: $hhffDst\n"); exit(1);
}

$xml = $zip->getFromName('word/document.xml');
if ($xml === false) {
    fwrite(STDERR, "missing word/document.xml in: $hhffDst\n"); exit(1);
}

$xml = defragmentMacros($xml);

[$p1Start, $p1End] = findEnclosingParagraph($xml, '{{stations.time.N}}');
$pre = substr($xml, 0, $p1End);
$rest = substr($xml, $p1End);
[$p2RelStart, $p2RelEnd] = findEnclosingParagraph($rest, '{{stations.details.N}}');
if ($p2RelStart !== 0) {
    fwrite(STDERR, "warn: details paragraph not immediately after time+employer (gap=$p2RelStart bytes)\n");
}
$replaceStart = $p1Start;
$replaceEnd   = $p1End + $p2RelEnd;

$newXml = substr($xml, 0, $replaceStart)
    . buildStationsTableHhff()
    . substr($xml, $replaceEnd);

$zip->addFromString('word/document.xml', $newXml);
$zip->close();

printf("hhff: replaced [%d..%d] (%d bytes) with single-row 2-col stations table -> %s\n",
    $replaceStart, $replaceEnd, $replaceEnd - $replaceStart, $hhffDst);

// Verify
$verifyZip = new ZipArchive();
$verifyZip->open($hhffDst);
$v = $verifyZip->getFromName('word/document.xml');
$verifyZip->close();
$placeholders = [];
if (preg_match_all('/\{\{([^}]+)\}\}/', $v, $m)) {
    foreach ($m[1] as $k) {
        $k = trim($k);
        $placeholders[$k] = true;
    }
}
printf("        placeholders detected: %d (table cells use {{stations.time.N}}, "
     . "{{stations.employer.N}}, {{stations.position.N}}, {{stations.details.N}})\n",
    count($placeholders));

// ---------------------------------------------------------------------------
// NeedleHaystack DE
// ---------------------------------------------------------------------------
//
// The v4 NH template already places stations placeholders inside a 2-column
// <w:tbl>, but it spreads ONE logical station across THREE table rows
// (time/employer in row 1, blank/position in row 2, blank/details in row 3
// — see hhff/word-files/v3/ANALYSIS-v3.md §3a). PhpWord's cloneRow only
// clones ONE row per call: the row that hosts {{stations.time.N}}. So the
// NH v4 template's row 2 + row 3 are emitted ONCE for the WHOLE Werdegang,
// no matter how many stations there are. That's the documented "NH
// multi-row-per-station limitation".
//
// v5 fix: collapse the 3-row station group into a single <w:tr> with two
// cells, time in left, employer + position + details stacked in right. This
// is the same canonical layout the customer's manual MH outputs use, so
// the visual outcome is closer to "Profil A.Findeisen Deichmann MH.docx"
// while still being rendered by a single cloneRow() per station.

$nhSrc = $ROOT . '/v4/Profil NeedleHaystack DE v4.docx';
$nhDst = $ROOT . '/v5/Profil NeedleHaystack DE v5.docx';

if (!is_file($nhSrc)) {
    fwrite(STDERR, "missing: $nhSrc\n"); exit(1);
}

@unlink($nhDst);
copy($nhSrc, $nhDst);

$zip = new ZipArchive();
if ($zip->open($nhDst) !== true) {
    fwrite(STDERR, "cannot open: $nhDst\n"); exit(1);
}

$xml = $zip->getFromName('word/document.xml');
if ($xml === false) {
    fwrite(STDERR, "missing word/document.xml in: $nhDst\n"); exit(1);
}

$xml = defragmentMacros($xml);

// Locate the table that holds {{stations.time.N}}.
$timeIdx = strpos($xml, '{{stations.time.N}}');
if ($timeIdx === false) {
    fwrite(STDERR, "NH: stations.time.N placeholder not found\n"); exit(1);
}
[$tblStart, $tblEnd] = findEnclosingTable($xml, $timeIdx);
$tableXml = substr($xml, $tblStart, $tblEnd - $tblStart);
$rowCount = preg_match_all('#<w:tr\b#', $tableXml);
printf("NH:  station table at [%d..%d], %d rows in source\n", $tblStart, $tblEnd, $rowCount);

// Build a Helvetica-Light single-row replacement that mirrors the NH visual
// brand (smaller body font, 10pt = sz 20, no bold, italic position, like
// the v4 NH template's existing run formatting).
function buildStationsTableNh(): string
{
    $rPrBody = '<w:rPr><w:rFonts w:ascii="Helvetica-Light" w:eastAsiaTheme="majorEastAsia" '
        . 'w:hAnsi="Helvetica-Light" w:cstheme="majorBidi"/>'
        . '<w:sz w:val="20"/><w:szCs w:val="56"/></w:rPr>';
    $rPrItalic = '<w:rPr><w:rFonts w:ascii="Helvetica-Light" w:eastAsiaTheme="majorEastAsia" '
        . 'w:hAnsi="Helvetica-Light" w:cstheme="majorBidi"/>'
        . '<w:i/><w:sz w:val="20"/><w:szCs w:val="56"/></w:rPr>';
    $tcBorders = '<w:tcBorders>'
        . '<w:top w:val="nil"/><w:left w:val="nil"/>'
        . '<w:bottom w:val="nil"/><w:right w:val="nil"/>'
        . '<w:insideH w:val="nil"/><w:insideV w:val="nil"/>'
        . '</w:tcBorders>';
    $cellLeft = '<w:tcPr>'
        . '<w:tcW w:w="2300" w:type="dxa"/>'
        . $tcBorders
        . '</w:tcPr>';
    $cellRight = '<w:tcPr>'
        . '<w:tcW w:w="6900" w:type="dxa"/>'
        . $tcBorders
        . '</w:tcPr>';
    $pPrTight = '<w:pPr>'
        . '<w:spacing w:after="0"/>'
        . '<w:rPr><w:rFonts w:ascii="Helvetica-Light" w:eastAsiaTheme="majorEastAsia" '
        . 'w:hAnsi="Helvetica-Light" w:cstheme="majorBidi"/>'
        . '<w:sz w:val="20"/><w:szCs w:val="56"/></w:rPr>'
        . '</w:pPr>';

    $tblPr = '<w:tblPr>'
        . '<w:tblW w:w="9200" w:type="dxa"/>'
        . '<w:tblInd w:w="0" w:type="dxa"/>'
        . '<w:tblBorders>'
        .   '<w:top w:val="nil"/><w:left w:val="nil"/>'
        .   '<w:bottom w:val="nil"/><w:right w:val="nil"/>'
        .   '<w:insideH w:val="nil"/><w:insideV w:val="nil"/>'
        . '</w:tblBorders>'
        . '<w:tblLayout w:type="fixed"/>'
        . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" '
        .   'w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/>'
        . '</w:tblPr>';

    $tblGrid = '<w:tblGrid>'
        . '<w:gridCol w:w="2300"/>'
        . '<w:gridCol w:w="6900"/>'
        . '</w:tblGrid>';

    // PhpWord findRowStart caveat — see hhff helper above. Same fix here.
    $row = '<w:tr w:rsidR="00000002" w:rsidTr="00000002">'
        . '<w:trPr><w:cantSplit/></w:trPr>'
        . '<w:tc>' . $cellLeft
        . '<w:p>' . $pPrTight
        .   '<w:r>' . $rPrBody . '<w:t xml:space="preserve">{{stations.time.N}}</w:t></w:r>'
        . '</w:p>'
        . '</w:tc>'
        . '<w:tc>' . $cellRight
        . '<w:p>' . $pPrTight
        .   '<w:r>' . $rPrBody . '<w:t xml:space="preserve">{{stations.employer.N}}</w:t></w:r>'
        . '</w:p>'
        . '<w:p>' . $pPrTight
        .   '<w:r>' . $rPrItalic . '<w:t xml:space="preserve">{{stations.position.N}}</w:t></w:r>'
        . '</w:p>'
        . '<w:p>' . $pPrTight
        .   '<w:r>' . $rPrBody . '<w:t xml:space="preserve">{{stations.details.N}}</w:t></w:r>'
        . '</w:p>'
        . '</w:tc>'
        . '</w:tr>';

    return '<w:tbl>' . $tblPr . $tblGrid . $row . '</w:tbl>';
}

$newXml = substr($xml, 0, $tblStart)
    . buildStationsTableNh()
    . substr($xml, $tblEnd);

$zip->addFromString('word/document.xml', $newXml);
$zip->close();

printf("NH:  replaced %d-row station table with single-row 2-col table -> %s\n",
    $rowCount, $nhDst);

// Verify
$verifyZip = new ZipArchive();
$verifyZip->open($nhDst);
$v = $verifyZip->getFromName('word/document.xml');
$verifyZip->close();
$placeholders = [];
if (preg_match_all('/\{\{([^}]+)\}\}/', $v, $m)) {
    foreach ($m[1] as $k) {
        $k = trim($k);
        $placeholders[$k] = true;
    }
}
printf("        placeholders detected: %d\n", count($placeholders));

printf("DONE.\n");
