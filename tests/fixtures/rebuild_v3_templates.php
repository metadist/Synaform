<?php

declare(strict_types=1);

/**
 * Rebuild the "V3" target templates from the "final" V2 originals in
 * /wwwroot/hhff/word-files/*.docx.
 *
 * Why this script and not editing in Word:
 *   - Word splits {{placeholder}} across multiple <w:r> runs (often 2-6 runs
 *     per placeholder, depending on autocorrect history). Renaming by hand in
 *     Word is error-prone.
 *   - We want the V3 build to be reproducible and diffable.
 *
 * What it does:
 *   1. Unzip each source DOCX into a temp dir.
 *   2. For every <w:p> paragraph that contains a {{...}} we want to rewrite:
 *      a. Concatenate all <w:t> inner texts inside the paragraph.
 *      b. Apply the rename map (OLD → NEW).
 *      c. Put the rewritten text entirely into the FIRST <w:t>, empty the
 *         rest. Paragraphs that contain placeholders are always uniformly
 *         styled (the AI doesn't bold a placeholder name inline), so we don't
 *         lose visible formatting.
 *   3. Repack the DOCX into /wwwroot/hhff/word-files/v3/.
 *
 * Usage:
 *   php tests/fixtures/rebuild_v3_templates.php
 *
 * The script is idempotent; re-run to regenerate v3 files from scratch.
 */

$SOURCES = [
    [
        'src' => '/wwwroot/hhff/word-files/Profil hhff Deutsch Ralf final.docx',
        'dst' => '/wwwroot/hhff/word-files/v3/Profil hhff DE v3.docx',
        'variant' => 'hhff',
    ],
    [
        'src' => '/wwwroot/hhff/word-files/Profile hhff englisch Ralf final.docx',
        'dst' => '/wwwroot/hhff/word-files/v3/Profil hhff EN v3.docx',
        'variant' => 'hhff',
    ],
    [
        'src' => '/wwwroot/hhff/word-files/Profil (Needle  Haystack) Deutsch Ralf final.docx',
        'dst' => '/wwwroot/hhff/word-files/v3/Profil NeedleHaystack DE v3.docx',
        'variant' => 'nh',
    ],
    [
        'src' => '/wwwroot/hhff/word-files/Profil (Needle  Haystack) English Ralf final.docx',
        'dst' => '/wwwroot/hhff/word-files/v3/Profil NeedleHaystack EN v3.docx',
        'variant' => 'nh',
    ],
];

/**
 * Every list variable must live in a canonical bullet paragraph so the generator's
 * list-expansion pass produces proper bullets instead of stacked prose or — worse —
 * cloned heading lines. We fix that at build time by copying the `<w:pPr>` from
 * the template's own known-good `{{benefits}}` paragraph onto every other list
 * placeholder's host paragraph. Benefits is chosen because both V2 originals got
 * it right (N&H uses `pStyle=Bulletpoints`, hhff uses `Listenabsatz` + numPr).
 *
 * Keys are the V3 variable names (post-rename).
 */
$LIST_KEYS = [
    'benefits',
    'languages',
    'other_skills',
    'relevant_positions',
    'relevant_positions_for_target',
    'education',
];

/**
 * Placeholder rename map applied to BOTH variants.
 *
 * Order matters: longer keys first so `stations.positions.N` is rewritten
 * before a generic `positions` key could collide (none today, but future-proof).
 *
 * The map uses raw placeholder names (without braces). We add braces at
 * substitution time.
 */
$COMMON_MAP = [
    // Stations rename (N&H only; harmless no-op on hhff)
    'stations.positions.N'       => 'stations.position.N',

    // List suffix cleanup
    'relevantfortargetposlist'   => 'relevant_positions_for_target',
    'relevantposlist'            => 'relevant_positions',
    'otherskillslist'            => 'other_skills',
    'languageslist'              => 'languages',
    'benefitslist'               => 'benefits',

    // Naming cleanups
    'currentansalary'            => 'current_annual_salary',
    'currentposition'            => 'current_position',
    'noticeperiod'               => 'notice_period',
    'workinghours'               => 'working_hours',
    'target-position'            => 'target_position',

    // Address reshape
    'address1'                   => 'street',
    'address2'                   => 'city',

    // Renames
    'number'                     => 'phone',
    'month'                      => 'generated_month',
    'year'                       => 'generated_year',

    // hhff-only "travelorcommute" → "commute" (we drop the "travel" side from hhff
    // because the original text only had one combined value; ANALYSIS-v3.md
    // documents this as a lossy rename).
    'travelorcommute'            => 'commute',
];

foreach ($SOURCES as $job) {
    rebuildTemplate($job['src'], $job['dst'], $COMMON_MAP, $LIST_KEYS);
}

fprintf(STDOUT, "\nV3 build complete.\n");

// -------------------------------------------------------------------------

function rebuildTemplate(string $srcPath, string $dstPath, array $renameMap, array $listKeys): void
{
    if (!is_file($srcPath)) {
        fprintf(STDERR, "SKIP %s (not found)\n", $srcPath);
        return;
    }

    $tmpDir = sys_get_temp_dir() . '/tx-v3-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0o777, true);

    $zip = new ZipArchive();
    if ($zip->open($srcPath) !== true) {
        fprintf(STDERR, "FAIL to open %s\n", $srcPath);
        return;
    }
    $zip->extractTo($tmpDir);
    $zip->close();

    $docXml = $tmpDir . '/word/document.xml';
    if (!is_file($docXml)) {
        fprintf(STDERR, "FAIL: no word/document.xml in %s\n", $srcPath);
        return;
    }

    $xml = file_get_contents($docXml);
    [$newXml, $stats] = rewritePlaceholders($xml, $renameMap);
    [$newXml, $listStats] = normalizeListParagraphs($newXml, $listKeys);
    file_put_contents($docXml, $newXml);
    $stats['list_paragraphs_normalised'] = $listStats['normalised'];
    $stats['list_pPr_source'] = $listStats['source'];

    if (!is_dir(dirname($dstPath))) {
        mkdir(dirname($dstPath), 0o777, true);
    }
    if (file_exists($dstPath)) {
        unlink($dstPath);
    }

    $out = new ZipArchive();
    if ($out->open($dstPath, ZipArchive::CREATE) !== true) {
        fprintf(STDERR, "FAIL to write %s\n", $dstPath);
        return;
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($rii as $file) {
        if ($file->isDir()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($tmpDir) + 1);
        $rel = str_replace('\\', '/', $rel);
        $out->addFile($file->getPathname(), $rel);
    }
    $out->close();

    // Cleanup
    removeDir($tmpDir);

    $name = basename($dstPath);
    fprintf(
        STDOUT,
        "OK  %-38s  renamed=%d paragraphs=%d list_paras_normalised=%d (pPr from %s)\n",
        $name,
        $stats['placeholders_renamed'],
        $stats['paragraphs_rewritten'],
        $stats['list_paragraphs_normalised'],
        $stats['list_pPr_source'] ?? 'none',
    );
}

/**
 * Normalise every list placeholder's host paragraph to use a canonical bullet
 * paragraph style: clone the `<w:pPr>` from the paragraph that hosts `{{benefits}}`
 * (both V2 originals ship benefits as a correctly-styled bullet paragraph) and
 * drop that pPr onto every other list placeholder's paragraph.
 *
 * Why this matters: the generator's Phase A pre-pass (expandListParagraphs)
 * clones the host paragraph once per list item. If the host isn't a bullet
 * paragraph — plain prose or a Heading/Titel style — every item renders without
 * a bullet (or as a huge title). Forcing a known-good pPr here gives us a
 * consistent list rendering across all list variables without depending on the
 * original template author's discipline.
 *
 * @param string       $xml      full document.xml (already placeholder-renamed)
 * @param list<string> $listKeys V3 list variable names (without braces)
 * @return array{0: string, 1: array{normalised: int, source: string}}
 */
function normalizeListParagraphs(string $xml, array $listKeys): array
{
    // 1. Find the benefits paragraph and extract its <w:pPr>.
    //    The placeholder may be split across runs at this point too, so we
    //    use the same per-paragraph flat-text scan that the controller uses.
    $benefitsPPr = null;
    $paraPattern = '~<w:p\b[^>]*>.*?</w:p>~s';
    if (preg_match_all($paraPattern, $xml, $paras, PREG_OFFSET_CAPTURE)) {
        foreach ($paras[0] as [$paraXml, $paraOff]) {
            $flat = '';
            if (preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $tm)) {
                $flat = implode('', $tm[1]);
            }
            if (strpos($flat, '{{benefits}}') !== false) {
                if (preg_match('~<w:pPr\b[^>]*>.*?</w:pPr>~s', $paraXml, $pprMatch)) {
                    $benefitsPPr = $pprMatch[0];
                }
                break;
            }
        }
    }

    if ($benefitsPPr === null) {
        return [$xml, ['normalised' => 0, 'source' => 'none (no {{benefits}} paragraph found)']];
    }

    // 2. For every list key that isn't benefits, locate its host paragraph and
    //    replace that paragraph's <w:pPr> with the canonical one. If the host
    //    has no <w:pPr> at all, inject one right after <w:p …>.
    $normalised = 0;
    $needles = [];
    foreach ($listKeys as $k) {
        if ($k === 'benefits') {
            continue;
        }
        $needles[$k] = '{{' . $k . '}}';
    }

    $xml = preg_replace_callback(
        $paraPattern,
        function (array $m) use ($needles, $benefitsPPr, &$normalised): string {
            $paraXml = $m[0];

            $flat = '';
            if (preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $paraXml, $tm)) {
                $flat = implode('', $tm[1]);
            }
            $hit = null;
            foreach ($needles as $k => $needle) {
                if (strpos($flat, $needle) !== false) {
                    $hit = $k;
                    break;
                }
            }
            if ($hit === null) {
                return $paraXml;
            }

            // Rewrite or inject <w:pPr>.
            if (preg_match('~<w:pPr\b[^>]*>.*?</w:pPr>~s', $paraXml)) {
                $rewritten = preg_replace(
                    '~<w:pPr\b[^>]*>.*?</w:pPr>~s',
                    addcslashes($benefitsPPr, '\\$'),
                    $paraXml,
                    1,
                );
            } else {
                $rewritten = preg_replace(
                    '~(<w:p\b[^>]*>)~',
                    '$1' . addcslashes($benefitsPPr, '\\$'),
                    $paraXml,
                    1,
                );
            }
            if (is_string($rewritten) && $rewritten !== $paraXml) {
                $normalised++;
                return $rewritten;
            }
            return $paraXml;
        },
        $xml,
    );

    return [$xml, ['normalised' => $normalised, 'source' => '{{benefits}} host paragraph']];
}

/**
 * @param string                $xml       full document.xml
 * @param array<string, string> $renameMap OLD key → NEW key (without braces)
 * @return array{0: string, 1: array{paragraphs_rewritten: int, placeholders_renamed: int}}
 */
function rewritePlaceholders(string $xml, array $renameMap): array
{
    $paragraphsRewritten = 0;
    $renameCount = 0;

    // Iterate <w:p>...</w:p> blocks. Regex is sufficient because they don't
    // legally nest inside each other in a flowing document.
    $xml = preg_replace_callback(
        '~<w:p\b[^>]*>.*?</w:p>~s',
        function (array $m) use ($renameMap, &$paragraphsRewritten, &$renameCount): string {
            $paragraph = $m[0];

            // Gather <w:t>...</w:t> nodes in document order.
            if (!preg_match_all('~<w:t(?P<attrs>[^>]*)>(?P<text>[^<]*)</w:t>~', $paragraph, $tMatches, PREG_OFFSET_CAPTURE)) {
                return $paragraph;
            }

            $runs = [];
            $flat = '';
            foreach ($tMatches[0] as $i => [$full, $off]) {
                $text = $tMatches['text'][$i][0];
                $attrs = $tMatches['attrs'][$i][0];
                $runs[] = [
                    'full'        => $full,
                    'offset'      => $off,
                    'length'      => strlen($full),
                    'attrs'       => $attrs,
                    'inner'       => $text,
                    'flat_start'  => strlen($flat),
                    'flat_length' => strlen($text),
                ];
                $flat .= $text;
            }

            // Quick skip: no placeholder in this paragraph at all.
            if (strpos($flat, '{{') === false) {
                return $paragraph;
            }

            // Apply rename map to flat text.
            $newFlat = $flat;
            $touched = false;
            foreach ($renameMap as $old => $new) {
                $needle = '{{' . $old . '}}';
                $replacement = '{{' . $new . '}}';
                $count = 0;
                $newFlat = str_replace($needle, $replacement, $newFlat, $count);
                if ($count > 0) {
                    $touched = true;
                    $renameCount += $count;
                }
            }

            if (!$touched) {
                return $paragraph;
            }
            $paragraphsRewritten++;

            // Drop the whole rewritten text into the FIRST <w:t>, empty the rest.
            $replaced = [];
            foreach ($runs as $i => $r) {
                if ($i === 0) {
                    // Always keep xml:space="preserve" so trailing/leading spaces survive.
                    $attrs = $r['attrs'];
                    if (!preg_match('~\sxml:space\s*=~', $attrs)) {
                        $attrs .= ' xml:space="preserve"';
                    }
                    $replaced[] = '<w:t' . $attrs . '>' . htmlspecialchars($newFlat, ENT_XML1 | ENT_QUOTES) . '</w:t>';
                } else {
                    $replaced[] = '<w:t' . $r['attrs'] . '></w:t>';
                }
            }

            // Rebuild paragraph by replacing each original <w:t> with its new version.
            // Walk from end so offsets in $runs stay valid.
            $newParagraph = $paragraph;
            for ($i = count($runs) - 1; $i >= 0; $i--) {
                $r = $runs[$i];
                $newParagraph = substr($newParagraph, 0, $r['offset']) . $replaced[$i] . substr($newParagraph, $r['offset'] + $r['length']);
            }

            return $newParagraph;
        },
        $xml,
    );

    return [$xml, ['paragraphs_rewritten' => $paragraphsRewritten, 'placeholders_renamed' => $renameCount]];
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
