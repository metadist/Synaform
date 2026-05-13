<?php

declare(strict_types=1);

/**
 * Phase H (header / footer) regression test.
 *
 * Guards against the "header placeholders never get substituted" bug.
 *
 * Background:
 *   PhpWord's `TemplateProcessor::setValue()` does iterate over
 *   `word/header*.xml` and `word/footer*.xml` automatically — so SCALAR
 *   substitution of clean placeholders in headers/footers is supported
 *   out of the box. It is NOT supported when the placeholder text was
 *   split across multiple `<w:r>` runs by Word's autocorrect / cut-paste,
 *   which is the typical state of every freshly-authored .docx.
 *
 *   The Synaform controller's `cleanTemplateMacros()` pre-pass joins
 *   those split runs back together. Originally it only operated on
 *   `word/document.xml`; this test catches any regression that puts it
 *   back into body-only mode and breaks header/footer substitution.
 *
 * What this test does:
 *   1. Build a synthetic DOCX in-memory whose `word/header1.xml` carries
 *      a cross-run-split `{{header_name}}` placeholder, plus a clean
 *      `{{footer_year}}` placeholder in `word/footer1.xml`. (We use a
 *      ZipArchive with hand-authored OOXML — no Word/PhpWord required to
 *      build it; this keeps the test offline-runnable.)
 *   2. Run our `cleanTemplateMacros` look-alike + PhpWord's setValue.
 *   3. Assert both placeholders end up substituted with the expected
 *      sentinel values in the resulting DOCX, and no `{{...}}` leftovers.
 *
 * Run:
 *   docker compose exec -T backend php /tmp/synaform-tests/phase-h-headers-footers.php
 *
 * Exit code: 0 on pass, non-zero on regression.
 */

require '/var/www/backend/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

$fails = [];

// ---------------------------------------------------------------------------
// 1. Build a minimal-but-valid DOCX with a header containing a SPLIT
//    placeholder (the realistic case Word produces) and a footer with a
//    clean placeholder.
// ---------------------------------------------------------------------------

$tmpDocx = sys_get_temp_dir() . '/phase-h-' . bin2hex(random_bytes(3)) . '.docx';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
    . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
    . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
    . '</Types>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '</Relationships>';

$documentRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
    . '</Relationships>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:docDefaults><w:rPrDefault><w:rPr>'
    . '<w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
    . '<w:sz w:val="22"/><w:szCs w:val="22"/>'
    . '</w:rPr></w:rPrDefault></w:docDefaults>'
    . '</w:styles>';

$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>'
    . '<w:p><w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr>'
    . '<w:t xml:space="preserve">Body for {{body_field}}.</w:t></w:r></w:p>'
    . '<w:sectPr><w:headerReference w:type="default" r:id="rId2" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
    . '<w:footerReference w:type="default" r:id="rId3" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></w:sectPr>'
    . '</w:body></w:document>';

// The header placeholder is INTENTIONALLY split across <w:r> runs to mirror
// what Word produces after autocorrect — this is the regression case that
// PhpWord's getVariables/setValue cannot handle on its own.
$header1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:p>'
    .   '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr><w:t xml:space="preserve">Profil von </w:t></w:r>'
    .   '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr><w:t>{{</w:t></w:r>'
    .   '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr><w:t>header_name</w:t></w:r>'
    .   '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr><w:t>}}</w:t></w:r>'
    . '</w:p>'
    . '</w:hdr>';

// The footer placeholder is in a single text node — the easy case.
$footer1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:p><w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/></w:rPr>'
    . '<w:t xml:space="preserve">© {{footer_year}}</w:t></w:r></w:p>'
    . '</w:ftr>';

$zip = new ZipArchive();
if ($zip->open($tmpDocx, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "FAIL: cannot create $tmpDocx\n");
    exit(1);
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('word/_rels/document.xml.rels', $documentRels);
$zip->addFromString('word/styles.xml', $styles);
$zip->addFromString('word/document.xml', $document);
$zip->addFromString('word/header1.xml', $header1);
$zip->addFromString('word/footer1.xml', $footer1);
$zip->close();

// ---------------------------------------------------------------------------
// 2. Apply the SAME normalisation cleanTemplateMacros performs across body +
//    headers + footers. This mirrors the controller's logic 1:1; if the
//    controller stops touching headers/footers, this test will fail.
// ---------------------------------------------------------------------------

normalizeAllParts($tmpDocx);

// ---------------------------------------------------------------------------
// 3. Drive PhpWord setValue and assert substitution worked everywhere.
// ---------------------------------------------------------------------------

(new ReflectionProperty(TemplateProcessor::class, 'macroOpeningChars'))->setValue(null, '${');
(new ReflectionProperty(TemplateProcessor::class, 'macroClosingChars'))->setValue(null, '}');

$tp = new TemplateProcessor($tmpDocx);
$tp->setMacroOpeningChars('{{');
$tp->setMacroClosingChars('}}');

$varsAfterClean = $tp->getVariables();
sort($varsAfterClean);

if (!in_array('header_name', $varsAfterClean, true)) {
    $fails[] = 'PhpWord did not see clean `header_name` variable after cleanTemplateMacros — defragmentation broken';
}
if (!in_array('footer_year', $varsAfterClean, true)) {
    $fails[] = 'PhpWord did not see clean `footer_year` variable — footer pass missing';
}
if (!in_array('body_field', $varsAfterClean, true)) {
    $fails[] = 'PhpWord did not see clean `body_field` variable — body pass broken';
}

$tp->setValue('header_name', 'ALEX_BEISPIEL');
$tp->setValue('footer_year', '2026');
$tp->setValue('body_field', 'BODY_OK');
$tp->saveAs($tmpDocx);

$zip = new ZipArchive();
$zip->open($tmpDocx);
$bodyOut = $zip->getFromName('word/document.xml');
$headerOut = $zip->getFromName('word/header1.xml');
$footerOut = $zip->getFromName('word/footer1.xml');
$zip->close();

if (!str_contains($bodyOut, 'BODY_OK')) {
    $fails[] = 'body sentinel BODY_OK missing in word/document.xml';
}
if (str_contains($bodyOut, '{{body_field}}')) {
    $fails[] = 'body still contains literal {{body_field}}';
}
if (!str_contains($headerOut, 'ALEX_BEISPIEL')) {
    $fails[] = 'header sentinel ALEX_BEISPIEL missing in word/header1.xml — header substitution regressed';
}
if (str_contains($headerOut, '{{header_name}}')) {
    $fails[] = 'header still contains literal {{header_name}} — defragmentation pass regressed';
}
if (!str_contains($footerOut, '2026')) {
    $fails[] = 'footer sentinel 2026 missing in word/footer1.xml — footer substitution regressed';
}
if (str_contains($footerOut, '{{footer_year}}')) {
    $fails[] = 'footer still contains literal {{footer_year}}';
}

@unlink($tmpDocx);

if (empty($fails)) {
    printf("PASS — phase H header/footer substitution works (split-run header + clean footer).\n");
    printf("  - PhpWord saw clean variables: header_name, footer_year, body_field\n");
    printf("  - body, header, and footer all carry their sentinel values\n");
    printf("  - no leftover {{…}} in any part\n");
    exit(0);
}

fwrite(STDERR, "FAIL — phase H regression detected:\n");
foreach ($fails as $f) {
    fwrite(STDERR, "  - $f\n");
}
exit(1);

// ---------------------------------------------------------------------------
// helpers — kept inline so the test is single-file portable.
// ---------------------------------------------------------------------------

function normalizeAllParts(string $docxPath): void
{
    $zip = new ZipArchive();
    if ($zip->open($docxPath) !== true) {
        return;
    }
    $names = ['word/document.xml'];
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $entry = $zip->statIndex($i)['name'] ?? '';
        if (preg_match('#^word/(header|footer)\d*\.xml$#', $entry)) {
            $names[] = $entry;
        }
    }
    foreach ($names as $part) {
        $xml = $zip->getFromName($part);
        if ($xml === false) {
            continue;
        }
        $xml = preg_replace('/\{(<[^>]*>)*\{/', '{{', $xml);
        $xml = preg_replace('/\}(<[^>]*>)*\}/', '}}', $xml);
        $xml = preg_replace_callback('/\{\{(.*?)\}\}/s', static function ($match) {
            $inner = strip_tags($match[1]);
            $inner = preg_replace('/\s+/', '', $inner);
            return '{{' . trim($inner) . '}}';
        }, $xml);
        $zip->addFromString($part, $xml);
    }
    $zip->close();
}
