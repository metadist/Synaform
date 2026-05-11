<?php

declare(strict_types=1);

/**
 * Install the seeded fashion-HR demo into a running Synaplan + Synaform
 * instance.
 *
 * What seed-demo.php produces — DOCX files on disk — is static. This script
 * is the second step: it uploads those artifacts into the running plugin
 * through its HTTP API so the 2 Collections, their templates, and the 6
 * candidates become visible in the plugin UI.
 *
 * Requires: seed-demo.php must have been run first.
 *
 * Usage:
 *   php tests/demo/install-demo.php [--wipe] [--generate]
 *
 * Options:
 *   --wipe      Delete any existing demo forms/templates/candidates by name
 *               prefix before creating fresh ones.
 *   --generate  After creating candidates, call /candidates/{id}/generate
 *               so filled DOCX files appear in the app too.
 *
 * Environment:
 *   SYNAPLAN_API_URL      default: http://localhost:8000
 *   SYNAPLAN_USER_ID      default: 1
 *   SYNAPLAN_ADMIN_EMAIL  default: admin@synaplan.com
 *   SYNAPLAN_ADMIN_PASS   default: admin123
 */

$apiUrl = rtrim($_SERVER['SYNAPLAN_API_URL'] ?? getenv('SYNAPLAN_API_URL') ?: 'http://localhost:8000', '/');
$userId = (int) ($_SERVER['SYNAPLAN_USER_ID'] ?? getenv('SYNAPLAN_USER_ID') ?: 1);
$email  = $_SERVER['SYNAPLAN_ADMIN_EMAIL'] ?? getenv('SYNAPLAN_ADMIN_EMAIL') ?: 'admin@synaplan.com';
$pass   = $_SERVER['SYNAPLAN_ADMIN_PASS']  ?? getenv('SYNAPLAN_ADMIN_PASS')  ?: 'admin123';

$wipe     = in_array('--wipe', $argv, true);
$generate = in_array('--generate', $argv, true);

$demoDir = __DIR__;
$jsonFile = $demoDir . '/collections.json';
if (!is_file($jsonFile)) {
    fwrite(STDERR, "FAIL: $jsonFile not found. Run seed-demo.php first.\n");
    exit(1);
}

$manifest = json_decode(file_get_contents($jsonFile), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "FAIL: collections.json is not valid JSON.\n");
    exit(1);
}

$base = "$apiUrl/api/v1/user/$userId/plugins/synaform";

// Tag every demo-created record so --wipe can find them again.
const DEMO_TAG = '[FashionDemo]';

// ---------------------------------------------------------------------------
// Minimal HTTP client (curl-based, JSON + multipart)
// ---------------------------------------------------------------------------

function httpJson(string $method, string $url, ?array $body, array $cookies = []): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
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
        $opts[CURLOPT_COOKIE] = implode('; ', array_map(
            fn($k, $v) => "$k=$v",
            array_keys($cookies),
            array_values($cookies)
        ));
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("curl failed: $err ($method $url)");
    }
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
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
    ];
}

function httpUpload(string $url, string $filePath, array $fields, array $cookies): array
{
    $ch = curl_init($url);
    $post = $fields;
    $post['file'] = new CURLFile($filePath, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', basename($filePath));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_COOKIE         => implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), array_values($cookies))),
        CURLOPT_TIMEOUT        => 180,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("upload failed: $err");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $bodyText = substr($response, $hdrSize);
    $decoded = json_decode($bodyText, true);
    return [
        'status' => $status,
        'body'   => is_array($decoded) ? $decoded : ['_raw' => $bodyText],
    ];
}

function requireOk(array $resp, string $what): array
{
    if ($resp['status'] < 200 || $resp['status'] >= 300 || empty($resp['body']['success'])) {
        fwrite(STDERR, "FAIL: $what -> HTTP {$resp['status']}\n");
        fwrite(STDERR, json_encode($resp['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
    return $resp['body'];
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

printf("[auth] Logging in as %s on %s ...\n", $email, $apiUrl);
$login = httpJson('POST', "$apiUrl/api/v1/auth/login", ['email' => $email, 'password' => $pass]);
if ($login['status'] !== 200 || empty($login['body']['success'])) {
    fwrite(STDERR, "FAIL: login: HTTP {$login['status']}\n");
    fwrite(STDERR, json_encode($login['body'], JSON_PRETTY_PRINT) . "\n");
    exit(1);
}
$cookies = $login['cookies'];
if (empty($cookies['access_token'])) {
    fwrite(STDERR, "FAIL: no access_token cookie received after login\n");
    exit(1);
}
printf("       OK, user id=%d\n", $login['body']['user']['id'] ?? $userId);

// ---------------------------------------------------------------------------
// Ensure plugin is set up for this user
// ---------------------------------------------------------------------------

$check = httpJson('GET', "$base/setup-check", null, $cookies);
if ($check['status'] === 200 && ($check['body']['needs_setup'] ?? false)) {
    printf("[setup] Plugin not yet initialised for user — running /setup ...\n");
    requireOk(httpJson('POST', "$base/setup", null, $cookies), 'plugin setup');
}

// ---------------------------------------------------------------------------
// Optional wipe
// ---------------------------------------------------------------------------

function listAll(string $base, string $endpoint, string $key, array $cookies): array
{
    $resp = httpJson('GET', "$base/$endpoint", null, $cookies);
    if ($resp['status'] !== 200) {
        return [];
    }
    return $resp['body'][$key] ?? [];
}

if ($wipe) {
    printf("[wipe] Deleting existing %s-tagged records ...\n", DEMO_TAG);
    foreach (listAll($base, 'candidates', 'candidates', $cookies) as $c) {
        if (!empty($c['name']) && str_contains($c['name'], DEMO_TAG)) {
            httpJson('DELETE', "$base/candidates/{$c['id']}", null, $cookies);
            printf("       deleted candidate %s (%s)\n", $c['id'], $c['name']);
        }
    }
    foreach (listAll($base, 'forms', 'forms', $cookies) as $f) {
        if (!empty($f['name']) && str_contains($f['name'], DEMO_TAG)) {
            httpJson('DELETE', "$base/forms/{$f['id']}", null, $cookies);
            printf("       deleted form %s (%s)\n", $f['id'], $f['name']);
        }
    }
    foreach (listAll($base, 'templates', 'templates', $cookies) as $t) {
        if (!empty($t['name']) && str_contains($t['name'], DEMO_TAG)) {
            httpJson('DELETE', "$base/templates/{$t['id']}", null, $cookies);
            printf("       deleted template %s (%s)\n", $t['id'], $t['name']);
        }
    }
}

// ---------------------------------------------------------------------------
// Upload templates
// ---------------------------------------------------------------------------

$templateFilesBySlug = [
    'demo-template1.docx' => $demoDir . '/demo-template1.docx',
    'demo-template2.docx' => $demoDir . '/demo-template2.docx',
];
$templateNamesBySlug = [
    'demo-template1.docx' => DEMO_TAG . ' Executive Fashion Profile',
    'demo-template2.docx' => DEMO_TAG . ' Retail Manager Profile',
];

$templateIds = [];
foreach ($templateFilesBySlug as $slug => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: template missing: $path (run seed-demo.php first)\n");
        exit(1);
    }
    printf("[tpl ] Uploading %s ...\n", $slug);
    $resp = httpUpload("$base/templates", $path, ['name' => $templateNamesBySlug[$slug]], $cookies);
    $body = requireOk($resp, "upload $slug");
    $templateIds[$slug] = $body['template']['id'];
    printf("       %s (placeholders: %d)\n", $body['template']['id'], $body['template']['placeholder_count'] ?? 0);
}

// ---------------------------------------------------------------------------
// Create forms (Collections) with proper typed fields
// ---------------------------------------------------------------------------

/**
 * Returns the field schema used by BOTH collections (the variable set is
 * identical — only the template differs).
 */
function demoFormFields(): array
{
    return [
        // Meta
        ['key' => 'target-position',  'label' => 'Vorgestellte Position (Zielposition)', 'type' => 'text', 'required' => true,  'source' => 'form'],
        ['key' => 'month',            'label' => 'Monat der Profilerstellung',          'type' => 'text', 'required' => false, 'source' => 'form'],
        ['key' => 'year',             'label' => 'Jahr der Profilerstellung',           'type' => 'text', 'required' => false, 'source' => 'form'],

        // Personal
        ['key' => 'fullname',         'label' => 'Vollständiger Name',   'type' => 'text', 'required' => false, 'source' => 'ai',   'fallback' => 'form'],
        ['key' => 'firstname',        'label' => 'Vorname',              'type' => 'text', 'required' => false, 'source' => 'form', 'fallback' => 'ai'],
        ['key' => 'lastname',         'label' => 'Nachname',             'type' => 'text', 'required' => false, 'source' => 'form', 'fallback' => 'ai'],
        ['key' => 'address1',         'label' => 'Straße und Hausnummer','type' => 'text', 'required' => false, 'source' => 'ai',   'fallback' => 'form'],
        ['key' => 'address2',         'label' => 'Ort',                  'type' => 'text', 'required' => false, 'source' => 'ai',   'fallback' => 'form'],
        ['key' => 'zip',              'label' => 'Postleitzahl',         'type' => 'text', 'required' => false, 'source' => 'ai',   'fallback' => 'form'],
        ['key' => 'birthdate',        'label' => 'Geburtsdatum',         'type' => 'text', 'required' => false, 'source' => 'ai',   'fallback' => 'form'],
        ['key' => 'nationality',      'label' => 'Nationalität',         'type' => 'text', 'required' => false, 'source' => 'form'],
        ['key' => 'maritalstatus',    'label' => 'Familienstand',        'type' => 'text', 'required' => false, 'source' => 'form'],
        ['key' => 'number',           'label' => 'Telefon',              'type' => 'text', 'required' => false, 'source' => 'ai',   'fallback' => 'form'],
        ['key' => 'email',            'label' => 'E-Mail',               'type' => 'text', 'required' => false, 'source' => 'ai',   'fallback' => 'form'],

        // Current position & availability
        ['key' => 'currentposition',  'label' => 'Aktuelle Position',    'type' => 'text', 'required' => false, 'source' => 'ai', 'fallback' => 'form'],
        ['key' => 'moving',           'label' => 'Umzugsbereitschaft',   'type' => 'select', 'options' => ['Ja', 'Nein'], 'required' => false, 'source' => 'form'],
        ['key' => 'commute',          'label' => 'Pendelbereitschaft',   'type' => 'select', 'options' => ['Ja', 'Nein'], 'required' => false, 'source' => 'form'],
        ['key' => 'travel',           'label' => 'Reisebereitschaft',    'type' => 'select', 'options' => ['Ja', 'Nein'], 'required' => false, 'source' => 'form'],
        ['key' => 'travelorcommute',  'label' => 'Pendeln/Reisen kombiniert', 'type' => 'text', 'required' => false, 'source' => 'form'],

        // Compensation & contract
        ['key' => 'noticeperiod',     'label' => 'Kündigungsfrist',              'type' => 'text', 'required' => false, 'source' => 'form'],
        ['key' => 'currentansalary',  'label' => 'Aktuelles Bruttojahresgehalt', 'type' => 'text', 'required' => false, 'source' => 'form'],
        ['key' => 'expectedansalary', 'label' => 'Erwartetes Bruttojahresgehalt','type' => 'text', 'required' => false, 'source' => 'form', 'hint' => "'nicht relevant' zum Weglassen"],
        ['key' => 'workinghours',     'label' => 'Vertragliche Arbeitszeit',     'type' => 'text', 'required' => false, 'source' => 'form', 'hint' => "'nicht relevant' zum Weglassen"],
        ['key' => 'optional.firmenwagen', 'label' => 'Firmenwagen (optional)',   'type' => 'text', 'required' => false, 'source' => 'form', 'hint' => 'Leer lassen wenn nicht relevant'],

        // Education (AI-first)
        ['key' => 'education',        'label' => 'Ausbildung und Studium',       'type' => 'text', 'required' => false, 'source' => 'ai', 'fallback' => 'form'],

        // Lists
        ['key' => 'relevantposlist',          'label' => 'Relevante vorherige Positionen', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'Eine Position pro Zeile'],
        ['key' => 'relevantfortargetposlist', 'label' => 'Relevante Erfahrung für Zielposition', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'Direct Reports, P&L, Store-Fläche, BR-Erfahrung'],
        ['key' => 'languageslist',            'label' => 'Sprachkenntnisse', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'mit Level (Muttersprache, C1, …)'],
        ['key' => 'benefitslist',             'label' => 'Benefits',         'type' => 'list', 'required' => false, 'source' => 'form'],
        ['key' => 'otherskillslist',          'label' => 'Sonstige Kenntnisse', 'type' => 'list', 'required' => false, 'source' => 'form'],

        // Stations (table / repeating group)
        [
            'key'      => 'stations',
            'label'    => 'Berufliche Stationen',
            'type'     => 'table',
            'required' => false,
            'source'   => 'ai',
            'fallback' => 'form',
            'columns'  => [
                ['key' => 'employer',  'label' => 'Arbeitgeber'],
                ['key' => 'time',      'label' => 'Zeitraum'],
                ['key' => 'positions', 'label' => 'Position'],
                ['key' => 'details',   'label' => 'Details (Datum, Titel, Bullets)'],
            ],
        ],
    ];
}

$formFields = demoFormFields();

$collectionSpecs = [
    'fashion-executive-profiles' => [
        'name'        => DEMO_TAG . ' Fashion Executive Profiles (DE)',
        'description' => 'Senior/Executive Rollen in der Modebranche — Marketing, E-Commerce, Business Unit. Template: demo-template1.docx.',
        'template'    => 'demo-template1.docx',
    ],
    'fashion-retail-manager-profiles' => [
        'name'        => DEMO_TAG . ' Fashion Retail Manager Profiles (DE)',
        'description' => 'Store Manager, Area Manager, Visual Merchandising im stationären Fashion-Retail. Template: demo-template2.docx.',
        'template'    => 'demo-template2.docx',
    ],
];

$formIds = [];
foreach ($collectionSpecs as $slug => $spec) {
    printf("[form] Creating Collection '%s' ...\n", $spec['name']);
    $body = requireOk(httpJson('POST', "$base/forms", [
        'name'         => $spec['name'],
        'description'  => $spec['description'],
        'language'     => 'de',
        'fields'       => $formFields,
        'template_ids' => [$templateIds[$spec['template']]],
    ], $cookies), "create form $slug");
    $formIds[$slug] = $body['form']['id'];
    printf("       %s (fields: %d, templates: %d)\n",
        $body['form']['id'],
        count($body['form']['fields'] ?? []),
        count($body['form']['template_ids'] ?? [])
    );
}

// ---------------------------------------------------------------------------
// Create candidates with field_values + overrides
// ---------------------------------------------------------------------------

/** yes/no → Ja/Nein for select fields */
function yesNoToJaNein(?string $v): string
{
    return ($v === 'yes') ? 'Ja' : (($v === 'no') ? 'Nein' : '');
}

$candidatesMap = [
    'A' => 'fashion-executive-profiles',
    'B' => 'fashion-retail-manager-profiles',
];

$createdCandidateIds = [];

foreach ($manifest['datasets'] as $ds) {
    // Map dataset -> collection slug. Manifest v1 uses ds['collection'] = slug already.
    $collSlug = $ds['collection'] ?? ($candidatesMap[$ds['collection_id'] ?? ''] ?? null);
    if (!$collSlug || !isset($formIds[$collSlug])) {
        fwrite(STDERR, "SKIP: cannot map dataset '{$ds['name']}' to a collection\n");
        continue;
    }

    $formId = $formIds[$collSlug];
    $tplSlug = $collectionSpecs[$collSlug]['template'];
    $tplId  = $templateIds[$tplSlug];

    // Split fullname heuristic
    $fullname = (string) ($ds['scalars']['fullname'] ?? '');
    $firstname = '';
    $lastname  = '';
    if ($fullname !== '' && strpos($fullname, ' ') !== false) {
        [$firstname, $lastname] = array_map('trim', explode(' ', $fullname, 2));
    }

    // Build field_values: all scalars + lists + select + stations (table) +
    // travelorcommute derived
    $fieldValues = $ds['scalars'] ?? [];
    $fieldValues['firstname']       = $firstname;
    $fieldValues['lastname']        = $lastname;
    $fieldValues['moving']          = yesNoToJaNein($ds['checkboxes']['moving']  ?? null);
    $fieldValues['commute']         = yesNoToJaNein($ds['checkboxes']['commute'] ?? null);
    $fieldValues['travel']          = yesNoToJaNein($ds['checkboxes']['travel']  ?? null);
    $fieldValues['travelorcommute'] = $fieldValues['travelorcommute'] ?? $fieldValues['travel'];

    foreach (($ds['lists'] ?? []) as $listKey => $listVals) {
        $fieldValues[$listKey] = $listVals;
    }
    foreach (($ds['optional'] ?? []) as $optKey => $optVal) {
        $fieldValues['optional.' . $optKey] = $optVal;
    }
    $fieldValues['stations'] = $ds['stations'] ?? [];

    $candName = DEMO_TAG . ' ' . $ds['name'] . ' — ' . ($ds['scalars']['target-position'] ?? '');

    printf("[cand] Creating '%s' in %s ...\n", $ds['name'], $collSlug);
    $body = requireOk(httpJson('POST', "$base/candidates", [
        'name'         => $candName,
        'form_id'      => $formId,
        'template_id'  => $tplId,
        'status'       => 'reviewed',
        'field_values' => $fieldValues,
    ], $cookies), "create candidate {$ds['slug']}");
    $candidateId = $body['candidate']['id'];

    // Explicit overrides too — guarantees the resolver picks our values even
    // if source/fallback routing changes in the future.
    $overrides = $fieldValues;
    requireOk(httpJson('PUT', "$base/candidates/$candidateId/variables", [
        'overrides' => $overrides,
    ], $cookies), "set variables for $candidateId");

    $createdCandidateIds[] = ['id' => $candidateId, 'tpl' => $tplId, 'name' => $ds['name']];
    printf("       %s\n", $candidateId);
}

// ---------------------------------------------------------------------------
// Optional: generate filled docs inside the app so they appear under
// each candidate's Documents list.
// ---------------------------------------------------------------------------

if ($generate) {
    printf("[gen ] Generating filled documents in-app ...\n");
    foreach ($createdCandidateIds as $c) {
        $resp = httpJson('POST', "$base/candidates/{$c['id']}/generate/{$c['tpl']}", null, $cookies);
        if ($resp['status'] >= 200 && $resp['status'] < 300 && !empty($resp['body']['success'])) {
            printf("       %s  (%s) -> document %s\n",
                $c['name'],
                $c['id'],
                $resp['body']['document']['id'] ?? '?'
            );
        } else {
            fwrite(STDERR, sprintf("       WARN: generate failed for %s (HTTP %d): %s\n",
                $c['name'], $resp['status'], json_encode($resp['body'], JSON_UNESCAPED_UNICODE)
            ));
        }
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

printf("\nDONE.\n");
printf("  API         : %s\n", $apiUrl);
printf("  User id     : %d\n", $userId);
printf("  Templates   : %d\n", count($templateIds));
printf("  Collections : %d\n", count($formIds));
printf("  Candidates  : %d\n", count($createdCandidateIds));
printf("\nOpen the plugin UI (/plugins/synaform) — the two '%s' collections\n", DEMO_TAG);
printf("with their 6 candidates should now be visible. Re-run with --wipe\n");
printf("to clear any previous demo install before seeding.\n");
