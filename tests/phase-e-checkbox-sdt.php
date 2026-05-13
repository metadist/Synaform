<?php

declare(strict_types=1);

/**
 * Phase E (checkbox content-control SDT) regression test.
 *
 * Guards against the SDT-overrun bug discovered on the v4 hhff DE template
 * (see _devnotes/2026-05-13-v4-font-preservation.md, follow-up patch).
 *
 * The bug:
 *   `convertCheckboxMarkersToContentControls()` runs in a loop because a
 *   single Word run can contain MULTIPLE `[[SYNCB|state|✓|✗]]` markers
 *   (the typical `{{checkb.X.yes}} Ja     {{checkb.X.no}} Nein` shape).
 *   On the second loop iteration, the regex started at the `<w:r>` *inside*
 *   the freshly-emitted SDT (because that's the first `<w:r>` it sees),
 *   failed to find a SYNCB marker inside the SDT's `<w:t>`, and backtracked
 *   the optional `<w:rPr>...</w:rPr>` capture's lazy `.*?` until it found
 *   the next `</w:rPr>` — which lives inside the leftover *next* run, AFTER
 *   `</w:sdtContent></w:sdt>`. The match then succeeded, but the captured
 *   rPr fragment contained the SDT close. The replacement re-emits that
 *   rPr twice (once around `$before`, once around `$after`), producing a
 *   document with one extra `</w:sdtContent></w:sdt>` and a bare `<w:r>`
 *   carrying the second checkbox glyph. Word and LibreOffice both refuse
 *   to open the resulting file with "unexpected end tag".
 *
 * The fix (in `convertCheckboxMarkersToContentControls`):
 *   replace the unconstrained `<w:rPr\b[^/]*?>.*?</w:rPr>` with a tempered
 *   greedy variant that cannot cross a `<w:r>` / `</w:r>` / `</w:rPr>`
 *   boundary:
 *     `<w:rPr\b[^/]*?>(?:(?!</w:rPr>|<w:r\b|</w:r>).)*?</w:rPr>`
 *
 * This test reproduces the bug shape WITHOUT booting the full Synaplan
 * stack — it works on a hand-authored document.xml fragment that mirrors
 * what `processCheckboxes` produces for a paired-glyph checkbox row.
 *
 * Run:
 *   php tests/phase-e-checkbox-sdt.php
 *
 * Exit code: 0 on pass, non-zero on regression.
 */

$rPrInner = '(?:(?!</w:rPr>|<w:r\b|</w:r>).)*?';
$rPrAlt = '(<w:rPr\b[^/]*?>' . $rPrInner . '</w:rPr>|<w:rPr\b[^/]*?/>)?';
$pattern = '#<w:r\b[^>]*>' . $rPrAlt
    . '(<w:t\b[^>]*>)([^<]*?)\[\[SYNCB\|(on|off)\|([^|]+)\|([^\]]+)\]\]([^<]*?)</w:t></w:r>#s';

$fails = [];

// ---------------------------------------------------------------------------
// Fixture: simulate the post-PhpWord run carrying TWO SYNCB markers in one
// <w:t>...</w:t> text node, just like processCheckboxes() emits for a
// `{{checkb.X.yes}} Ja     {{checkb.X.no}} Nein` template line.
// ---------------------------------------------------------------------------

$fontRPr = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr>';

$initial = '<w:p><w:pPr><w:jc w:val="both"/></w:pPr>'
         . '<w:r>' . $fontRPr . '<w:t xml:space="preserve">Umzugsbereitschaft</w:t></w:r>'
         . '<w:r>' . $fontRPr . '<w:tab/><w:tab/><w:tab/></w:r>'
         . '<w:r>' . $fontRPr . '<w:t xml:space="preserve">'
                . '[[SYNCB|on|☒|☐]] Ja     [[SYNCB|off|☒|☐]] Nein'
                . '</w:t></w:r>'
         . '</w:p>';

// Mirror the controller's loop: at each pass, replace one SYNCB-bearing run
// with `<w:r>(before)</w:r><w:sdt>...</w:sdt><w:r>(after)</w:r>` (omitting
// empty before/after runs).
$xml = $initial;
$loops = 0;
while ($loops++ < 10) {
    $passCount = 0;
    $next = preg_replace_callback(
        $pattern,
        static function (array $m) use (&$passCount): string {
            $passCount++;
            $rPr = $m[1] ?? '';
            $tOpen = $m[2];
            $before = $m[3];
            $state = $m[4];
            $checkedGlyph = $m[5];
            $uncheckedGlyph = $m[6];
            $after = $m[7];

            // Stand-in for buildCheckboxSdtXml — only the structure matters
            // here, not the w14:checkbox attributes.
            $glyph = $state === 'on' ? $checkedGlyph : $uncheckedGlyph;
            $sdt = '<w:sdt>'
                 . '<w:sdtPr><w:rPr><w:rFonts w:ascii="MS Gothic"/></w:rPr>'
                 . '<w:id w:val="1"/></w:sdtPr>'
                 . '<w:sdtContent>'
                 . '<w:r><w:rPr><w:rFonts w:ascii="MS Gothic"/></w:rPr>'
                 . '<w:t xml:space="preserve">' . $glyph . '</w:t></w:r>'
                 . '</w:sdtContent></w:sdt>';

            $out = '';
            if ($before !== '') {
                $out .= '<w:r>' . $rPr . '<w:t xml:space="preserve">' . $before . '</w:t></w:r>';
            }
            $out .= $sdt;
            if ($after !== '') {
                $out .= '<w:r>' . $rPr . '<w:t xml:space="preserve">' . $after . '</w:t></w:r>';
            }
            return $out;
        },
        $xml
    );
    if ($next === null) {
        $fails[] = 'preg_replace_callback returned null (pattern broke on input)';
        break;
    }
    if ($passCount === 0) {
        break;
    }
    $xml = $next;
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

// 1. SDT tags must balance (this is the failure mode that broke Word).
$sdtOpens     = preg_match_all('#<w:sdt\b#', $xml);
$sdtCloses    = preg_match_all('#</w:sdt>#', $xml);
$contentOpens = preg_match_all('#<w:sdtContent\b#', $xml);
$contentClose = preg_match_all('#</w:sdtContent>#', $xml);

if ($sdtOpens !== $sdtCloses) {
    $fails[] = "SDT tag imbalance: $sdtOpens opens vs $sdtCloses closes";
}
if ($contentOpens !== $contentClose) {
    $fails[] = "<w:sdtContent> tag imbalance: $contentOpens opens vs $contentClose closes";
}

// 2. Both SYNCB markers must have been consumed (none left behind).
if (preg_match('#\[\[SYNCB#', $xml)) {
    $fails[] = 'leftover [[SYNCB marker — at least one was not converted';
}

// 3. The output must be well-formed XML when wrapped in a synthetic root
//    that declares both namespaces.
$wrapped = '<root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
         . ' xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml">'
         . $xml . '</root>';
$prevInternal = libxml_use_internal_errors(true);
$dom = new DOMDocument();
$loaded = $dom->loadXML($wrapped);
$xmlErrors = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors($prevInternal);
if (!$loaded) {
    $msgs = array_map(static fn (LibXMLError $e) => trim($e->message), $xmlErrors);
    $fails[] = 'output is not well-formed XML: ' . implode(' | ', array_slice($msgs, 0, 3));
}

// 4. Both checkbox glyphs must appear in the body, in the right order:
//    ☒ (checked, "yes" = on) before ☐ (unchecked, "no" = off).
if (!preg_match('#☒.*☐#u', $xml)) {
    $fails[] = 'expected ☒…☐ glyph order not present';
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

if (empty($fails)) {
    printf("PASS — phase E checkbox SDT post-pass produces well-formed XML for double-marker runs.\n");
    printf("  - SDT opens/closes:        %d / %d\n", $sdtOpens, $sdtCloses);
    printf("  - sdtContent opens/closes: %d / %d\n", $contentOpens, $contentClose);
    printf("  - leftover SYNCB markers:  0\n");
    printf("  - output bytes:            %d\n", strlen($xml));
    exit(0);
}

fwrite(STDERR, "FAIL — phase E checkbox SDT regression detected:\n");
foreach ($fails as $f) {
    fwrite(STDERR, "  - $f\n");
}
fwrite(STDERR, "\n--- output XML ---\n$xml\n");
exit(1);
