<?php

declare(strict_types=1);

/**
 * Phase E2 regression test — checkbox SDT post-pass must survive runs that
 * carry inline elements (most commonly <w:tab/>) between </w:rPr> and <w:t>.
 *
 * The shape in question, lifted verbatim from the customer's
 * `Profil hhff DE v4.docx` template, looks like this after PhpWord's
 * setValue replaces the {{checkb.moving.yes}} placeholder:
 *
 *   <w:r>
 *     <w:rPr>…Arial 12pt de-DE…</w:rPr>
 *     <w:tab/>
 *     <w:t>[[SYNCB|on|☒|☐]] Ja</w:t>
 *   </w:r>
 *
 * Before the 2026-05-26 fix, the post-pass regex required `<w:t…>` to
 * appear directly after the rPr (or after the `<w:r>` opening if no rPr),
 * so the tab broke the match and every Bereitschaft (Umzug / Pendel /
 * Reise) ended up with literal `[[SYNCB|on|☒|☐]] Ja` text in the final
 * document. This test reproduces that exact shape and asserts:
 *
 *   1. the regex matches (passCount > 0),
 *   2. all SYNCB markers are consumed,
 *   3. the original <w:tab/> is preserved BEFORE the SDT,
 *   4. SDT / sdtContent tags balance,
 *   5. the result is well-formed XML.
 *
 * Run:
 *   php tests/phase-e2-checkbox-sdt-with-tab.php
 *
 * Exit code: 0 on pass, non-zero on regression.
 */

// Mirror the production regex in `convertCheckboxMarkersInPart`. Capture
// groups (must stay in sync):
//   1 = rPr block, 2 = inline-middle (<w:tab/>, <w:br/>, …),
//   3 = <w:t …> opening tag, 4 = before-text, 5 = state,
//   6 = checked glyph, 7 = unchecked glyph, 8 = after-text.
$rPrInner = '(?:(?!</w:rPr>|<w:r\b|</w:r>).)*?';
$rPrAlt = '(<w:rPr\b[^/]*?>' . $rPrInner . '</w:rPr>|<w:rPr\b[^/]*?/>)?';
$inlineMid = '((?:(?!<w:t[\s>/]|<w:r\b|</w:r>).)*?)';
$pattern = '#<w:r\b[^>]*>' . $rPrAlt . $inlineMid
    . '(<w:t\b[^>]*>)([^<]*?)\[\[SYNCB\|(on|off)\|([^|]+)\|([^\]]+)\]\]([^<]*?)</w:t></w:r>#s';

$fails = [];

// ---------------------------------------------------------------------------
// Fixtures — three customer-shaped inline-element scenarios.
// ---------------------------------------------------------------------------

$fontRPr = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
         . '<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="de-DE"/></w:rPr>';

$fixtures = [
    // Verbatim from Profil hhff DE v4 — a tab separates the leading
    // empty content from the SYNCB-bearing <w:t>.
    'tab' => '<w:p>'
        . '<w:r>' . $fontRPr . '<w:tab/><w:t xml:space="preserve">'
        . '[[SYNCB|on|☒|☐]] Ja</w:t></w:r>'
        . '<w:r>' . $fontRPr . '<w:tab/><w:t xml:space="preserve">'
        . '[[SYNCB|off|☒|☐]] Nein</w:t></w:r>'
        . '</w:p>',

    // <w:br/> between rPr and <w:t> — exercises the same code path with
    // a different inline-content node so a future regex regression that
    // works for tab but not break still trips.
    'break' => '<w:p>'
        . '<w:r>' . $fontRPr . '<w:br/><w:t xml:space="preserve">'
        . '[[SYNCB|on|☒|☐]] Ja</w:t></w:r>'
        . '</w:p>',

    // Multiple inline elements stacked (e.g. tab + symbol). The inline
    // middle should match all of them.
    'tab_plus_sym' => '<w:p>'
        . '<w:r>' . $fontRPr . '<w:tab/><w:sym w:font="Wingdings" w:char="F0FE"/>'
        . '<w:t xml:space="preserve">[[SYNCB|on|☒|☐]] Ja</w:t></w:r>'
        . '</w:p>',
];

// ---------------------------------------------------------------------------
// Run the post-pass against each fixture and assert the outcome.
// ---------------------------------------------------------------------------

foreach ($fixtures as $label => $initial) {
    $xml = $initial;
    $totalPasses = 0;
    $loops = 0;
    $tabsBefore = preg_match_all('#<w:tab/>#', $initial);
    $brsBefore = preg_match_all('#<w:br/>#', $initial);
    $symsBefore = preg_match_all('#<w:sym\b#', $initial);

    while ($loops++ < 10) {
        $passCount = 0;
        $next = preg_replace_callback(
            $pattern,
            static function (array $m) use (&$passCount): string {
                $passCount++;
                $rPr = $m[1] ?? '';
                $inlineMid = $m[2] ?? '';
                $before = $m[4];
                $state = $m[5];
                $checkedGlyph = $m[6];
                $uncheckedGlyph = $m[7];
                $after = $m[8];

                $glyph = $state === 'on' ? $checkedGlyph : $uncheckedGlyph;
                $sdt = '<w:sdt><w:sdtPr><w:rPr><w:rFonts w:ascii="MS Gothic"/></w:rPr>'
                     . '<w:id w:val="42"/></w:sdtPr><w:sdtContent>'
                     . '<w:r><w:rPr><w:rFonts w:ascii="MS Gothic"/></w:rPr>'
                     . '<w:t xml:space="preserve">' . $glyph . '</w:t></w:r>'
                     . '</w:sdtContent></w:sdt>';

                $out = '';
                if ($before !== '') {
                    $out .= '<w:r>' . $rPr . $inlineMid
                        . '<w:t xml:space="preserve">' . $before . '</w:t></w:r>';
                } elseif ($inlineMid !== '') {
                    $out .= '<w:r>' . $rPr . $inlineMid . '</w:r>';
                }
                $out .= $sdt;
                if ($after !== '') {
                    $out .= '<w:r>' . $rPr
                        . '<w:t xml:space="preserve">' . $after . '</w:t></w:r>';
                }
                return $out;
            },
            $xml
        );
        if ($next === null) {
            $fails[] = "[$label] preg_replace_callback returned null";
            break;
        }
        if ($passCount === 0) {
            break;
        }
        $totalPasses += $passCount;
        $xml = $next;
    }

    if ($totalPasses === 0) {
        $fails[] = "[$label] regex failed to match — SYNCB marker left literal "
                 . '(this is the v4-hhff release blocker)';
    }
    if (preg_match('#\[\[SYNCB#', $xml)) {
        $fails[] = "[$label] leftover [[SYNCB after $loops loops";
    }

    $sdtOpens = preg_match_all('#<w:sdt\b#', $xml);
    $sdtCloses = preg_match_all('#</w:sdt>#', $xml);
    if ($sdtOpens !== $sdtCloses) {
        $fails[] = "[$label] SDT imbalance: $sdtOpens opens vs $sdtCloses closes";
    }

    $contentOpens = preg_match_all('#<w:sdtContent\b#', $xml);
    $contentClose = preg_match_all('#</w:sdtContent>#', $xml);
    if ($contentOpens !== $contentClose) {
        $fails[] = "[$label] sdtContent imbalance: $contentOpens vs $contentClose";
    }

    // Inline content (tabs / breaks / symbols) that stood ahead of the SYNCB
    // marker MUST still appear in the result — losing them would silently
    // collapse the visual layout.
    $tabsAfter = preg_match_all('#<w:tab/>#', $xml);
    $brsAfter = preg_match_all('#<w:br/>#', $xml);
    $symsAfter = preg_match_all('#<w:sym\b#', $xml);
    if ($tabsAfter !== $tabsBefore) {
        $fails[] = "[$label] <w:tab/> count changed: $tabsBefore → $tabsAfter";
    }
    if ($brsAfter !== $brsBefore) {
        $fails[] = "[$label] <w:br/> count changed: $brsBefore → $brsAfter";
    }
    if ($symsAfter !== $symsBefore) {
        $fails[] = "[$label] <w:sym/> count changed: $symsBefore → $symsAfter";
    }

    // Output must parse as XML once wrapped in a root that declares both
    // namespaces.
    $wrapped = '<root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml">'
        . $xml . '</root>';
    $prevInternal = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($wrapped);
    $errs = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prevInternal);
    if (!$loaded) {
        $msgs = array_map(static fn (LibXMLError $e) => trim($e->message), $errs);
        $fails[] = "[$label] not well-formed XML: " . implode(' | ', array_slice($msgs, 0, 3));
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

if (empty($fails)) {
    printf("PASS — phase E2 SDT post-pass survives <w:tab/> / <w:br/> / <w:sym/> between rPr and <w:t>.\n");
    printf("  - fixtures verified: %d (tab / break / tab+sym)\n", count($fixtures));
    printf("  - all SYNCB markers consumed, all SDT pairs balanced, all inline elements preserved.\n");
    exit(0);
}

fwrite(STDERR, "FAIL — phase E2 regression detected:\n");
foreach ($fails as $f) {
    fwrite(STDERR, "  - $f\n");
}
exit(1);
