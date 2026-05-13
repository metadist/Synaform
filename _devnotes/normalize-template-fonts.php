<?php

declare(strict_types=1);

/**
 * Standalone CLI: bake explicit `<w:rFonts>` / `<w:sz>` / `<w:szCs>` into
 * every placeholder-bearing run inside a target .docx so the file itself is
 * font-clean, even when consumed outside Synaplan (e.g. opened in Word for
 * manual editing, or fed through a different DOCX-templating engine).
 *
 * This is a thin wrapper around the same `normalizePlaceholderRunFonts` pass
 * that `cleanTemplateMacros` runs inside the controller — kept in this
 * private dev folder so customers can re-bake their own templates without
 * round-tripping through the platform.
 *
 * Why this exists:
 *   - `cleanTemplateMacros` already does the right thing at upload/generate
 *     time, so files newly uploaded to Synaplan are normalized on the fly.
 *   - However, customers also keep the source .docx in their own private
 *     repos (e.g. /wwwroot/hhff/word-files/v4/). Running this script once
 *     after each manual edit in Word makes the on-disk source itself
 *     "PhpWord-safe" so nobody has to remember the cleanup step.
 *
 * The script is idempotent: re-running it on an already-normalized template
 * leaves it byte-identical (the regex only adds rPr children that are
 * missing).
 *
 * Usage:
 *   php normalize-template-fonts.php <docx> [<docx> ...]
 *
 * Behaviour:
 *   - The original file is overwritten in place.
 *   - A `.bak` copy is created next to it on first run (preserved on
 *     re-runs — we never overwrite an existing .bak).
 *   - The script writes a one-line per file report to stdout
 *     (paragraphs touched, runs gained explicit fonts).
 *
 * NOTE on PII: this script never inspects the actual *text* inside `<w:t>`,
 * only the run-property metadata. It's safe to run on private customer
 * .docx files; nothing the script reads or writes leaves the filesystem.
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: php normalize-template-fonts.php <docx> [<docx> ...]\n");
    exit(2);
}

foreach (array_slice($argv, 1) as $path) {
    if (!is_file($path)) {
        fprintf(STDERR, "SKIP %s — not a file\n", $path);
        continue;
    }

    // Take a one-shot backup before the first overwrite.
    $bak = $path . '.bak';
    if (!file_exists($bak)) {
        copy($path, $bak);
    }

    $report = normalizeDocxFonts($path);
    printf(
        "OK  %-50s touched_paragraphs=%d gained_rfonts=%d already_explicit=%d doc_dominant_font=%s\n",
        basename($path),
        $report['paragraphs_touched'],
        $report['runs_gained_rfonts'],
        $report['runs_already_explicit'],
        $report['document_dominant_font'] ?: '(none — no signal)'
    );
}

/**
 * Open a .docx, run the same normalization the SynaformController does in
 * `cleanTemplateMacros`, and write it back. Returns a small stats array.
 *
 * @return array{
 *     paragraphs_touched: int,
 *     runs_gained_rfonts: int,
 *     runs_already_explicit: int,
 *     document_dominant_font: string,
 * }
 */
function normalizeDocxFonts(string $docxPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($docxPath) !== true) {
        fprintf(STDERR, "FAIL to open %s\n", $docxPath);
        return [
            'paragraphs_touched' => 0,
            'runs_gained_rfonts' => 0,
            'runs_already_explicit' => 0,
            'document_dominant_font' => '',
        ];
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        return [
            'paragraphs_touched' => 0,
            'runs_gained_rfonts' => 0,
            'runs_already_explicit' => 0,
            'document_dominant_font' => '',
        ];
    }

    $documentWide = detectDocumentDominantRunStyle($xml);
    $dominantFontHuman = '';
    if ($documentWide['rFonts'] !== ''
        && preg_match('~w:ascii="([^"]+)"~', $documentWide['rFonts'], $fm)) {
        $dominantFontHuman = $fm[1];
    }

    $paragraphsTouched = 0;
    $runsGained = 0;
    $runsAlreadyExplicit = 0;

    $rewritten = preg_replace_callback(
        '#<w:p\b[^>]*>(?:(?!</w:p>).)*?</w:p>#s',
        function (array $m) use ($documentWide, &$paragraphsTouched, &$runsGained, &$runsAlreadyExplicit): string {
            $paraXml = $m[0];
            if (strpos($paraXml, '{{') === false) {
                return $paraXml;
            }

            $dominant = detectParagraphDominantRunStyle($paraXml);
            if ($dominant['rFonts'] === '' && $documentWide['rFonts'] !== '') {
                $dominant['rFonts'] = $documentWide['rFonts'];
            }
            if ($dominant['sz'] === '' && $documentWide['sz'] !== '') {
                $dominant['sz'] = $documentWide['sz'];
            }
            if ($dominant['szCs'] === '' && $documentWide['szCs'] !== '') {
                $dominant['szCs'] = $documentWide['szCs'];
            }
            if ($dominant['rFonts'] === '' && $dominant['sz'] === '' && $dominant['szCs'] === '') {
                return $paraXml;
            }

            $touched = false;
            $newPara = preg_replace_callback(
                '#<w:r\b[^>]*>(?:(?!</w:r>).)*?</w:r>#s',
                function (array $rm) use ($dominant, &$runsGained, &$runsAlreadyExplicit, &$touched): string {
                    $runXml = $rm[0];
                    if (preg_match('#<w:rPr\b[^>]*>(.*?)</w:rPr>#s', $runXml, $rprm)) {
                        if (preg_match('#<w:rFonts\b#', $rprm[1])) {
                            $runsAlreadyExplicit++;
                            return $runXml;
                        }
                    }
                    $newRun = ensureRunHasFont($runXml, $dominant);
                    if ($newRun !== $runXml) {
                        $runsGained++;
                        $touched = true;
                    }
                    return $newRun;
                },
                $paraXml
            ) ?? $paraXml;

            if ($touched) {
                $paragraphsTouched++;
            }
            return $newPara;
        },
        $xml
    );

    if (is_string($rewritten) && $rewritten !== $xml) {
        $zip->addFromString('word/document.xml', $rewritten);
    }
    $zip->close();

    return [
        'paragraphs_touched' => $paragraphsTouched,
        'runs_gained_rfonts' => $runsGained,
        'runs_already_explicit' => $runsAlreadyExplicit,
        'document_dominant_font' => $dominantFontHuman,
    ];
}

/**
 * Same logic as SynaformController::detectDominantRunStyle.
 *
 * @return array{rFonts: string, sz: string, szCs: string}
 */
function detectParagraphDominantRunStyle(string $paraXml): array
{
    $rFonts = '';
    $sz = '';
    $szCs = '';
    if (preg_match_all('#<w:r\b[^>]*>(.*?)</w:r>#s', $paraXml, $rm)) {
        foreach ($rm[1] as $runInner) {
            if (!preg_match('#<w:rPr\b[^>]*>(.*?)</w:rPr>#s', $runInner, $rprm)) {
                continue;
            }
            $rPrInner = $rprm[1];
            if ($rFonts === '' && preg_match('#<w:rFonts\b[^>]*/?>#', $rPrInner, $fm)) {
                $rFonts = $fm[0];
            }
            if ($sz === '' && preg_match('#<w:sz\b[^>]*/?>#', $rPrInner, $sm)) {
                $sz = $sm[0];
            }
            if ($szCs === '' && preg_match('#<w:szCs\b[^>]*/?>#', $rPrInner, $sm2)) {
                $szCs = $sm2[0];
            }
            if ($rFonts !== '' && $sz !== '' && $szCs !== '') {
                break;
            }
        }
    }
    return ['rFonts' => $rFonts, 'sz' => $sz, 'szCs' => $szCs];
}

/**
 * Same logic as SynaformController::detectDocumentDominantRunStyle.
 *
 * @return array{rFonts: string, sz: string, szCs: string}
 */
function detectDocumentDominantRunStyle(string $xml): array
{
    $fontTallies = [];
    $szTallies = [];
    $szCsTallies = [];
    if (preg_match_all('#<w:r\b[^>]*>(?:(?!</w:r>).)*?</w:r>#s', $xml, $rm)) {
        foreach ($rm[0] as $runXml) {
            if (!preg_match('#<w:rPr\b[^>]*>(.*?)</w:rPr>#s', $runXml, $rprm)) {
                continue;
            }
            $rPrInner = $rprm[1];
            if (preg_match('#<w:rFonts\b[^>]*/?>#', $rPrInner, $fm)) {
                $tag = $fm[0];
                if (strpos($tag, 'w:ascii=') !== false || strpos($tag, 'w:hAnsi=') !== false) {
                    $fontTallies[$tag] = ($fontTallies[$tag] ?? 0) + 1;
                }
            }
            if (preg_match('#<w:sz\b[^>]*/?>#', $rPrInner, $sm)) {
                $szTallies[$sm[0]] = ($szTallies[$sm[0]] ?? 0) + 1;
            }
            if (preg_match('#<w:szCs\b[^>]*/?>#', $rPrInner, $sm2)) {
                $szCsTallies[$sm2[0]] = ($szCsTallies[$sm2[0]] ?? 0) + 1;
            }
        }
    }
    return [
        'rFonts' => topTallyKey($fontTallies),
        'sz'     => topTallyKey($szTallies),
        'szCs'   => topTallyKey($szCsTallies),
    ];
}

function topTallyKey(array $tallies): string
{
    if (empty($tallies)) {
        return '';
    }
    arsort($tallies);
    return (string) array_key_first($tallies);
}

/**
 * Same logic as SynaformController::ensureRunHasFont.
 *
 * @param array{rFonts: string, sz: string, szCs: string} $dominant
 */
function ensureRunHasFont(string $runXml, array $dominant): string
{
    if (preg_match('#<w:rPr\b[^>]*>(.*?)</w:rPr>#s', $runXml, $rprm)) {
        $rPrFull = $rprm[0];
        $rPrInner = $rprm[1];
        $needRFonts = $dominant['rFonts'] !== '' && !preg_match('#<w:rFonts\b#', $rPrInner);
        $needSz     = $dominant['sz']     !== '' && !preg_match('#<w:sz\b#', $rPrInner);
        $needSzCs   = $dominant['szCs']   !== '' && !preg_match('#<w:szCs\b#', $rPrInner);
        if (!$needRFonts && !$needSz && !$needSzCs) {
            return $runXml;
        }
        $injection = '';
        if ($needRFonts) {
            $injection .= $dominant['rFonts'];
        }
        if ($needSz) {
            $injection .= $dominant['sz'];
        }
        if ($needSzCs) {
            $injection .= $dominant['szCs'];
        }
        $newRPr = preg_replace(
            '#<w:rPr\b[^>]*>.*?</w:rPr>#s',
            '<w:rPr>' . $injection . $rPrInner . '</w:rPr>',
            $rPrFull,
            1
        ) ?? $rPrFull;
        return str_replace($rPrFull, $newRPr, $runXml);
    }

    $injection = '';
    if ($dominant['rFonts'] !== '') {
        $injection .= $dominant['rFonts'];
    }
    if ($dominant['sz'] !== '') {
        $injection .= $dominant['sz'];
    }
    if ($dominant['szCs'] !== '') {
        $injection .= $dominant['szCs'];
    }
    if ($injection === '') {
        return $runXml;
    }
    return preg_replace(
        '#<w:r\b([^>]*)>#',
        '<w:r$1><w:rPr>' . addcslashes($injection, '\\$') . '</w:rPr>',
        $runXml,
        1
    ) ?? $runXml;
}
