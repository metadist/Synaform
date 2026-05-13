<?php

declare(strict_types=1);

/**
 * Template-rendering benchmark for Synaform v4 target templates.
 *
 * What it does:
 *   1. Boot the live Symfony kernel inside the synaplan-backend container.
 *   2. For each input .docx (passed as CLI arg, default = the two v4 templates
 *      copied into the container at /tmp/v4/), drive the FULL generation
 *      pipeline:
 *        - cleanTemplateMacros() — including the new font-bake pass
 *        - expandTableBlocks(), expandListParagraphs(), cloneParagraphGroupsPrepass()
 *        - PhpWord TemplateProcessor + processRowGroups / processCheckboxes /
 *          processLists / processImages / processScalars
 *        - expandRichRowColumns() (the bullet/date/title renderer for
 *          stations.details)
 *        - applyTableLayoutHelpers() + convertCheckboxMarkersToContentControls()
 *   3. Inspect the resulting DOCX:
 *        - any leftover {{placeholder}}? — would mean we did not fill a variable
 *        - every <w:r> that contributes visible text: does it carry an
 *          <w:rFonts> in its rPr (directly or via its <w:rStyle>)?
 *        - what fonts actually end up in use across the generated runs?
 *   4. Print a markdown report to stdout.
 *
 * NB: Uses *fixture* candidate data — no PII from /wwwroot/hhff. The fixture
 * fully exercises every placeholder shape (scalar, list, checkbox, table,
 * image — image left empty, the placeholder gets safely cleared).
 *
 * Usage (inside the synaplan-backend container):
 *   docker compose exec -T backend php /tmp/synaform-template-bench.php
 *       /tmp/v4/Profil_hhff_DE_v4.docx
 *       /tmp/v4/Profil_NeedleHaystack_DE_v4.docx
 */

require '/var/www/backend/vendor/autoload.php';

$kernel = new App\Kernel('prod', false);
$kernel->boot();
$container = $kernel->getContainer();

$plugin = $container->get('Plugin\\Synaform\\Controller\\SynaformController');
$ref = new ReflectionClass($plugin);

$reflectInvoke = function (string $method, array $args) use ($plugin, $ref) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($plugin, $args);
};

$inputPaths = array_slice($argv, 1);
if (empty($inputPaths)) {
    fwrite(STDERR, "usage: php synaform-template-bench.php <docx> [<docx> ...]\n");
    exit(2);
}

$formFields = [
    ['key' => 'fullname', 'type' => 'text'],
    ['key' => 'birthdate', 'type' => 'text'],
    ['key' => 'nationality', 'type' => 'text'],
    ['key' => 'maritalstatus', 'type' => 'select'],
    ['key' => 'email', 'type' => 'text'],
    ['key' => 'phone', 'type' => 'text'],
    ['key' => 'street', 'type' => 'text'],
    ['key' => 'zip', 'type' => 'text'],
    ['key' => 'city', 'type' => 'text'],
    ['key' => 'target_position', 'type' => 'text'],
    ['key' => 'current_position', 'type' => 'text'],
    ['key' => 'current_annual_salary', 'type' => 'text'],
    ['key' => 'notice_period', 'type' => 'text'],
    ['key' => 'working_hours', 'type' => 'text'],
    ['key' => 'moving', 'type' => 'checkbox', 'designer' => ['yes_label' => 'Ja', 'no_label' => 'Nein']],
    ['key' => 'commute', 'type' => 'checkbox', 'designer' => ['yes_label' => 'Ja', 'no_label' => 'Nein']],
    ['key' => 'travel', 'type' => 'checkbox', 'designer' => ['yes_label' => 'Ja', 'no_label' => 'Nein']],
    ['key' => 'relevant_positions', 'type' => 'list'],
    ['key' => 'relevant_positions_for_target', 'type' => 'list'],
    ['key' => 'benefits', 'type' => 'list'],
    ['key' => 'languages', 'type' => 'list'],
    ['key' => 'other_skills', 'type' => 'list'],
    ['key' => 'education', 'type' => 'list'],
    ['key' => 'generated_month', 'type' => 'text'],
    ['key' => 'generated_year', 'type' => 'text'],
    [
        'key' => 'stations',
        'type' => 'table',
        'columns' => [
            ['key' => 'time', 'type' => 'text'],
            ['key' => 'employer', 'type' => 'text'],
            ['key' => 'position', 'type' => 'text'],
            ['key' => 'details', 'type' => 'list', 'structured' => true],
        ],
    ],
];

$entry = [
    'id' => 'bench_candidate',
    'form_id' => 'bench_form',
    'variables' => [
        'fullname' => 'Alex Beispiel',
        'birthdate' => '01.04.1985',
        'nationality' => 'Deutsch',
        'maritalstatus' => 'verheiratet',
        'email' => 'alex@example.test',
        'phone' => '+49 30 12345678',
        'street' => 'Musterstraße 12',
        'zip' => '10115',
        'city' => 'Berlin',
        'target_position' => 'Head of Marketing',
        'current_position' => 'Marketing Manager',
        'current_annual_salary' => '85.000 €',
        'notice_period' => '3 Monate zum Quartal',
        'working_hours' => '40h / Woche',
        'moving' => true,
        'commute' => true,
        'travel' => false,
        'relevant_positions' => ['Marketing Manager', 'Head of Brand'],
        'relevant_positions_for_target' => ['Marketing Manager'],
        'benefits' => ['Firmenwagen', '30 Urlaubstage', 'BAV'],
        'languages' => ['Deutsch (Muttersprache)', 'English (C1)'],
        'other_skills' => ['HubSpot', 'Adobe Creative Cloud', 'SQL'],
        'education' => ['M.Sc. Marketing — TU Berlin (2012)'],
        'generated_month' => 'Mai',
        'generated_year' => '2026',
        'stations' => [
            [
                'time' => '02/2021 – heute',
                'employer' => 'Beispiel AG',
                'position' => 'Head of Marketing',
                'details' => [
                    '02/2021 – heute',
                    'Head of Marketing',
                    'Team von 5 geleitet',
                    '€2M jährliche Einsparung',
                    'Internationaler Rollout',
                ],
            ],
            [
                'time' => '03/2017 – 01/2021',
                'employer' => 'Vorgänger GmbH',
                'position' => 'Senior Brand Manager',
                'details' => [
                    'Markenrelaunch DACH',
                    'Performance-Kanäle aufgebaut',
                ],
            ],
        ],
    ],
];

$reportLines = [];
$reportLines[] = '# Synaform v4 template generation bench';
$reportLines[] = '';
$reportLines[] = 'Generated: ' . date('Y-m-d H:i:s T');
$reportLines[] = '';

foreach ($inputPaths as $templatePath) {
    $reportLines[] = '## ' . basename($templatePath);
    $reportLines[] = '';

    if (!is_file($templatePath)) {
        $reportLines[] = '- **MISSING** at `' . $templatePath . '` — skipping.';
        $reportLines[] = '';
        continue;
    }

    // Build a working dir so cleanTemplateMacros and friends can write
    // alongside the template without polluting /tmp/v4/.
    $work = sys_get_temp_dir() . '/syf-bench-' . bin2hex(random_bytes(4));
    mkdir($work, 0o777, true);
    $copy = $work . '/template.docx';
    copy($templatePath, $copy);

    // Phase 0: source-template introspection (placeholders, fonts).
    $srcInfo = inspectDocxRuns($copy, /* placeholdersOnly */ true);
    $reportLines[] = '### Source template';
    $reportLines[] = '';
    $reportLines[] = '- Placeholders found (joined per paragraph): ' . count($srcInfo['placeholders']) . ' unique';
    $reportLines[] = '- `<w:r>` runs touching a placeholder: ' . $srcInfo['runs_total']
        . ' (' . $srcInfo['runs_with_font'] . ' with explicit `<w:rFonts>`, '
        . $srcInfo['runs_no_font'] . ' without)';
    if (!empty($srcInfo['fonts_in_placeholder_runs'])) {
        $reportLines[] = '- Fonts on placeholder runs: ' . implode(', ', array_map(
            static fn ($k, $v) => "`$k` ($v)",
            array_keys($srcInfo['fonts_in_placeholder_runs']),
            array_values($srcInfo['fonts_in_placeholder_runs'])
        ));
    }
    $reportLines[] = '';

    // Phase 1: drive cleanTemplateMacros (now including font-bake pass).
    try {
        /** @var string $cleaned */
        $cleaned = $reflectInvoke('cleanTemplateMacros', [$copy]);
    } catch (Throwable $e) {
        $reportLines[] = 'cleanTemplateMacros: **EXCEPTION** ' . get_class($e) . ': ' . $e->getMessage();
        continue;
    }

    $cleanInfo = inspectDocxRuns($cleaned, /* placeholdersOnly */ true);
    $reportLines[] = '### After cleanTemplateMacros (font-bake pass)';
    $reportLines[] = '';
    $reportLines[] = '- `<w:r>` runs touching a placeholder: ' . $cleanInfo['runs_total']
        . ' (' . $cleanInfo['runs_with_font'] . ' with explicit `<w:rFonts>`, '
        . $cleanInfo['runs_no_font'] . ' without)';
    if (!empty($cleanInfo['fonts_in_placeholder_runs'])) {
        $reportLines[] = '- Fonts on placeholder runs: ' . implode(', ', array_map(
            static fn ($k, $v) => "`$k` ($v)",
            array_keys($cleanInfo['fonts_in_placeholder_runs']),
            array_values($cleanInfo['fonts_in_placeholder_runs'])
        ));
    }
    $reportLines[] = '';

    // Phase 2: full generate() pipeline (we mirror what generate() does
    // because the public method needs auth + filesystem state we don't have
    // in a CLI bench).
    try {
        $generated = generateThroughPipeline(
            $reflectInvoke,
            $cleaned,
            $entry,
            $formFields,
            $work . '/generated.docx',
        );
    } catch (Throwable $e) {
        $reportLines[] = 'generate(): **EXCEPTION** ' . get_class($e) . ': ' . $e->getMessage();
        $reportLines[] = '';
        $reportLines[] = '```';
        $reportLines[] = $e->getTraceAsString();
        $reportLines[] = '```';
        continue;
    }

    // Phase 3: introspect the result.
    $genInfo = inspectDocxRuns($generated, /* placeholdersOnly */ false);
    $reportLines[] = '### Generated DOCX (' . round(filesize($generated) / 1024, 1) . ' KB)';
    $reportLines[] = '';
    $reportLines[] = '- Total `<w:r>` runs in body: ' . $genInfo['runs_total'];
    $reportLines[] = '- Runs with explicit `<w:rFonts>` (directly): ' . $genInfo['runs_with_font'];
    $reportLines[] = '- Runs without explicit `<w:rFonts>`: ' . $genInfo['runs_no_font']
        . ' (these inherit from `rPrDefault` / paragraph style)';
    if (!empty($genInfo['fonts_in_runs'])) {
        $reportLines[] = '- Fonts seen across body runs: ' . implode(', ', array_map(
            static fn ($k, $v) => "`$k` ($v)",
            array_keys($genInfo['fonts_in_runs']),
            array_values($genInfo['fonts_in_runs'])
        ));
    }
    $leftover = findLeftoverPlaceholders($generated);
    $reportLines[] = '- Leftover `{{…}}` placeholders in body: '
        . (empty($leftover) ? '**none** ✅' : '**' . count($leftover) . '** — ' . implode(', ', array_map(static fn ($k) => "`$k`", $leftover)));
    $reportLines[] = '';

    // Save the generated file outside the temp dir so a human can open it.
    $persist = '/tmp/synaform-bench-out';
    if (!is_dir($persist)) {
        mkdir($persist, 0o777, true);
    }
    $persistPath = $persist . '/' . pathinfo($templatePath, PATHINFO_FILENAME) . '_filled.docx';
    copy($generated, $persistPath);
    $reportLines[] = '- Inspect with: `docker compose cp synaplan-backend:' . $persistPath
        . ' .`';
    $reportLines[] = '';

    // Cleanup workdir.
    cleanupDir($work);
}

echo implode("\n", $reportLines) . "\n";

// =====================================================================
// helpers
// =====================================================================

/**
 * Reproduce the production `generate()` pipeline for a single (template,
 * candidate) pair — but stop just before the response/persist step.
 * Returns the generated DOCX path.
 *
 * The order matches Controller::generate() exactly so any drift between this
 * bench and production is visible in diff.
 */
function generateThroughPipeline(callable $invoke, string $cleanedPath, array $entry, array $formFields, string $outputPath): string
{
    $variables = $entry['variables'];

    // 1. Strip image-typed values (image fields handled separately).
    foreach ($formFields as $f) {
        if (($f['type'] ?? '') === 'image' && !empty($f['key'])) {
            unset($variables[$f['key']]);
        }
    }
    // 2. Normalise checkbox bool → string label.
    foreach ($formFields as $f) {
        if (($f['type'] ?? '') !== 'checkbox' || empty($f['key'])) {
            continue;
        }
        $k = $f['key'];
        if (!array_key_exists($k, $variables)) {
            continue;
        }
        $v = $variables[$k];
        if (is_bool($v)) {
            $designer = $f['designer'] ?? [];
            $variables[$k] = $v ? ($designer['yes_label'] ?? 'Ja') : ($designer['no_label'] ?? 'Nein');
        }
    }

    $arrays = [];
    foreach ($formFields as $f) {
        $key = $f['key'] ?? null;
        if (!$key || !array_key_exists($key, $variables)) {
            continue;
        }
        if (in_array($f['type'] ?? '', ['list', 'table'], true) && is_array($variables[$key])) {
            $arrays[$key] = $variables[$key];
        }
    }

    $designerMap = [];
    $richSubfields = ['stations.details']; // matches RICH_ROW_SUBFIELDS_DEFAULT

    // table columns declared as list also become rich
    foreach ($formFields as $f) {
        if (($f['type'] ?? '') !== 'table') {
            continue;
        }
        foreach (($f['columns'] ?? []) as $col) {
            if (($col['type'] ?? '') === 'list' && !empty($col['key'])) {
                $combined = $f['key'] . '.' . $col['key'];
                if (!in_array($combined, $richSubfields, true)) {
                    $richSubfields[] = $combined;
                }
            }
        }
    }

    // Phase T pre-pass.
    $expandedTableKeys = $invoke('expandTableBlocks', [$cleanedPath, $formFields, $arrays, $richSubfields]);

    // Phase A pre-pass.
    $rawPlaceholders = $invoke('extractPlaceholders', [$cleanedPath]);
    $rawKeys = array_column($rawPlaceholders, 'key');
    $preClassified = $invoke('classifyTemplatePlaceholders', [$rawKeys, $variables, $arrays]);
    $expandedListKeys = $invoke('expandListParagraphs', [
        $cleanedPath, $preClassified['lists'], $variables, $arrays, $designerMap,
    ]);

    // Phase C pre-pass.
    $preClonedGroups = $invoke('cloneParagraphGroupsPrepass', [
        $cleanedPath, $preClassified['rowGroups'] ?? [], $arrays, $richSubfields,
    ]);

    // Reset PhpWord macro statics, just like the controller does.
    (new \ReflectionProperty(\PhpOffice\PhpWord\TemplateProcessor::class, 'macroOpeningChars'))->setValue(null, '${');
    (new \ReflectionProperty(\PhpOffice\PhpWord\TemplateProcessor::class, 'macroClosingChars'))->setValue(null, '}');

    $tp = new \PhpOffice\PhpWord\TemplateProcessor($cleanedPath);
    $tp->setMacroOpeningChars('{{');
    $tp->setMacroClosingChars('}}');

    $templatePlaceholders = $tp->getVariables();
    $classified = $invoke('classifyTemplatePlaceholders', [$templatePlaceholders, $variables, $arrays]);
    $classified['lists'] = array_values(array_diff($classified['lists'], $expandedListKeys));
    if (!empty($expandedTableKeys)) {
        $tableKeys = array_keys($expandedTableKeys);
        $classified['lists'] = array_values(array_diff($classified['lists'], $tableKeys));
        $classified['scalars'] = array_values(array_diff($classified['scalars'], $tableKeys));
        foreach ($tableKeys as $tk) {
            unset($classified['rowGroups'][$tk]);
        }
    }
    foreach (array_keys($preClonedGroups) as $handledGroup) {
        unset($classified['rowGroups'][$handledGroup]);
    }

    $invoke('processRowGroups', [$tp, $classified['rowGroups'], $arrays, $designerMap, $richSubfields]);
    $invoke('processBlockGroups', [$tp, $classified['blockGroups'], $arrays]);
    $invoke('processCheckboxes', [$tp, $classified['checkboxes'], $variables, $designerMap]);
    $invoke('processLists', [$tp, $classified['lists'], $variables, $designerMap]);
    $invoke('processImages', [$tp, $formFields, $entry]);
    $invoke('processScalars', [$tp, $classified['scalars'], $variables]);

    $tp->saveAs($outputPath);

    $invoke('expandRichRowColumns', [$outputPath, $richSubfields, $arrays, $formFields]);
    $invoke('applyTableLayoutHelpers', [$outputPath, $arrays, $designerMap]);
    $invoke('convertCheckboxMarkersToContentControls', [$outputPath]);

    return $outputPath;
}

/**
 * Open a DOCX, peek at word/document.xml, and report on every <w:r> in body —
 * what fonts are declared, how many runs are missing rPr.rFonts, and (when
 * placeholdersOnly) which placeholders are detected.
 *
 * @return array{
 *   placeholders: list<string>,
 *   runs_total: int,
 *   runs_with_font: int,
 *   runs_no_font: int,
 *   fonts_in_placeholder_runs: array<string,int>,
 *   fonts_in_runs: array<string,int>,
 * }
 */
function inspectDocxRuns(string $docxPath, bool $placeholdersOnly): array
{
    $zip = new ZipArchive();
    $zip->open($docxPath);
    $xml = $zip->getFromName('word/document.xml') ?: '';
    $zip->close();

    $info = [
        'placeholders' => [],
        'runs_total' => 0,
        'runs_with_font' => 0,
        'runs_no_font' => 0,
        'fonts_in_placeholder_runs' => [],
        'fonts_in_runs' => [],
    ];

    if (preg_match_all('~<w:p\b[^>]*>(?:(?!</w:p>).)*?</w:p>~s', $xml, $paras)) {
        foreach ($paras[0] as $p) {
            $flat = '';
            if (preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $p, $tm)) {
                $flat = implode('', $tm[1]);
            }
            $hasPh = preg_match_all('~\{\{([^{}]+)\}\}~', $flat, $phm);
            if ($hasPh) {
                foreach ($phm[1] as $k) {
                    $info['placeholders'][$k] = true;
                }
            }
            if ($placeholdersOnly && !$hasPh) {
                continue;
            }
            // Walk runs.
            if (!preg_match_all('~<w:r\b[^>]*>(?:(?!</w:r>).)*?</w:r>~s', $p, $rm)) {
                continue;
            }
            foreach ($rm[0] as $runXml) {
                // For placeholdersOnly mode, only count runs that contain
                // placeholder-related characters (so lone label runs in the
                // same paragraph don't pollute the metric).
                if ($placeholdersOnly) {
                    $rt = '';
                    if (preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $runXml, $rtm)) {
                        $rt = implode('', $rtm[1]);
                    }
                    if (strpbrk($rt, '{}') === false) {
                        continue;
                    }
                }
                $info['runs_total']++;
                $font = null;
                if (preg_match('~<w:rFonts\b[^/>]*\bw:ascii(Theme)?="([^"]+)"~', $runXml, $fm)) {
                    $font = $fm[2];
                }
                if ($font !== null) {
                    $info['runs_with_font']++;
                    $bucket = $placeholdersOnly ? 'fonts_in_placeholder_runs' : 'fonts_in_runs';
                    $info[$bucket][$font] = ($info[$bucket][$font] ?? 0) + 1;
                } else {
                    $info['runs_no_font']++;
                }
            }
        }
    }

    $info['placeholders'] = array_keys($info['placeholders']);
    sort($info['placeholders']);
    return $info;
}

function findLeftoverPlaceholders(string $docxPath): array
{
    $zip = new ZipArchive();
    $zip->open($docxPath);
    $xml = $zip->getFromName('word/document.xml') ?: '';
    $zip->close();
    $found = [];
    if (preg_match_all('~<w:p\b[^>]*>(?:(?!</w:p>).)*?</w:p>~s', $xml, $paras)) {
        foreach ($paras[0] as $p) {
            $flat = '';
            if (preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $p, $tm)) {
                $flat = implode('', $tm[1]);
            }
            if (preg_match_all('~\{\{([^{}]+)\}\}~', $flat, $phm)) {
                foreach ($phm[1] as $k) {
                    $found[$k] = true;
                }
            }
        }
    }
    return array_keys($found);
}

function cleanupDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) {
        if ($f->isDir()) {
            @rmdir($f->getRealPath());
        } else {
            @unlink($f->getRealPath());
        }
    }
    @rmdir($dir);
}
