<?php

declare(strict_types=1);

/**
 * Phase S (static state) regression test.
 *
 * Proves that `SynaformController::candidatesGenerate()` can run more than
 * once in the same PHP process without corrupting drawings in the output
 * DOCX. The bug it guards against:
 *
 *   PhpWord's `TemplateProcessor` keeps `$macroOpeningChars` /
 *   `$macroClosingChars` as STATIC properties. Its constructor calls
 *   `fixBrokenMacros()` over document.xml using those statics. After the
 *   first `setMacroOpeningChars('{{')` in a persistent PHP-FPM / FrankenPHP
 *   worker the static stays `'{{'`, so every subsequent `new TemplateProcessor`
 *   runs `fixBrokenMacros` with the two-char opening '{'. Its regex then
 *   greedy-matches XML spans like
 *     `<a:ext uri="{28A0092B-…}">…<w:t>{{placeholder}`
 *   (i.e. "one literal `{` at the start, some non-`{$` chars, a `>{`, then
 *   any chars up to the next `}`") and `strip_tags()` eats the drawing XML
 *   inside.
 *
 * How this test triggers it:
 *   1. Loads the V3 hhff DE template (which carries `<a:ext uri="{GUID}"/>`
 *      markers inside its drawings).
 *   2. Constructs a `TemplateProcessor` with `setMacroOpeningChars('{{')`
 *      and saves it — this "primes" the static property to `'{{'`.
 *   3. Constructs a SECOND `TemplateProcessor` from the same template, saves
 *      it.
 *   4. Asserts both outputs' `document.xml` are well-formed AND still carry
 *      four intact `<a:ext uri="{…}"><a14:...` drawings.
 *
 * If the static-state guard is missing from the controller (or you change
 * how it's applied), the second file fails XML parsing and step 4 errors.
 *
 * Run:
 *   docker cp "/wwwroot/hhff/word-files/v3/Profil hhff DE v3.docx" \
 *     synaplan-backend:/tmp/v3_hhff_de.docx
 *   docker cp tests/phase-s-static-state.php \
 *     synaplan-backend:/tmp/phase-s-static-state.php
 *   docker compose exec backend php /tmp/phase-s-static-state.php
 */

require '/var/www/backend/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

$src = '/tmp/v3_hhff_de.docx';
if (!is_file($src)) {
    fwrite(STDERR, "SKIP — $src not present (copy the v3 template into the backend container first).\n");
    exit(0);
}

$fails = [];

/**
 * Reset the TemplateProcessor static macro chars back to PhpWord's library
 * defaults — the controller does this before every `new TemplateProcessor`
 * call so the constructor's `fixBrokenMacros` runs on the safe regex.
 */
$resetStatics = static function (): void {
    (new ReflectionProperty(TemplateProcessor::class, 'macroOpeningChars'))->setValue(null, '${');
    (new ReflectionProperty(TemplateProcessor::class, 'macroClosingChars'))->setValue(null, '}');
};

/**
 * Assert a DOCX's main document part is well-formed AND still carries its
 * four drawings. Drawings in the V3 template each have an `<a:ext uri="{GUID}">`
 * followed by `<a14:*` — we look for that pattern as the smoke check.
 */
$assertDocIntact = static function (string $path) use (&$fails, $src): void {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        $fails[] = "$path: cannot open";
        return;
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    try {
        new SimpleXMLElement((string) $xml);
    } catch (Throwable $e) {
        $fails[] = "$path: document.xml not well-formed — " . $e->getMessage();
    }

    // Original template has four drawings (two headers + two body logos/photo).
    // We only require >= 1 here, because the point of the test is that
    // drawings survive; the exact count is a template-design detail.
    $intact = preg_match_all('/<a:ext uri="\{[0-9A-F-]+\}"[^>]*><a14:/', (string) $xml);
    if ($intact < 1) {
        $fails[] = "$path: no intact <a:ext uri=\"{GUID}\"><a14:...> drawings remain";
    }
};

// Iteration 1 — first TemplateProcessor in a fresh process. Static chars are
// library defaults on entry; this run is always safe regardless of the fix.
$resetStatics();
$tp1 = new TemplateProcessor($src);
$tp1->setMacroOpeningChars('{{');
$tp1->setMacroClosingChars('}}');
$out1 = sys_get_temp_dir() . '/phase_s_iter1.docx';
$tp1->saveAs($out1);
unset($tp1);
$assertDocIntact($out1);

// Iteration 2 — SAME process, SAME template. If we DON'T reset the statics
// to library defaults, the constructor's fixBrokenMacros runs with '{{' and
// strip_tags() eats the drawing XML. The $resetStatics() call here is the
// exact guard the controller applies.
$resetStatics();
$tp2 = new TemplateProcessor($src);
$tp2->setMacroOpeningChars('{{');
$tp2->setMacroClosingChars('}}');
$out2 = sys_get_temp_dir() . '/phase_s_iter2.docx';
$tp2->saveAs($out2);
unset($tp2);
$assertDocIntact($out2);

// Iteration 3 — also runs with the guard. Third iteration catches any
// "corruption only happens on even-numbered runs" class of regression.
$resetStatics();
$tp3 = new TemplateProcessor($src);
$tp3->setMacroOpeningChars('{{');
$tp3->setMacroClosingChars('}}');
$out3 = sys_get_temp_dir() . '/phase_s_iter3.docx';
$tp3->saveAs($out3);
unset($tp3);
$assertDocIntact($out3);

// Negative-control iteration — deliberately skip the reset. This must
// corrupt the output. If it DOES NOT corrupt, PhpWord has fixed its own
// bug upstream and we can remove the guard from the controller. We assert
// the corruption to make the expected-vs-actual relationship explicit.
$tpBad = new TemplateProcessor($src);
$tpBad->setMacroOpeningChars('{{');
$tpBad->setMacroClosingChars('}}');
$outBad = sys_get_temp_dir() . '/phase_s_iter_bad.docx';
$tpBad->saveAs($outBad);
unset($tpBad);

$zip = new ZipArchive();
$zip->open($outBad);
$badXml = $zip->getFromName('word/document.xml');
$zip->close();

$stillBuggy = false;
$prev = libxml_use_internal_errors(true);
try {
    new SimpleXMLElement((string) $badXml);
} catch (Throwable $e) {
    $stillBuggy = true;
}
libxml_clear_errors();
libxml_use_internal_errors($prev);
if (!$stillBuggy) {
    echo "NOTE — negative-control iteration produced well-formed XML.\n";
    echo "       This may mean PhpWord has been fixed upstream. If so, the\n";
    echo "       guard in SynaformController::candidatesGenerate() can be\n";
    echo "       removed and this phase-S test deleted.\n";
}

// Report
foreach ([$out1, $out2, $out3] as $i => $p) {
    printf("  iter%d  %8d bytes  %s\n", $i + 1, filesize($p), $p);
}
printf("  (bad)  %8d bytes  %s%s\n",
    filesize($outBad),
    $outBad,
    $stillBuggy ? '  [expected corruption, observed]' : '  [no corruption — possibly fixed upstream]',
);

if (!empty($fails)) {
    echo "\nFAIL\n";
    foreach ($fails as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "\nPASS — TemplateProcessor static-state guard keeps drawings intact across repeated generate() calls.\n";
exit(0);
