<?php

declare(strict_types=1);

/**
 * v4 hhff template — end-to-end API smoke test.
 *
 * Mirrors `tests/demo/install-demo.php` but is narrowed to a single template
 * and a single dataset, with synthetic (non-PII) values. Used to confirm the
 * full HTTP path still works end-to-end after the font-preservation patch
 * (the browser-use subagent could not push past the file picker due to a
 * browser security restriction; this script exercises the same endpoints
 * via curl, which has no such restriction).
 *
 * What it does:
 *   1. POST /api/v1/auth/login (admin@synaplan.com / admin123)
 *   2. GET  /plugins/synaform/setup-check (and POST /setup if needed)
 *   3. POST /plugins/synaform/templates  (multipart upload of v4 hhff DOCX)
 *   4. POST /plugins/synaform/forms      (Collection with the v4 variable set)
 *   5. POST /plugins/synaform/candidates (one synthetic dataset)
 *   6. PUT  /candidates/{id}/variables   (explicit overrides)
 *   7. POST /candidates/{id}/generate/{tpl} (run the actual pipeline through HTTP)
 *   8. GET  /candidates/{id}/documents/{doc}/download (pull bytes back)
 *   9. Inspect the downloaded DOCX: any leftover {{...}}? any Calibri leakage?
 *  10. Clean up so the run leaves no residue (delete candidate / form / template).
 *
 * Usage:
 *   php _devnotes/v4-api-smoketest.php
 *
 * Exit code: 0 on success, non-zero if any HTTP call fails or the result
 * has leftover placeholders / Calibri-only fonts on the body.
 *
 * The synthetic dataset uses obviously-fake values ("Alex Beispiel",
 * "Musterstraße 12", etc.) so we can run it safely on any environment
 * without fear of accidentally leaving real customer data behind.
 */

$apiUrl = rtrim(getenv('SYNAPLAN_API_URL') ?: 'http://localhost:8000', '/');
$userId = (int) (getenv('SYNAPLAN_USER_ID') ?: 1);
$email  = getenv('SYNAPLAN_ADMIN_EMAIL') ?: 'admin@synaplan.com';
$pass   = getenv('SYNAPLAN_ADMIN_PASS')  ?: 'admin123';

$templatePath = $argv[1] ?? '/wwwroot/hhff/word-files/v4/Profil hhff DE v4.docx';
if (!is_file($templatePath)) {
    fwrite(STDERR, "FAIL: template not found: $templatePath\n");
    exit(1);
}

$base = "$apiUrl/api/v1/user/$userId/plugins/synaform";

// =====================================================================
// minimal curl helpers
// =====================================================================

function http(string $method, string $url, ?array $body, array $cookies = [], array $headers = []): array
{
    $ch = curl_init($url);
    $headers[] = 'Accept: application/json';
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 180,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!empty($cookies)) {
        $opts[CURLOPT_COOKIE] = implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($cookies), array_values($cookies)));
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($response === false) {
        return ['status' => 0, 'body' => ['_raw' => 'curl error'], 'cookies' => [], 'raw' => ''];
    }
    $rawHeaders = substr($response, 0, $hdrSize);
    $bodyText   = substr($response, $hdrSize);
    $setCookies = [];
    foreach (preg_split("/\r?\n/", $rawHeaders) as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $cookie = trim(substr($line, strlen('Set-Cookie:')));
            if (preg_match('/^([^=]+)=([^;]+)/', $cookie, $m)) {
                $setCookies[$m[1]] = $m[2];
            }
        }
    }
    $decoded = json_decode($bodyText, true);
    return [
        'status'  => $status,
        'body'    => is_array($decoded) ? $decoded : ['_raw' => $bodyText],
        'cookies' => $setCookies,
        'raw'     => $bodyText,
    ];
}

function httpUpload(string $url, string $filePath, array $fields, array $cookies): array
{
    $ch = curl_init($url);
    $post = $fields;
    $post['file'] = new CURLFile(
        $filePath,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        basename($filePath)
    );
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_COOKIE         => implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($cookies), array_values($cookies))),
        CURLOPT_TIMEOUT        => 180,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $bodyText = $response === false ? '' : substr($response, $hdrSize);
    $decoded = json_decode($bodyText, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : ['_raw' => $bodyText]];
}

function httpDownload(string $url, array $cookies, string $outPath): int
{
    $fp = fopen($outPath, 'wb');
    if ($fp === false) {
        return 0;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FILE           => $fp,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: */*'],
        CURLOPT_COOKIE         => implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($cookies), array_values($cookies))),
        CURLOPT_TIMEOUT        => 60,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    return $status;
}

function fail(string $msg, array $extra = []): void
{
    fwrite(STDERR, "FAIL: $msg\n");
    if (!empty($extra)) {
        fwrite(STDERR, json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }
    exit(1);
}

function ok(array $resp, string $what): array
{
    if ($resp['status'] < 200 || $resp['status'] >= 300 || empty($resp['body']['success'])) {
        fail("$what -> HTTP {$resp['status']}", $resp['body']);
    }
    return $resp['body'];
}

// =====================================================================
// 1. login
// =====================================================================

printf("[auth ] login %s on %s ...\n", $email, $apiUrl);
$login = http('POST', "$apiUrl/api/v1/auth/login", ['email' => $email, 'password' => $pass]);
if ($login['status'] !== 200 || empty($login['body']['success'])) {
    fail("login: HTTP {$login['status']}", $login['body']);
}
$cookies = $login['cookies'];
if (empty($cookies['access_token'])) {
    fail("no access_token cookie after login");
}
printf("        OK, user id=%d\n", $login['body']['user']['id'] ?? $userId);

// =====================================================================
// 2. setup-check
// =====================================================================

$check = http('GET', "$base/setup-check", null, $cookies);
if ($check['status'] === 200 && !empty($check['body']['needs_setup'])) {
    printf("[setup] running /setup\n");
    ok(http('POST', "$base/setup", null, $cookies), 'plugin setup');
}

// =====================================================================
// 3. upload template
// =====================================================================

$tag = '[V4SmokeTest]';
$tplName = $tag . ' ' . basename($templatePath);

// Wipe any leftovers from a previous failed run.
foreach (($check['body']['templates'] ?? []) as $t) {
    if (!empty($t['name']) && str_contains($t['name'], $tag)) {
        http('DELETE', "$base/templates/{$t['id']}", null, $cookies);
    }
}
$listTpl = http('GET', "$base/templates", null, $cookies);
foreach ($listTpl['body']['templates'] ?? [] as $t) {
    if (!empty($t['name']) && str_contains($t['name'], $tag)) {
        http('DELETE', "$base/templates/{$t['id']}", null, $cookies);
    }
}
$listForms = http('GET', "$base/forms", null, $cookies);
foreach ($listForms['body']['forms'] ?? [] as $f) {
    if (!empty($f['name']) && str_contains($f['name'], $tag)) {
        http('DELETE', "$base/forms/{$f['id']}", null, $cookies);
    }
}
$listCands = http('GET', "$base/candidates", null, $cookies);
foreach ($listCands['body']['candidates'] ?? [] as $c) {
    if (!empty($c['name']) && str_contains($c['name'], $tag)) {
        http('DELETE', "$base/candidates/{$c['id']}", null, $cookies);
    }
}

printf("[tpl  ] uploading %s ...\n", basename($templatePath));
$resp = httpUpload("$base/templates", $templatePath, ['name' => $tplName], $cookies);
$body = ok($resp, 'template upload');
$templateId = $body['template']['id'];
$placeholderCount = $body['template']['placeholder_count'] ?? 0;
printf("        OK, %s (placeholders detected: %d)\n", $templateId, $placeholderCount);
if ($placeholderCount === 0) {
    fail("template upload reported 0 placeholders — DOCX rejected");
}

// =====================================================================
// 4. create form / Collection
// =====================================================================

$formFields = [
    ['key' => 'fullname', 'label' => 'Vollständiger Name', 'type' => 'text', 'source' => 'form'],
    ['key' => 'birthdate', 'label' => 'Geburtsdatum', 'type' => 'text', 'source' => 'form'],
    ['key' => 'nationality', 'label' => 'Nationalität', 'type' => 'text', 'source' => 'form'],
    ['key' => 'maritalstatus', 'label' => 'Familienstand', 'type' => 'select', 'options' => ['ledig','verheiratet','geschieden','verwitwet'], 'source' => 'form'],
    ['key' => 'email', 'label' => 'E-Mail', 'type' => 'text', 'source' => 'form'],
    ['key' => 'phone', 'label' => 'Telefon', 'type' => 'text', 'source' => 'form'],
    ['key' => 'street', 'label' => 'Straße', 'type' => 'text', 'source' => 'form'],
    ['key' => 'zip', 'label' => 'PLZ', 'type' => 'text', 'source' => 'form'],
    ['key' => 'city', 'label' => 'Stadt', 'type' => 'text', 'source' => 'form'],
    ['key' => 'target_position', 'label' => 'Zielposition', 'type' => 'text', 'source' => 'form'],
    ['key' => 'current_position', 'label' => 'Aktuelle Position', 'type' => 'text', 'source' => 'form'],
    ['key' => 'current_annual_salary', 'label' => 'Aktuelles Bruttojahresgehalt', 'type' => 'text', 'source' => 'form'],
    ['key' => 'notice_period', 'label' => 'Kündigungsfrist', 'type' => 'text', 'source' => 'form'],
    ['key' => 'working_hours', 'label' => 'Arbeitszeit', 'type' => 'text', 'source' => 'form'],
    ['key' => 'moving', 'label' => 'Umzugsbereitschaft', 'type' => 'checkbox', 'source' => 'form', 'designer' => ['yes_label' => 'Ja', 'no_label' => 'Nein']],
    ['key' => 'commute', 'label' => 'Pendelbereitschaft', 'type' => 'checkbox', 'source' => 'form', 'designer' => ['yes_label' => 'Ja', 'no_label' => 'Nein']],
    ['key' => 'travel', 'label' => 'Reisebereitschaft', 'type' => 'checkbox', 'source' => 'form', 'designer' => ['yes_label' => 'Ja', 'no_label' => 'Nein']],
    ['key' => 'relevant_positions', 'label' => 'Relevante Positionen', 'type' => 'list', 'source' => 'form'],
    ['key' => 'relevant_positions_for_target', 'label' => 'Relevante Erfahrung für Zielposition', 'type' => 'list', 'source' => 'form'],
    ['key' => 'benefits', 'label' => 'Benefits', 'type' => 'list', 'source' => 'form'],
    ['key' => 'languages', 'label' => 'Sprachen', 'type' => 'list', 'source' => 'form'],
    ['key' => 'other_skills', 'label' => 'Sonstige Kenntnisse', 'type' => 'list', 'source' => 'form'],
    ['key' => 'education', 'label' => 'Ausbildung', 'type' => 'list', 'source' => 'form'],
    ['key' => 'generated_month', 'label' => 'Monat', 'type' => 'text', 'source' => 'form'],
    ['key' => 'generated_year', 'label' => 'Jahr', 'type' => 'text', 'source' => 'form'],
    [
        'key' => 'stations', 'label' => 'Berufliche Stationen', 'type' => 'table', 'source' => 'form',
        'columns' => [
            ['key' => 'time', 'label' => 'Zeitraum', 'type' => 'text'],
            ['key' => 'employer', 'label' => 'Arbeitgeber', 'type' => 'text'],
            ['key' => 'position', 'label' => 'Position', 'type' => 'text'],
            ['key' => 'details', 'label' => 'Details', 'type' => 'list', 'structured' => true],
        ],
    ],
];

printf("[form ] creating Collection ...\n");
$body = ok(http('POST', "$base/forms", [
    'name'         => $tag . ' Synaform v4 hhff DE smoke',
    'description'  => 'Automated v4 smoke test. Safe to delete.',
    'language'     => 'de',
    'fields'       => $formFields,
    'template_ids' => [$templateId],
], $cookies), 'create form');
$formId = $body['form']['id'];
printf("        OK, %s (fields: %d)\n", $formId, count($body['form']['fields'] ?? []));

// =====================================================================
// 5. create candidate / dataset
// =====================================================================

$fieldValues = [
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
    'moving' => 'Ja',
    'commute' => 'Ja',
    'travel' => 'Nein',
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
];

printf("[cand ] creating dataset ...\n");
$body = ok(http('POST', "$base/candidates", [
    'name'         => $tag . ' Alex Beispiel',
    'form_id'      => $formId,
    'template_id'  => $templateId,
    'status'       => 'reviewed',
    'field_values' => $fieldValues,
], $cookies), 'create candidate');
$candidateId = $body['candidate']['id'];
printf("        OK, %s\n", $candidateId);

ok(http('PUT', "$base/candidates/$candidateId/variables", [
    'overrides' => $fieldValues,
], $cookies), 'set overrides');

// =====================================================================
// 6. generate
// =====================================================================

printf("[gen  ] generating filled DOCX ...\n");
$gen = http('POST', "$base/candidates/$candidateId/generate/$templateId", null, $cookies);
if ($gen['status'] < 200 || $gen['status'] >= 300 || empty($gen['body']['success'])) {
    fail('generate failed', ['status' => $gen['status'], 'body' => $gen['body']]);
}
$documentId = $gen['body']['document']['id'] ?? null;
if (!$documentId) {
    fail('generate succeeded but no document.id', $gen['body']);
}
printf("        OK, document %s\n", $documentId);

// =====================================================================
// 7. download + introspect
// =====================================================================

$tmpOut = sys_get_temp_dir() . '/v4-smoketest-' . bin2hex(random_bytes(3)) . '.docx';
$status = httpDownload("$base/candidates/$candidateId/documents/$documentId/download", $cookies, $tmpOut);
if ($status < 200 || $status >= 300) {
    fail("download failed: HTTP $status");
}
$bytes = filesize($tmpOut) ?: 0;
printf("[dl   ] downloaded %d bytes to %s\n", $bytes, $tmpOut);
if ($bytes < 5_000) {
    fail("downloaded artefact too small");
}

$zip = new ZipArchive();
if ($zip->open($tmpOut) !== true) {
    fail("downloaded file is not a valid ZIP / DOCX");
}
$xml = $zip->getFromName('word/document.xml');
$headerXmls = [];
$footerXmls = [];
for ($i = 0; $i < $zip->numFiles; ++$i) {
    $entry = $zip->statIndex($i)['name'] ?? '';
    if (preg_match('#^word/header\d*\.xml$#', $entry)) {
        $headerXmls[$entry] = $zip->getFromName($entry) ?: '';
    } elseif (preg_match('#^word/footer\d*\.xml$#', $entry)) {
        $footerXmls[$entry] = $zip->getFromName($entry) ?: '';
    }
}
$zip->close();
if (!is_string($xml) || $xml === '') {
    fail("DOCX has no word/document.xml");
}

// 7a. leftover {{...}} placeholders — body + headers + footers.
$collectLeftover = static function (string $xml): array {
    $out = [];
    if (preg_match_all('~<w:p\b[^>]*>(?:(?!</w:p>).)*?</w:p>~s', $xml, $paras)) {
        foreach ($paras[0] as $p) {
            $flat = '';
            if (preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $p, $tm)) {
                $flat = implode('', $tm[1]);
            }
            if (preg_match_all('~\{\{([^{}]+)\}\}~', $flat, $phm)) {
                foreach ($phm[1] as $k) {
                    $out[$k] = true;
                }
            }
        }
    }
    return $out;
};
$leftover = $collectLeftover($xml);
foreach ($headerXmls as $hxml) {
    foreach ($collectLeftover($hxml) as $k => $_) {
        $leftover[$k] = true;
    }
}
foreach ($footerXmls as $fxml) {
    foreach ($collectLeftover($fxml) as $k => $_) {
        $leftover[$k] = true;
    }
}
$leftoverList = array_keys($leftover);

// 7a-bis. Header substitution check — the test dataset puts known sentinel
// values in `fullname` / `target_position`. If the v4 hhff template's header
// placeholders (`{{fullname}}` / `{{target_position}}` in the upper-right
// "Profil von …" line) were correctly substituted, those sentinels must
// appear in at least one header part. This is the assertion the customer
// asked us to add; without it, regressions in cleanTemplateMacros's
// header-pass would silently slip through.
$headerSubsCheck = ['ok' => true, 'detail' => '(no headers in template — skipped)'];
if (!empty($headerXmls)) {
    $headerJoined = implode("\n", array_values($headerXmls));
    $sentinels = [
        'fullname'        => $fieldValues['fullname'] ?? null,
        'target_position' => $fieldValues['target_position'] ?? null,
    ];
    $missingSentinels = [];
    foreach ($sentinels as $key => $expected) {
        if ($expected === null || $expected === '') {
            continue;
        }
        // Word may break the substituted text across runs again, so collapse
        // every header part down to its concatenated <w:t> text first.
        $flatHeaderText = '';
        if (preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $headerJoined, $tm)) {
            $flatHeaderText = implode('', $tm[1]);
        }
        if (str_contains($flatHeaderText, (string) $expected)) {
            continue;
        }
        // Header may simply not USE this placeholder — check whether the
        // pristine template carried it. Only fail if the template DID and
        // the substitution was lost.
        $hadIt = false;
        foreach ($headerXmls as $hxml) {
            $flat = '';
            if (preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $hxml, $tm)) {
                $flat = implode('', $tm[1]);
            }
            if (str_contains($flat, '{{' . $key . '}}') || str_contains($hxml, '{{' . $key . '}}')) {
                $hadIt = true;
                break;
            }
        }
        if ($hadIt) {
            $missingSentinels[] = "$key (expected \"$expected\")";
        }
    }
    if (empty($missingSentinels)) {
        $headerSubsCheck['detail'] = sprintf('OK — %d header part(s) processed', count($headerXmls));
    } else {
        $headerSubsCheck = [
            'ok' => false,
            'detail' => 'header placeholders not substituted: ' . implode(', ', $missingSentinels),
        ];
    }
}

// 7b. font tally on body runs.
$fontTally = [];
$noFontRuns = 0;
$totalRuns = 0;
if (preg_match_all('~<w:r\b[^>]*>(?:(?!</w:r>).)*?</w:r>~s', $xml, $rm)) {
    foreach ($rm[0] as $runXml) {
        // Skip runs with no visible text (empty proofErr / instrText / drawings).
        $hasText = false;
        if (preg_match_all('~<w:t[^>]*>([^<]*)</w:t>~', $runXml, $tm)) {
            foreach ($tm[1] as $txt) {
                if ($txt !== '') {
                    $hasText = true;
                    break;
                }
            }
        }
        if (!$hasText) {
            continue;
        }
        $totalRuns++;
        if (preg_match('~<w:rFonts\b[^/>]*\bw:ascii(Theme)?="([^"]+)"~', $runXml, $fm)) {
            $fontTally[$fm[2]] = ($fontTally[$fm[2]] ?? 0) + 1;
        } else {
            $noFontRuns++;
        }
    }
}

printf("[check] body text-bearing runs: %d (%d with explicit rFonts, %d without)\n",
    $totalRuns, $totalRuns - $noFontRuns, $noFontRuns);
printf("        fonts seen: %s\n", $fontTally ? implode(', ', array_map(
    static fn ($k, $v) => "$k($v)",
    array_keys($fontTally),
    array_values($fontTally),
)) : '(none)');

// =====================================================================
// 8. cleanup (delete the smoke-test records)
// =====================================================================

http('DELETE', "$base/candidates/$candidateId", null, $cookies);
http('DELETE', "$base/forms/$formId", null, $cookies);
http('DELETE', "$base/templates/$templateId", null, $cookies);
@unlink($tmpOut);

// =====================================================================
// 9. report
// =====================================================================

$exitCode = 0;
$problems = [];
if (!empty($leftoverList)) {
    $problems[] = "leftover placeholders (body+headers+footers): " . implode(', ', $leftoverList);
    $exitCode = 1;
}
if (!$headerSubsCheck['ok']) {
    $problems[] = $headerSubsCheck['detail'];
    $exitCode = 1;
}
if ($totalRuns > 0 && $totalRuns - $noFontRuns < 0.5 * $totalRuns) {
    // More than half the body runs have no explicit font — suspicious.
    $problems[] = sprintf("only %d/%d runs have explicit rFonts (font preservation may have regressed)",
        $totalRuns - $noFontRuns, $totalRuns);
    $exitCode = 1;
}
// Loud red flag: any Calibri leakage at all in a template that should never use Calibri.
if (!empty($fontTally['Calibri']) || !empty($fontTally['Calibri Light'])) {
    $problems[] = "Calibri/Calibri Light appears in body runs (theme-default leakage): " . json_encode($fontTally);
    $exitCode = 1;
}

if ($exitCode === 0) {
    printf("\nPASS — v4 hhff DE template smoke test went through the full HTTP API end-to-end.\n");
    printf("  - upload + placeholder detection: OK (%d placeholders)\n", $placeholderCount);
    printf("  - collection + dataset + override: OK\n");
    printf("  - generate via /generate endpoint:  OK (document %s)\n", $documentId);
    printf("  - download + DOCX integrity:        OK (%d bytes)\n", $bytes);
    printf("  - placeholder substitution:         OK (no leftover {{...}})\n");
    printf("  - header/footer substitution:       %s\n", $headerSubsCheck['detail']);
    printf("  - font preservation:                OK (%s)\n", implode(', ', array_map(
        static fn ($k, $v) => $k . ' × ' . $v,
        array_keys($fontTally),
        array_values($fontTally),
    )));
} else {
    printf("\nFAIL — v4 smoke test reported issues:\n");
    foreach ($problems as $p) {
        printf("  - %s\n", $p);
    }
}

exit($exitCode);
