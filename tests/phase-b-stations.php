<?php

declare(strict_types=1);

/**
 * Phase B regression test — proves that {{stations.details.N}} placeholders
 * get expanded into a sequence of real Word paragraphs (date headers in bold,
 * sub-position titles, and bullet achievements) instead of one line-break blob.
 *
 * Runs fully offline — no Docker, no Symfony, no customer data. Uses the
 * synthetic `test_template.docx` fixture. The fixture has a single
 * {{stations.details.N}} placeholder in a table cell; we rewrite it to
 * {{stations.details.N#1}} to simulate what PhpWord's cloneRow() leaves
 * behind, then exercise the same algorithm the controller uses.
 *
 * Usage: php tests/phase-b-stations.php
 * Exit: 0 on pass, 1 on failure.
 */

$fixture = __DIR__ . '/fixtures/test_template.docx';
if (!is_file($fixture)) {
    fwrite(STDERR, "FAIL: fixture missing: $fixture\n");
    exit(1);
}

$out = sys_get_temp_dir() . '/templatex_phase_b_out.docx';
copy($fixture, $out);

// --- Simulate cloneRow's suffixing: {{stations.details.N}} → {{stations.details.N#1}}
$zip = new ZipArchive();
$zip->open($out);
$xml = $zip->getFromName('word/document.xml');
$xml = str_replace('{{stations.details.N}}', '{{stations.details.N#1}}', $xml);
$zip->addFromString('word/document.xml', $xml);
$zip->close();

// --- Fully synthetic station detail exercising every block type the renderer
//     understands: a date header, a role title, and a bullet list — twice —
//     with an empty-line separator between the two sub-positions. No domain-
//     specific phrasing; the strings here are arbitrary test markers.
$station = [
    'employer' => 'Acme Industries GmbH',
    'time'     => '02/2021 -- heute',
    'details'  => <<<DETAILS
04/2024 -- heute
Senior Director Alpha
- Led a cross-functional team of twelve across three locations
- Owned annual budget of fifty million euros
- Launched two new product lines in the reporting period

02/2021 -- 04/2024
Team Lead Beta
- Drove process automation and tooling standardisation
- Grew the unit from eight to fifteen engineers
DETAILS,
];

// --- Inline copies of the controller algorithm (dependency-free)

function escapeForWordXml(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    if (preg_match('/\r|\n/', $escaped) === 1) {
        $escaped = preg_replace('/\r\n|\r|\n/', '</w:t><w:br/><w:t>', $escaped);
    }
    return $escaped;
}

function parseStationDetails(string $details): array
{
    $lines = preg_split('/\r\n|\r|\n/', $details) ?: [];
    $dateRange = '~^\s*\d{1,2}[./]\d{4}\s*[\-–—]{1,2}\s*(?:heute|today|laufend|\d{1,2}[./]\d{4})\s*$~iu';
    $yearRange = '~^\s*\d{4}\s*[\-–—]{1,2}\s*(?:heute|today|laufend|\d{4})\s*$~iu';
    $bullet    = '~^[\-*•·–—]\s+(.*)$~u';
    $blocks = [];
    foreach ($lines as $line) {
        $s = trim($line);
        if ($s === '')                                                     { $blocks[] = ['type' => 'spacer']; continue; }
        if (preg_match($dateRange, $s) || preg_match($yearRange, $s))      { $blocks[] = ['type' => 'date',   'text' => $s]; continue; }
        if (preg_match($bullet, $s, $bm))                                  { $blocks[] = ['type' => 'bullet', 'text' => trim($bm[1])]; continue; }
        $blocks[] = ['type' => 'text', 'text' => $s];
    }
    $collapsed = []; $last = false;
    foreach ($blocks as $b) {
        if ($b['type'] === 'spacer') { if ($last) continue; $last = true; }
        else { $last = false; }
        $collapsed[] = $b;
    }
    while (!empty($collapsed) && $collapsed[0]['type'] === 'spacer')         array_shift($collapsed);
    while (!empty($collapsed) && end($collapsed)['type'] === 'spacer')       array_pop($collapsed);
    return $collapsed;
}

function renderStationDetailsXml(string $details, string $basePPr, ?int $bulletNumId): string
{
    $blocks = parseStationDetails($details);
    if (empty($blocks)) return '';
    $bulletPPr = $bulletNumId !== null
        ? '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $bulletNumId . '"/></w:numPr><w:spacing w:after="0"/><w:ind w:left="360" w:hanging="360"/></w:pPr>'
        : '<w:pPr><w:spacing w:after="0"/><w:ind w:left="360" w:hanging="360"/></w:pPr>';
    $out = '';
    foreach ($blocks as $b) {
        switch ($b['type']) {
            case 'spacer':
                $out .= '<w:p>' . $basePPr . '</w:p>';
                break;
            case 'date':
                $out .= '<w:p>' . $basePPr
                    . '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'
                    . escapeForWordXml($b['text']) . '</w:t></w:r></w:p>';
                break;
            case 'bullet':
                $prefix = $bulletNumId !== null ? '' : '• ';
                $out .= '<w:p>' . $bulletPPr
                    . '<w:r><w:t xml:space="preserve">' . $prefix . escapeForWordXml($b['text']) . '</w:t></w:r></w:p>';
                break;
            default:
                $out .= '<w:p>' . $basePPr
                    . '<w:r><w:t xml:space="preserve">' . escapeForWordXml($b['text']) . '</w:t></w:r></w:p>';
        }
    }
    return $out;
}

function detectBulletNumId(string $numberingXml): ?int
{
    if ($numberingXml === '') return null;
    $bulletAbs = [];
    if (preg_match_all('#<w:abstractNum\b[^>]*?w:abstractNumId="(\d+)"[^>]*>(.*?)</w:abstractNum>#s', $numberingXml, $am)) {
        foreach ($am[1] as $idx => $absId) {
            if (preg_match('#<w:lvl\b[^>]*?w:ilvl="0"[^>]*>(.*?)</w:lvl>#s', $am[2][$idx], $lvl)) {
                if (str_contains($lvl[1], '<w:numFmt w:val="bullet"/>')) $bulletAbs[$absId] = true;
            }
        }
    }
    if (empty($bulletAbs)) return null;
    if (preg_match_all('#<w:num\b[^>]*?w:numId="(\d+)"[^>]*>(.*?)</w:num>#s', $numberingXml, $nm)) {
        foreach ($nm[1] as $idx => $numId) {
            if (preg_match('#<w:abstractNumId\s+w:val="(\d+)"\s*/>#', $nm[2][$idx], $ref)) {
                if (isset($bulletAbs[$ref[1]])) return (int) $numId;
            }
        }
    }
    return null;
}

function expandStationDetails(string $docxPath, array $stations): void
{
    if (empty($stations)) return;
    $zip = new ZipArchive();
    if ($zip->open($docxPath) !== true) return;
    $xml = $zip->getFromName('word/document.xml');
    $numberingXml = $zip->getFromName('word/numbering.xml');
    $bulletNumId = is_string($numberingXml) ? detectBulletNumId($numberingXml) : null;

    foreach ($stations as $i => $station) {
        $num = $i + 1;
        $details = is_array($station) ? (string) ($station['details'] ?? '') : (string) $station;
        foreach (["{{stations.details.N#{$num}}}", "{{stations.details#{$num}}}"] as $ph) {
            if (!str_contains($xml, $ph)) continue;
            if (trim($details) === '') { $xml = str_replace($ph, '', $xml); continue; }
            $pattern = '#<w:p\b[^>]*>(?:(?!</w:p>).)*?' . preg_quote($ph, '#') . '(?:(?!</w:p>).)*?</w:p>#s';
            $xml = preg_replace_callback($pattern, function(array $m) use ($details, $bulletNumId): string {
                $basePPr = '';
                if (preg_match('#<w:pPr>.*?</w:pPr>#s', $m[0], $pm)) $basePPr = $pm[0];
                return renderStationDetailsXml($details, $basePPr, $bulletNumId);
            }, $xml);
        }
    }
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
}

// --- Count <w:p> before
$zip = new ZipArchive(); $zip->open($out);
$origXml = $zip->getFromName('word/document.xml');
$zip->close();
$origParas = substr_count($origXml, '<w:p ') + substr_count($origXml, '<w:p>');

expandStationDetails($out, [$station]);

// --- Assertions
$zip = new ZipArchive(); $zip->open($out);
$finalXml = $zip->getFromName('word/document.xml');
$zip->close();

$fails = [];

if (str_contains($finalXml, '{{stations.details')) {
    $fails[] = 'leftover stations.details placeholder remains in XML';
}

$expectedSubstrings = [
    '04/2024 -- heute',
    'Senior Director Alpha',
    'Led a cross-functional team of twelve',
    'fifty million euros',
    'Launched two new product lines',
    '02/2021 -- 04/2024',
    'Team Lead Beta',
    'Drove process automation',
];
$foundCount = 0;
foreach ($expectedSubstrings as $needle) {
    $haystack = htmlspecialchars($needle, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    if (!str_contains($finalXml, $haystack)) {
        $fails[] = "expected substring not found: '$needle'";
    } else {
        $foundCount++;
    }
}
printf("  substrings found: %d/%d\n", $foundCount, count($expectedSubstrings));

// Expect two bold date headers
$boldDateRuns = preg_match_all('#<w:r><w:rPr><w:b/></w:rPr><w:t[^>]*>[^<]*\d{2}[./]\d{4}[^<]*</w:t></w:r>#u', $finalXml);
printf("  bold date header runs: %d\n", (int) $boldDateRuns);
if ($boldDateRuns < 2) {
    $fails[] = "expected ≥2 bold date header runs, got $boldDateRuns";
}

// Paragraph count must have jumped
$newParas = substr_count($finalXml, '<w:p ') + substr_count($finalXml, '<w:p>');
printf("  <w:p> count: before=%d, after=%d (delta=%d)\n", $origParas, $newParas, $newParas - $origParas);
if ($newParas - $origParas < 8) {
    $fails[] = "paragraph count growth too small; details structure likely flattened";
}

// Bullet fallback: test template has no numbering.xml → each bullet line should
// be prefixed with "• ".
$bulletPrefix = substr_count($finalXml, '>• ');
printf("  char-bullet prefixes ('• '): %d\n", $bulletPrefix);
if ($bulletPrefix < 4) {
    $fails[] = "expected ≥4 char-bullet prefixes in fallback mode, got $bulletPrefix";
}

printf("\n  output: %s\n", $out);

if (!empty($fails)) {
    echo "\nFAIL\n";
    foreach ($fails as $f) echo "  - $f\n";
    exit(1);
}

echo "\nPASS — phase B station detail expansion works on the synthetic fixture.\n";
exit(0);
