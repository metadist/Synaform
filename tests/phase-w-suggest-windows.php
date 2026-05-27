<?php

declare(strict_types=1);

/**
 * Phase W regression test — `splitDocumentIntoWindows()` must walk the WHOLE
 * document, not just the first page.
 *
 * The bug it guards against:
 *   Before this test, `templatesAiSuggestFromDocx()` called
 *   `clipDocumentForPrompt($sourceText, 22000, 6000)` for the proposal
 *   stage and then asked the AI for "between 6 and 12 entries". With
 *   per-doc-type priority-var hints biasing the model toward
 *   first-page values (name, address, dates, title), the model almost
 *   always returned 6–12 variables drawn entirely from the document's
 *   first ~3 pages — even on long contracts, multi-page CVs, and
 *   filled-in profile documents. Multi-pass top-ups did not help
 *   because each pass saw the same head+tail slice.
 *
 *   The fix splits the source text into overlapping windows on
 *   paragraph boundaries and runs one AI proposal call per window,
 *   so every page of the document is actually scanned.
 *
 * This test exercises the splitter (which is the production logic
 * inlined below — `SynaformController::splitDocumentIntoWindows()` is
 * private) against four scenarios:
 *   1. Short doc            — must return exactly one window.
 *   2. Medium multi-page    — must return ≥2 overlapping windows.
 *   3. Every paragraph hit  — must be visible in at least one window.
 *   4. Runaway long doc     — must respect `$maxWindows` and tail-preserve.
 *
 * Run:
 *   php tests/phase-w-suggest-windows.php
 *
 * Exit code: 0 on pass, non-zero on regression.
 */

$fails = [];

// ---------------------------------------------------------------------------
// Inlined production splitter — mirror of
// SynaformController::splitDocumentIntoWindows().
// Keep the two in sync if you touch either side.
// ---------------------------------------------------------------------------

$splitDocumentIntoWindows = static function (
    string $text,
    int $windowChars = 18000,
    int $overlapChars = 2000,
    int $maxWindows = 12,
): array {
    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    if ($len === 0) {
        return [];
    }
    if ($len <= $windowChars) {
        return [$text];
    }

    $paragraphOffsets = [0];
    $offset = 0;
    $hasMb = function_exists('mb_strpos');
    while (true) {
        $next = $hasMb ? mb_strpos($text, "\n", $offset) : strpos($text, "\n", $offset);
        if ($next === false) {
            break;
        }
        $paragraphOffsets[] = $next + 1;
        $offset = $next + 1;
    }
    $paragraphOffsets[] = $len;
    $totalBreaks = count($paragraphOffsets);

    $windows = [];
    $cursor = 0;
    $lastEnd = 0;
    while ($cursor < $len && count($windows) < $maxWindows) {
        $rawEnd = min($cursor + $windowChars, $len);
        $end = $rawEnd;
        for ($i = $totalBreaks - 1; $i >= 0; $i--) {
            $candidate = $paragraphOffsets[$i];
            if ($candidate <= $rawEnd && $candidate > $cursor + 1000) {
                $end = $candidate;
                break;
            }
        }
        $slice = $hasMb
            ? mb_substr($text, $cursor, $end - $cursor)
            : substr($text, $cursor, $end - $cursor);
        $windows[] = $slice;
        $lastEnd = $end;
        if ($end >= $len) {
            break;
        }
        $cursor = max($end - $overlapChars, $cursor + 1);
    }

    if ($lastEnd < $len) {
        $tailStart = max($len - $windowChars, $lastEnd - $overlapChars);
        $tail = $hasMb
            ? mb_substr($text, $tailStart, $len - $tailStart)
            : substr($text, $tailStart, $len - $tailStart);
        $windows[count($windows) - 1] = $tail;
    }

    return $windows;
};

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

/**
 * Build a synthetic "multi-page" document made of N paragraphs of roughly
 * the requested character length, each starting with a unique sentinel
 * ("§§MARKER-<idx>§§") so we can verify every paragraph reached at least
 * one window.
 */
$buildDoc = static function (int $paragraphs, int $approxParaLen = 400): string {
    $lorem = 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua ut enim ad minim veniam quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. ';
    $out = [];
    while (count($out) < $paragraphs) {
        $idx = count($out) + 1;
        $marker = "§§MARKER-{$idx}§§";
        $body = $marker . ' ';
        while (strlen($body) < $approxParaLen) {
            $body .= $lorem;
        }
        $out[] = $body;
    }
    return implode("\n", $out);
};

// ---------------------------------------------------------------------------
// Test 1 — short doc returns exactly one window, unmodified.
// ---------------------------------------------------------------------------

$shortDoc = $buildDoc(5, 200);
$shortLen = strlen($shortDoc);
$windowsShort = $splitDocumentIntoWindows($shortDoc);
if (count($windowsShort) !== 1) {
    $fails[] = sprintf(
        'short doc (%d chars) should produce exactly 1 window; got %d',
        $shortLen,
        count($windowsShort),
    );
}
if ($windowsShort && $windowsShort[0] !== $shortDoc) {
    $fails[] = 'short doc must be returned unmodified as a single window';
}

// ---------------------------------------------------------------------------
// Test 2 — medium multi-page doc returns multiple overlapping windows.
//
// ~60 paragraphs × 400 chars ≈ 24 KB → 2 windows of 18 KB each.
// ---------------------------------------------------------------------------

$mediumDoc = $buildDoc(60, 400);
$mediumLen = strlen($mediumDoc);
$windowsMedium = $splitDocumentIntoWindows($mediumDoc);
if (count($windowsMedium) < 2) {
    $fails[] = sprintf(
        'medium doc (%d chars) must produce ≥2 windows; got %d',
        $mediumLen,
        count($windowsMedium),
    );
}

// Windows must overlap: the END of window N must appear at the START of
// window N+1 (modulo paragraph-boundary nudging — we check by looking for
// a chunk of window N's tail inside window N+1's head).
for ($i = 0; $i < count($windowsMedium) - 1; $i++) {
    $tailOfThis = substr($windowsMedium[$i], -800);
    $headOfNext = substr($windowsMedium[$i + 1], 0, 4000);
    // The first 400 chars of $tailOfThis should appear somewhere in
    // $headOfNext for the overlap to be real.
    $probe = substr($tailOfThis, 0, 400);
    if (strpos($headOfNext, $probe) === false) {
        $fails[] = sprintf(
            'windows %d and %d do not overlap (probe from window %d tail not found in window %d head)',
            $i,
            $i + 1,
            $i,
            $i + 1,
        );
        break;
    }
}

// ---------------------------------------------------------------------------
// Test 3 — every paragraph appears in at least one window.
//
// This is the headline assertion. Pre-fix, the proposal stage only ever saw
// the head+tail slice — paragraphs in the middle of long docs were invisible
// to the AI. After the fix, every paragraph MUST land in some window.
// ---------------------------------------------------------------------------

$paragraphCount = 60;
$missing = [];
for ($idx = 1; $idx <= $paragraphCount; $idx++) {
    $marker = "§§MARKER-{$idx}§§";
    $found = false;
    foreach ($windowsMedium as $w) {
        if (strpos($w, $marker) !== false) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $missing[] = $marker;
    }
}
if (!empty($missing)) {
    $fails[] = sprintf(
        'every paragraph must appear in some window; missing %d markers (first few: %s)',
        count($missing),
        implode(', ', array_slice($missing, 0, 5)),
    );
}

// ---------------------------------------------------------------------------
// Test 4 — runaway long doc respects $maxWindows AND tail is preserved.
//
// 600 paragraphs × 400 chars ≈ 240 KB. With $windowChars=18000 +
// $maxWindows=12 we'd nominally cover ~216 KB; the last ~24 KB would be
// invisible without the tail-replacement. The fix replaces the final
// window with a tail slice so the signature block stays visible.
// ---------------------------------------------------------------------------

$longDoc = $buildDoc(600, 400);
$longLen = strlen($longDoc);
$windowsLong = $splitDocumentIntoWindows($longDoc);
if (count($windowsLong) !== 12) {
    $fails[] = sprintf(
        'long doc (%d chars) must respect maxWindows=12; got %d windows',
        $longLen,
        count($windowsLong),
    );
}

// The very last paragraph marker (the doc "signature") MUST appear in the
// last window — that's the whole point of tail-replacement on runaway docs.
$lastMarker = "§§MARKER-600§§";
$lastWindow = end($windowsLong);
if (!is_string($lastWindow) || strpos($lastWindow, $lastMarker) === false) {
    $fails[] = sprintf(
        'tail of long doc must be visible in the last window — marker %s not found',
        $lastMarker,
    );
}

// First-window content must still anchor on the document head so the
// classifier's per-doc-type hints still match document opening.
if (!isset($windowsLong[0]) || strpos($windowsLong[0], "§§MARKER-1§§") === false) {
    $fails[] = 'head of long doc must be visible in the first window';
}

// ---------------------------------------------------------------------------
// Test 5 — empty input returns empty array (defensive).
// ---------------------------------------------------------------------------

if ($splitDocumentIntoWindows('') !== []) {
    $fails[] = 'empty source text must produce zero windows';
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

if (empty($fails)) {
    printf("PASS — phase W: splitDocumentIntoWindows walks the WHOLE document, not just page 1.\n");
    printf("  - short doc (%d chars) → 1 window\n", $shortLen);
    printf("  - medium doc (%d chars) → %d windows, overlapping\n", $mediumLen, count($windowsMedium));
    printf("  - every one of %d paragraphs reached at least one window\n", $paragraphCount);
    printf("  - long doc (%d chars) → %d windows, head + signature both visible\n", $longLen, count($windowsLong));
    exit(0);
}

fwrite(STDERR, "FAIL — phase W regression detected:\n");
foreach ($fails as $f) {
    fwrite(STDERR, "  - $f\n");
}
exit(1);
