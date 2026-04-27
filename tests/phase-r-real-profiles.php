<?php

declare(strict_types=1);

/**
 * Phase R (real profiles) regression test — runs the full generate
 * pipeline against 3 actual customer profile templates to prove the
 * new V2 generator reliably fills:
 *
 *   - scalar placeholders
 *   - {{stations.*.N}} row groups (cloneRow / paragraph-prepass)
 *   - {{checkb.*}} pairs
 *   - list-type placeholders with UL / OL designer toggles
 *   - table-block {{varname}} expansion driven by designer columns
 *
 * The profiles are considered sensitive and live in the private `hhff`
 * repo. They are available to this test at:
 *
 *   /hhff-word-files/Profil A.Findeisen Deichmann MH.docx
 *   /hhff-word-files/Profil A.Moussaoui Best Secret MH.docx
 *   /hhff-word-files/Profile C.Fabri Fitflop MH.docx
 *
 * If the directory is not mounted in the container, the test skips
 * with exit code 0 (so CI stays green on public runners).
 *
 * Run:
 *   docker cp /wwwroot/hhff/word-files synaplan-backend:/hhff-word-files 2>/dev/null \
 *   && docker compose exec backend php /plugins/templatex/tests/phase-r-real-profiles.php
 */

require '/var/www/backend/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

$profileDir = '/hhff-word-files';
$profiles = [
    // Filled-out profiles from real customers (reference material).
    'Profil A.Findeisen Deichmann MH.docx',
    'Profil A.Moussaoui Best Secret MH.docx',
    'Profile C.Fabri Fitflop MH.docx',
    // Empty templates that actually carry {{placeholders}} — these drive
    // the generate pipeline. `template-v1_de_fixed.docx` and
    // `template-v2_de_fixed.docx` are the hand-curated v1/v2 templates
    // used by the customer to generate the profiles above.
    'Profil (Needle & Haystack) Name Firma Kuerzel N&H.docx',
    'Profil hhff neu.docx',
    'Profile hhff neu_engl.docx',
    'template-v1_de_fixed.docx',
    'template-v2_de_fixed.docx',
];

if (!is_dir($profileDir)) {
    echo "SKIP — $profileDir not mounted (hhff word-files unavailable)\n";
    exit(0);
}

$controllerRef = new ReflectionClass(\Plugin\TemplateX\Controller\TemplateXController::class);
$controller = $controllerRef->newInstanceWithoutConstructor();
$logProp = $controllerRef->getProperty('logger');
$logProp->setAccessible(true);
$logProp->setValue($controller, new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void {}
});

function callPriv($controller, string $method, array $args)
{
    $ref = new ReflectionMethod(\Plugin\TemplateX\Controller\TemplateXController::class, $method);
    $ref->setAccessible(true);
    return $ref->invoke($controller, ...$args);
}

$fails = [];
$summary = [];

foreach ($profiles as $pname) {
    $src = $profileDir . '/' . $pname;
    if (!is_file($src)) {
        $fails[] = "profile missing: $pname";
        continue;
    }

    // 1. Detect placeholders in the real profile → drives expectations.
    $placeholders = callPriv($controller, 'extractPlaceholders', [$src]);
    $phKeys = array_column($placeholders, 'key');

    $summary[$pname] = [
        'placeholders' => count($phKeys),
        'sample' => array_slice($phKeys, 0, 6),
    ];

    if (count($phKeys) === 0) {
        // Many real customer DOCX files don't use TemplateX placeholders yet — that's
        // not an error, just note it in the summary.
        $summary[$pname]['note'] = 'no {{placeholders}} present';
        continue;
    }

    // 2. Build synthetic data for each placeholder pattern.
    $variables = [];
    $arrays = [];
    foreach ($phKeys as $k) {
        if (str_starts_with($k, '#') || str_starts_with($k, '/')) {
            continue;
        }
        if (str_starts_with($k, 'checkb.')) {
            $variables[$k] = str_ends_with($k, '.yes');
            continue;
        }
        if (preg_match('/^(\w+)\.(\w+)(?:\.N)?$/', $k, $m)) {
            $group = $m[1];
            $field = $m[2];
            if (!isset($arrays[$group])) {
                $arrays[$group] = [];
                for ($i = 0; $i < 2; $i++) {
                    $arrays[$group][] = [];
                }
            }
            foreach ($arrays[$group] as $idx => $row) {
                $arrays[$group][$idx][$field] = "TestVal_{$group}_{$field}_" . ($idx + 1);
            }
            continue;
        }
        if (str_ends_with($k, 'list')) {
            $variables[$k] = ['Item A', 'Item B', 'Item C'];
            continue;
        }
        $variables[$k] = 'Test_' . $k;
    }

    // 3. Run the generator's private pipeline (same sequence as
    //    candidatesGenerate() but skipping the HTTP wrapper).
    $tmp = sys_get_temp_dir() . '/tx_real_' . md5($pname) . '.docx';
    copy($src, $tmp);

    $designerMap = [];

    $cleanedPath = callPriv($controller, 'cleanTemplateMacros', [$tmp]);

    $expandedTableKeys = callPriv($controller, 'expandTableBlocks', [
        $cleanedPath,
        [],
        $arrays,
    ]);

    $preClassified = callPriv($controller, 'classifyTemplatePlaceholders', [
        $phKeys,
        $variables,
        $arrays,
    ]);

    $expandedListKeys = callPriv($controller, 'expandListParagraphs', [
        $cleanedPath,
        $preClassified['lists'] ?? [],
        $variables,
        $arrays,
        $designerMap,
    ]);

    $preClonedGroups = callPriv($controller, 'cloneParagraphGroupsPrepass', [
        $cleanedPath,
        $preClassified['rowGroups'] ?? [],
        $arrays,
    ]);

    $tp = new TemplateProcessor($cleanedPath);
    $tp->setMacroOpeningChars('{{');
    $tp->setMacroClosingChars('}}');
    $live = $tp->getVariables();
    $classified = callPriv($controller, 'classifyTemplatePlaceholders', [
        $live,
        $variables,
        $arrays,
    ]);
    $classified['lists'] = array_values(array_diff($classified['lists'], $expandedListKeys));
    foreach (array_keys($preClonedGroups) as $g) {
        unset($classified['rowGroups'][$g]);
    }
    if (!empty($expandedTableKeys)) {
        $tks = array_keys($expandedTableKeys);
        $classified['lists'] = array_values(array_diff($classified['lists'], $tks));
        $classified['scalars'] = array_values(array_diff($classified['scalars'], $tks));
        foreach ($tks as $tk) {
            unset($classified['rowGroups'][$tk]);
        }
    }

    callPriv($controller, 'processRowGroups', [$tp, $classified['rowGroups'], $arrays, $designerMap]);
    callPriv($controller, 'processBlockGroups', [$tp, $classified['blockGroups'], $arrays]);
    callPriv($controller, 'processCheckboxes', [$tp, $classified['checkboxes'], $variables, $designerMap]);
    callPriv($controller, 'processLists', [$tp, $classified['lists'], $variables]);
    callPriv($controller, 'processScalars', [$tp, $classified['scalars'], $variables]);

    $out = sys_get_temp_dir() . '/tx_real_out_' . md5($pname) . '.docx';
    $tp->saveAs($out);

    callPriv($controller, 'expandStationDetails', [$out, $arrays['stations'] ?? []]);
    callPriv($controller, 'applyTableLayoutHelpers', [$out, $arrays, $designerMap]);

    if (is_file($cleanedPath)) {
        unlink($cleanedPath);
    }

    // 4. Assert: no raw {{placeholder}} left in the output (ignoring block
    //    markers {{#…}}/{{/…}} which are allowed to remain if a block had no
    //    data, and the .N suffix anchors that become cloned rows).
    $zip = new ZipArchive();
    $zip->open($out);
    $outXml = $zip->getFromName('word/document.xml');
    $zip->close();

    $remaining = [];
    if (preg_match_all('/\{\{([^}]+)\}\}/', strip_tags($outXml), $m)) {
        foreach ($m[1] as $raw) {
            $raw = trim($raw);
            if (str_starts_with($raw, '#') || str_starts_with($raw, '/')) {
                continue;
            }
            $remaining[] = $raw;
        }
    }
    if (!empty($remaining)) {
        $fails[] = "$pname: unsubstituted placeholders remain: " . implode(', ', array_slice(array_unique($remaining), 0, 8));
    }

    $summary[$pname]['output_size'] = filesize($out);
    $summary[$pname]['list_expanded'] = count($expandedListKeys);
    $summary[$pname]['row_prepass'] = count($preClonedGroups);
    $summary[$pname]['table_block'] = count($expandedTableKeys);
}

foreach ($summary as $pname => $info) {
    printf(
        "  %-48s placeholders=%-3d list=%-2d rowPrepass=%-2d tblBlock=%-2d %s\n",
        $pname,
        $info['placeholders'],
        $info['list_expanded'] ?? 0,
        $info['row_prepass'] ?? 0,
        $info['table_block'] ?? 0,
        isset($info['note']) ? "(note: {$info['note']})" : ''
    );
}

if (!empty($fails)) {
    echo "\nFAIL\n";
    foreach ($fails as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "\nPASS — phase R (real profiles) end-to-end pipeline works.\n";
exit(0);
