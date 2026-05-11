<?php

declare(strict_types=1);

/**
 * Synaform demo seed — fashion HR candidate profiles (German).
 *
 * Produces, fully offline and repeatable:
 *   - demo-template1.docx  Executive/Senior profile layout
 *   - demo-template2.docx  Retail Store Manager profile layout (compact)
 *   - collections.json     2 Collections with every variable set + 6 datasets
 *   - filled/*.docx        One filled DOCX per candidate, using the collection's
 *                          template, exercising scalars, checkboxes, lists and
 *                          {{stations.*.N}} row cloning.
 *
 * Run:
 *   php tests/demo/seed-demo.php
 *
 * PhpWord is auto-detected from several common locations (workspace Synaplan
 * instance, docker backend mount, local vendor/). All other operations use
 * pure PHP ZipArchive + regex — the same primitives as phase-a/phase-b tests.
 */

$autoloadCandidates = [
    '/wwwroot/synaplan/backend/vendor/autoload.php',
    '/var/www/backend/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];
$loaded = false;
foreach ($autoloadCandidates as $path) {
    if (is_file($path)) {
        require $path;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    fwrite(STDERR, "FAIL: PhpOffice\\PhpWord autoloader not found. Tried:\n  - "
        . implode("\n  - ", $autoloadCandidates) . "\n");
    exit(1);
}

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

// ---------------------------------------------------------------------------
// Output paths
// ---------------------------------------------------------------------------

$outDir     = __DIR__;
$filledDir  = $outDir . '/filled';
$template1  = $outDir . '/demo-template1.docx';
$template2  = $outDir . '/demo-template2.docx';
$jsonFile   = $outDir . '/collections.json';

if (!is_dir($filledDir) && !mkdir($filledDir, 0777, true) && !is_dir($filledDir)) {
    fwrite(STDERR, "FAIL: cannot create $filledDir\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Variable catalog — mirrors tests/demo/example-variables.md
// ---------------------------------------------------------------------------

$variableCatalog = [
    // type => list of [key, description]
    'scalar' => [
        ['target-position',  'Die offene Stelle (Zielposition), aus dem Formular.'],
        ['month',            'Monat der Profilerstellung.'],
        ['year',             'Jahr der Profilerstellung.'],
        ['fullname',         'Vollständiger Name (aus Lebenslauf).'],
        ['address1',         'Straße und Hausnummer.'],
        ['address2',         'Ort.'],
        ['zip',              'Postleitzahl.'],
        ['birthdate',        'Geburtsdatum.'],
        ['nationality',      'Nationalität (Formular).'],
        ['maritalstatus',    'Familienstand (Formular).'],
        ['number',           'Telefonnummer.'],
        ['email',            'E-Mail-Adresse.'],
        ['currentposition',  'Aktuelle Position (Lebenslauf).'],
        ['moving',           'Umzugsbereitschaft (Ja / Nein).'],
        ['travelorcommute',  'Pendel-/Reisebereitschaft (Ja / Nein).'],
        ['noticeperiod',     'Kündigungsfrist.'],
        ['currentansalary',  'Aktuelles Bruttojahresgehalt.'],
        ['expectedansalary', 'Erwartetes Bruttojahresgehalt.'],
        ['education',        'Ausbildung und Studium.'],
        ['workinghours',     'Vertragliche Arbeitszeit.'],
    ],
    'list' => [
        ['relevantposlist',          'Vorherige relevante Positionen (manuell gepflegt).'],
        ['relevantfortargetposlist', 'Für die Zielposition relevante Erfahrung (Direct Reports, P&L, Store-Fläche…).'],
        ['languageslist',            'Sprachkenntnisse mit Level.'],
        ['benefitslist',             'Benefits (Firmenwagen, Bonus, bAV…).'],
        ['otherskillslist',          'Sonstige Kenntnisse (Tools, Software, Zertifikate).'],
    ],
    'checkbox' => [
        ['checkb.moving.yes',  'Umzugsbereitschaft Ja.'],
        ['checkb.moving.no',   'Umzugsbereitschaft Nein.'],
        ['checkb.commute.yes', 'Pendelbereitschaft Ja.'],
        ['checkb.commute.no',  'Pendelbereitschaft Nein.'],
        ['checkb.travel.yes',  'Reisebereitschaft Ja.'],
        ['checkb.travel.no',   'Reisebereitschaft Nein.'],
    ],
    'optional' => [
        ['optional.firmenwagen', 'Nur ausgeben, wenn Firmenwagen im Formular aktiviert.'],
    ],
    'station' => [
        ['stations.employer.N',  'Arbeitgeber pro Station.'],
        ['stations.time.N',      'Zeitraum pro Station.'],
        ['stations.positions.N', 'Berufsbezeichnung pro Station.'],
        ['stations.details.N',   'Detailtexte pro Station (multi-line mit Datumsheadern und Bullets).'],
    ],
];

/** Flat list of every placeholder across categories (used as Collection.fields). */
function flattenCatalog(array $cat): array
{
    $out = [];
    foreach ($cat as $type => $rows) {
        foreach ($rows as [$key, $desc]) {
            $out[] = ['key' => $key, 'type' => $type, 'description' => $desc];
        }
    }
    return $out;
}

$allFields = flattenCatalog($variableCatalog);

// ---------------------------------------------------------------------------
// Template 1 — Executive / Senior Fashion profile
// ---------------------------------------------------------------------------

function buildTemplate1(string $outPath): void
{
    $word = new PhpWord();
    $word->setDefaultFontName('Arial');
    $word->setDefaultFontSize(10);

    $section = $word->addSection([
        'marginTop' => 1000, 'marginBottom' => 1000,
        'marginLeft' => 1200, 'marginRight' => 1200,
    ]);

    $title      = ['bold' => true, 'size' => 18, 'color' => '1F3A93'];
    $h2         = ['bold' => true, 'size' => 12, 'color' => '1F3A93'];
    $label      = ['bold' => true, 'size' => 10];
    $value      = ['size' => 10];
    $small      = ['size' => 9, 'color' => '666666'];
    $hrLine     = ['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '1F3A93'];
    $rowLabel   = 2600;
    $rowValue   = 5900;

    $section->addText('KANDIDATENPROFIL — {{month}} {{year}}', $title, ['alignment' => 'center', 'spaceAfter' => 120]);
    $section->addText('{{fullname}}', ['bold' => true, 'size' => 14], ['alignment' => 'center']);
    $section->addText('für die Position: {{target-position}}', $small, ['alignment' => 'center', 'spaceAfter' => 240]);

    $section->addText('PERSÖNLICHE DATEN', $h2);
    $section->addLine($hrLine);
    $pd = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);
    foreach ([
        ['Adresse:',       '{{address1}}, {{zip}} {{address2}}'],
        ['Geburtsdatum:',  '{{birthdate}}'],
        ['Nationalität:',  '{{nationality}}'],
        ['Familienstand:', '{{maritalstatus}}'],
        ['Telefon:',       '{{number}}'],
        ['E-Mail:',        '{{email}}'],
    ] as [$l, $v]) {
        $pd->addRow();
        $pd->addCell($rowLabel)->addText($l, $label);
        $pd->addCell($rowValue)->addText($v, $value);
    }
    $section->addTextBreak();

    $section->addText('AKTUELLE POSITION', $h2);
    $section->addLine($hrLine);
    $section->addText('{{currentposition}}', $value, ['spaceAfter' => 180]);

    $section->addText('AUSBILDUNG', $h2);
    $section->addLine($hrLine);
    $section->addText('{{education}}', $value, ['spaceAfter' => 180]);

    $section->addText('BERUFLICHE STATIONEN', $h2);
    $section->addLine($hrLine);
    $st = $section->addTable(['borderSize' => 2, 'borderColor' => 'BBBBBB', 'cellMargin' => 60]);
    $st->addRow();
    $st->addCell(2200)->addText('Zeitraum',    $label);
    $st->addCell(3000)->addText('Unternehmen', $label);
    $st->addCell(3300)->addText('Details',     $label);
    $st->addRow();
    $st->addCell(2200)->addText('{{stations.time.N}}',      $value);
    $st->addCell(3000)->addText('{{stations.employer.N}}' . "\n" . '{{stations.positions.N}}', $value);
    $st->addCell(3300)->addText('{{stations.details.N}}',   $value);
    $section->addTextBreak();

    $section->addText('RELEVANTE VORHERIGE POSITIONEN', $h2);
    $section->addLine($hrLine);
    $section->addText('{{relevantposlist}}', $value, ['spaceAfter' => 180]);

    $section->addText('RELEVANTE ERFAHRUNG FÜR DIE ZIELPOSITION', $h2);
    $section->addLine($hrLine);
    $section->addText('{{relevantfortargetposlist}}', $value, ['spaceAfter' => 180]);

    $section->addText('SPRACHEN', $h2);
    $section->addLine($hrLine);
    $section->addText('{{languageslist}}', $value, ['spaceAfter' => 180]);

    $section->addText('SONSTIGE KENNTNISSE', $h2);
    $section->addLine($hrLine);
    $section->addText('{{otherskillslist}}', $value, ['spaceAfter' => 180]);

    $section->addText('KONDITIONEN', $h2);
    $section->addLine($hrLine);
    $cond = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);
    foreach ([
        ['Kündigungsfrist:',    '{{noticeperiod}}'],
        ['Aktuelles Gehalt:',   '{{currentansalary}}'],
        ['Gehaltsvorstellung:', '{{expectedansalary}}'],
        ['Arbeitszeit:',        '{{workinghours}}'],
        ['Firmenwagen:',        '{{optional.firmenwagen}}'],
    ] as [$l, $v]) {
        $cond->addRow();
        $cond->addCell($rowLabel)->addText($l, $label);
        $cond->addCell($rowValue)->addText($v, $value);
    }
    $section->addTextBreak();

    $section->addText('BEREITSCHAFTEN', $h2);
    $section->addLine($hrLine);
    $av = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);
    foreach ([
        ['Umzugsbereitschaft:',         '{{checkb.moving.yes}}  Ja     {{checkb.moving.no}}  Nein', '{{moving}}'],
        ['Pendelbereitschaft:',         '{{checkb.commute.yes}}  Ja     {{checkb.commute.no}}  Nein', ''],
        ['Reisebereitschaft:',          '{{checkb.travel.yes}}  Ja     {{checkb.travel.no}}  Nein', '{{travelorcommute}}'],
    ] as [$l, $v, $note]) {
        $av->addRow();
        $av->addCell(3200)->addText($l, $label);
        $av->addCell(3800)->addText($v, $value);
        $av->addCell(2500)->addText($note, $small);
    }
    $section->addTextBreak();

    $section->addText('BENEFITS', $h2);
    $section->addLine($hrLine);
    $section->addText('{{benefitslist}}', $value);

    IOFactory::createWriter($word, 'Word2007')->save($outPath);
}

// ---------------------------------------------------------------------------
// Template 2 — Retail Store Manager profile (compact)
// ---------------------------------------------------------------------------

function buildTemplate2(string $outPath): void
{
    $word = new PhpWord();
    $word->setDefaultFontName('Calibri');
    $word->setDefaultFontSize(10);

    $section = $word->addSection([
        'marginTop' => 900, 'marginBottom' => 900,
        'marginLeft' => 1100, 'marginRight' => 1100,
    ]);

    $title   = ['bold' => true, 'size' => 16, 'color' => '8B1538'];
    $h2      = ['bold' => true, 'size' => 11, 'color' => '8B1538', 'allCaps' => true];
    $label   = ['bold' => true, 'size' => 9];
    $value   = ['size' => 10];
    $small   = ['size' => 8, 'color' => '707070'];
    $hr      = ['weight' => 1, 'width' => 450, 'height' => 0, 'color' => '8B1538'];

    $section->addText('RETAIL FASHION — STORE MANAGER PROFIL', $title, ['alignment' => 'left', 'spaceAfter' => 80]);
    $section->addText('Erstellt: {{month}}/{{year}}  ·  Zielposition: {{target-position}}', $small, ['spaceAfter' => 160]);

    // Compact header block: Name + contact inline
    $head = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);
    $head->addRow();
    $c1 = $head->addCell(6500);
    $c1->addText('{{fullname}}', ['bold' => true, 'size' => 13, 'color' => '333333']);
    $c1->addText('Derzeit: {{currentposition}}', $small);
    $c2 = $head->addCell(4000);
    $c2->addText('{{number}} · {{email}}', $small, ['alignment' => 'right']);
    $c2->addText('{{address1}}, {{zip}} {{address2}}', $small, ['alignment' => 'right']);
    $c2->addText('* {{birthdate}}  ·  {{nationality}}  ·  {{maritalstatus}}', $small, ['alignment' => 'right']);
    $section->addTextBreak();

    $section->addText('KARRIERE', $h2);
    $section->addLine($hr);
    $st = $section->addTable(['borderSize' => 4, 'borderColor' => 'E0E0E0', 'cellMargin' => 60]);
    $st->addRow();
    $st->addCell(2400)->addText('{{stations.time.N}}', ['bold' => true, 'size' => 9]);
    $cellMid = $st->addCell(7800);
    $cellMid->addText('{{stations.employer.N}} — {{stations.positions.N}}', ['bold' => true, 'size' => 10]);
    $cellMid->addText('{{stations.details.N}}', $value);
    $section->addTextBreak();

    $section->addText('AUSBILDUNG', $h2);
    $section->addLine($hr);
    $section->addText('{{education}}', $value, ['spaceAfter' => 140]);

    $section->addText('RELEVANTE POSITIONEN', $h2);
    $section->addLine($hr);
    $section->addText('{{relevantposlist}}', $value, ['spaceAfter' => 140]);

    $section->addText('RELEVANT FÜR DIE ZIELPOSITION', $h2);
    $section->addLine($hr);
    $section->addText('{{relevantfortargetposlist}}', $value, ['spaceAfter' => 140]);

    $section->addText('SPRACHEN UND SKILLS', $h2);
    $section->addLine($hr);
    $skills = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);
    $skills->addRow();
    $a = $skills->addCell(5200);
    $a->addText('Sprachen', $label);
    $a->addText('{{languageslist}}', $value);
    $b = $skills->addCell(5200);
    $b->addText('Weitere Kenntnisse', $label);
    $b->addText('{{otherskillslist}}', $value);
    $section->addTextBreak();

    $section->addText('KONDITIONEN UND BEREITSCHAFT', $h2);
    $section->addLine($hr);
    $cond = $section->addTable(['borderSize' => 0, 'cellMargin' => 40]);
    foreach ([
        ['Kündigungsfrist:',    '{{noticeperiod}}'],
        ['Gehalt aktuell:',     '{{currentansalary}}'],
        ['Gehaltsvorstellung:', '{{expectedansalary}}'],
        ['Arbeitszeit:',        '{{workinghours}}'],
        ['Firmenwagen:',        '{{optional.firmenwagen}}'],
        ['Umzug:',              '{{checkb.moving.yes}} Ja · {{checkb.moving.no}} Nein  ({{moving}})'],
        ['Pendeln:',            '{{checkb.commute.yes}} Ja · {{checkb.commute.no}} Nein'],
        ['Reisen:',             '{{checkb.travel.yes}} Ja · {{checkb.travel.no}} Nein  ({{travelorcommute}})'],
    ] as [$l, $v]) {
        $cond->addRow();
        $cond->addCell(3400)->addText($l, $label);
        $cond->addCell(7000)->addText($v, $value);
    }
    $section->addTextBreak();

    $section->addText('BENEFITS', $h2);
    $section->addLine($hr);
    $section->addText('{{benefitslist}}', $value);

    IOFactory::createWriter($word, 'Word2007')->save($outPath);
}

// ---------------------------------------------------------------------------
// Fictional candidates — German fashion industry, realistic but synthetic
// ---------------------------------------------------------------------------

$candidates = [
    // Collection A — Executive / Senior Fashion
    [
        'collection' => 'A',
        'slug' => 'lena-hartmann',
        'scalars' => [
            'target-position'  => 'Head of Brand Marketing Fashion DACH',
            'month'            => 'April',
            'year'             => '2026',
            'fullname'         => 'Lena Hartmann',
            'address1'         => 'Kastanienallee 42',
            'address2'         => 'Düsseldorf',
            'zip'              => '40233',
            'birthdate'        => '14.06.1984',
            'nationality'      => 'deutsch',
            'maritalstatus'    => 'verheiratet, 1 Kind',
            'number'           => '+49 171 5540987',
            'email'            => 'lena.hartmann@example.de',
            'currentposition'  => 'Head of Brand Marketing bei Moda Rhein AG',
            'moving'           => 'Ja',
            'travelorcommute'  => 'Ja',
            'noticeperiod'     => '3 Monate zum Quartalsende',
            'currentansalary'  => 'EUR 112.000 brutto p.a. + 18% Bonus',
            'expectedansalary' => 'EUR 135.000 brutto p.a. + 20% Bonus',
            'education'        => 'Diplom-Betriebswirtin (FH), Schwerpunkt Marketing — Hochschule Pforzheim, 2008',
            'workinghours'     => '40 Std./Woche, Vollzeit',
        ],
        'checkboxes' => ['moving' => 'yes', 'commute' => 'yes', 'travel' => 'yes'],
        'optional'   => ['firmenwagen' => 'BMW 3er Touring, auch privat nutzbar'],
        'lists' => [
            'relevantposlist' => [
                'Head of Brand Marketing — Moda Rhein AG',
                'Senior Brand Manager Womenswear — Nordstern Textilwerke GmbH',
                'Marketing Manager Lifestyle — Hamburg Retail Fashion AG',
            ],
            'relevantfortargetposlist' => [
                '12 Direct Reports (Category, Content, Social, PR)',
                'Marketing-Budget 8,5 Mio EUR p.a.',
                'P&L-Mitverantwortung 180 Mio EUR Umsatz',
                'Verantwortlich für 24 Retail-Flächen in 6 Ländern',
                'Einstellungs- und Kündigungsverantwortung für das Marketingteam',
            ],
            'languageslist' => [
                'Deutsch (Muttersprache)',
                'Englisch (verhandlungssicher, C2)',
                'Französisch (fließend, C1)',
                'Italienisch (konversationsfähig, B1)',
            ],
            'benefitslist' => [
                'Firmenwagen (Mittelklasse, privat)',
                'Variable Vergütung 18%',
                'Betriebliche Altersvorsorge (10% AG-Zuschuss)',
                '30 Urlaubstage + 2 Brauchtumstage',
                'Home-Office 2 Tage/Woche',
                'Mitarbeiterrabatt 40%',
            ],
            'otherskillslist' => [
                'Adobe Creative Cloud (Power User)',
                'Salesforce Marketing Cloud',
                'Google Analytics 4 / Looker Studio',
                'SAP ERP (Modul SD/MM)',
                'Fashion-GDS (TradeBeyond, NuORDER)',
            ],
        ],
        'stations' => [
            [
                'time'      => '03/2022 – heute',
                'employer'  => 'Moda Rhein AG, Düsseldorf',
                'positions' => 'Head of Brand Marketing',
                'details'   => <<<D
03/2022 – heute
Head of Brand Marketing DACH
- Globale Markenstrategie für 4 Fashion-Labels mit 180 Mio EUR Umsatz
- Führung eines 12-köpfigen Marketingteams über 3 Standorte
- Relaunch der Hauptmarke 2023 mit +22% Markenbekanntheit
- Budgetverantwortung 8,5 Mio EUR p.a.
D,
            ],
            [
                'time'      => '05/2018 – 02/2022',
                'employer'  => 'Nordstern Textilwerke GmbH, Münster',
                'positions' => 'Senior Brand Manager Womenswear',
                'details'   => <<<D
05/2018 – 02/2022
Senior Brand Manager Womenswear
- Kategorieverantwortung Womenswear (EUR 65 Mio Umsatz)
- Aufbau Social-Commerce-Kanäle (Instagram, TikTok) auf 950k Follower
- Einführung eines saisonalen Kollaborations-Formats mit 4 Designerinnen
- Kampagnenführung in 12 europäischen Märkten
D,
            ],
            [
                'time'      => '09/2014 – 04/2018',
                'employer'  => 'Hamburg Retail Fashion AG, Hamburg',
                'positions' => 'Marketing Manager Lifestyle',
                'details'   => <<<D
09/2014 – 04/2018
Marketing Manager Lifestyle
- Omni-Channel-Kampagnen für 45 Stores in DE/AT
- Einführung CRM-gestützter Kundensegmentierung (+18% Wiederkauf)
- POS-Konzept für Flagship Hamburg Mönckebergstraße
D,
            ],
        ],
    ],
    [
        'collection' => 'A',
        'slug' => 'maximilian-graf',
        'scalars' => [
            'target-position'  => 'Director E-Commerce & Digital Fashion',
            'month'            => 'April',
            'year'             => '2026',
            'fullname'         => 'Maximilian Graf',
            'address1'         => 'Isarstraße 18',
            'address2'         => 'München',
            'zip'              => '80469',
            'birthdate'        => '02.11.1979',
            'nationality'      => 'deutsch / österreichisch',
            'maritalstatus'    => 'ledig',
            'number'           => '+49 160 7788301',
            'email'            => 'max.graf@example.de',
            'currentposition'  => 'Head of E-Commerce bei Schmidt & Falkenberg Fashion',
            'moving'           => 'Nein',
            'travelorcommute'  => 'Ja',
            'noticeperiod'     => '6 Monate zum Halbjahresende',
            'currentansalary'  => 'EUR 138.000 brutto p.a. + 25% Bonus',
            'expectedansalary' => 'EUR 165.000 brutto p.a. + 25–30% Bonus',
            'education'        => 'Master of Science, Wirtschaftsinformatik — TU München, 2005',
            'workinghours'     => '40 Std./Woche, Vollzeit mit flexiblem Home-Office',
        ],
        'checkboxes' => ['moving' => 'no', 'commute' => 'yes', 'travel' => 'yes'],
        'optional'   => ['firmenwagen' => 'Audi A6 Avant e-tron'],
        'lists' => [
            'relevantposlist' => [
                'Head of E-Commerce — Schmidt & Falkenberg Fashion (seit 2019)',
                'Senior E-Commerce Manager — Beckert Mode GmbH (2014–2019)',
                'Online Marketing Lead — Aachener Couture Group (2010–2014)',
            ],
            'relevantfortargetposlist' => [
                '9 Direct Reports, 42 FTE im Digital-Bereich',
                'Online-Umsatzverantwortung 95 Mio EUR p.a.',
                'Conversion-Rate-Steigerung +38% in 3 Jahren',
                'Replatforming Shopware 6 → Commercetools geleitet',
                'BR-erfahren (Einführung Betriebsvereinbarung Home-Office)',
            ],
            'languageslist' => [
                'Deutsch (Muttersprache)',
                'Englisch (verhandlungssicher, C2)',
                'Spanisch (fließend, B2)',
            ],
            'benefitslist' => [
                'Firmenwagen (Oberklasse, privat)',
                'Variable Vergütung 25%',
                'Aktienoptionsprogramm',
                'bAV mit Entgeltumwandlung',
                '30 Urlaubstage',
                'Mobiles Arbeiten 3 Tage/Woche',
            ],
            'otherskillslist' => [
                'Commercetools, Shopware 6, Shopify Plus',
                'SAP Hybris Commerce',
                'Adobe Analytics, Tealium',
                'A/B-Testing (Optimizely, VWO)',
                'Agile Leadership (Scrum@Scale zertifiziert)',
            ],
        ],
        'stations' => [
            [
                'time'      => '04/2019 – heute',
                'employer'  => 'Schmidt & Falkenberg Fashion, München',
                'positions' => 'Head of E-Commerce',
                'details'   => <<<D
04/2019 – heute
Head of E-Commerce
- Gesamtverantwortung für 9 Online-Shops in 14 Ländern
- Umsatzwachstum von 38 auf 95 Mio EUR in 4 Jahren
- Replatforming auf Commercetools-Stack in 11 Monaten
- Aufbau Customer-Data-Platform und CRM-Automatisierung
D,
            ],
            [
                'time'      => '01/2014 – 03/2019',
                'employer'  => 'Beckert Mode GmbH, Köln',
                'positions' => 'Senior E-Commerce Manager',
                'details'   => <<<D
01/2014 – 03/2019
Senior E-Commerce Manager
- Betrieb und Weiterentwicklung des B2C-Shops (EUR 28 Mio Umsatz)
- Einführung Marketplace-Anbindung (Zalando, About You)
- Team: 6 FTE Online-Marketing + 3 FTE Shop-Ops
D,
            ],
            [
                'time'      => '08/2010 – 12/2013',
                'employer'  => 'Aachener Couture Group, Aachen',
                'positions' => 'Online Marketing Lead',
                'details'   => <<<D
08/2010 – 12/2013
Online Marketing Lead
- Performance-Marketing DACH (SEA, Paid Social)
- CRM-Programm von 120k auf 520k aktive Kontakte skaliert
D,
            ],
        ],
    ],
    [
        'collection' => 'A',
        'slug' => 'sophie-wagner',
        'scalars' => [
            'target-position'  => 'Business Unit Director Sportswear & Fashion',
            'month'            => 'April',
            'year'             => '2026',
            'fullname'         => 'Sophie Wagner',
            'address1'         => 'Herrnhuter Weg 7',
            'address2'         => 'Stuttgart',
            'zip'              => '70193',
            'birthdate'        => '29.03.1976',
            'nationality'      => 'deutsch',
            'maritalstatus'    => 'verheiratet, 2 Kinder',
            'number'           => '+49 175 3309921',
            'email'            => 'sophie.wagner@example.de',
            'currentposition'  => 'Business Unit Director Sport & Lifestyle bei Stuttgarter Textilhaus KG',
            'moving'           => 'Ja',
            'travelorcommute'  => 'Ja',
            'noticeperiod'     => '6 Monate zum Quartalsende',
            'currentansalary'  => 'EUR 168.000 brutto p.a. + 35% Bonus + LTI',
            'expectedansalary' => 'EUR 195.000 brutto p.a. + 35–40% Bonus + LTI',
            'education'        => 'Dipl.-Kauffrau — Universität Mannheim, 2001; INSEAD Executive Programme, 2017',
            'workinghours'     => 'Vollzeit, 40 Std./Woche',
        ],
        'checkboxes' => ['moving' => 'yes', 'commute' => 'yes', 'travel' => 'yes'],
        'optional'   => ['firmenwagen' => 'Mercedes GLE 450, Vollausstattung'],
        'lists' => [
            'relevantposlist' => [
                'Business Unit Director Sport & Lifestyle — Stuttgarter Textilhaus KG',
                'Head of Category Sportswear — Nordstern Textilwerke GmbH',
                'Director Buying Sport — Moda Rhein AG',
            ],
            'relevantfortargetposlist' => [
                '18 Direct Reports, 220 FTE Business Unit',
                'P&L-Verantwortung EUR 340 Mio Umsatz',
                'Store-Fläche gesamt 18.500 m² in DE/AT/CH',
                '4 eigene Produktionsstätten in Portugal und Rumänien',
                'Langjährige BR-Erfahrung (Großverhandlungen, IG Metall)',
                'Einstellungs- und Restrukturierungsverantwortung (2020 Turnaround)',
            ],
            'languageslist' => [
                'Deutsch (Muttersprache)',
                'Englisch (verhandlungssicher, C2)',
                'Französisch (verhandlungssicher, C1)',
                'Portugiesisch (fließend, B2)',
            ],
            'benefitslist' => [
                'Firmenwagen Oberklasse, privat nutzbar',
                'Variable Vergütung 35% + LTI',
                'Altersvorsorge (12% AG-Zuschuss)',
                '30 Urlaubstage + Sabbatical-Option',
                'Mitarbeiterrabatt 50%',
            ],
            'otherskillslist' => [
                'SAP S/4HANA (Retail)',
                'Dynamics 365 F&O',
                'Planning-Tools (Centric PLM, RLM)',
                'Category-Management-Zertifikat (ECR Europe)',
                'Nachhaltigkeits-Reporting (GRI, CSRD)',
            ],
        ],
        'stations' => [
            [
                'time'      => '06/2020 – heute',
                'employer'  => 'Stuttgarter Textilhaus KG, Stuttgart',
                'positions' => 'Business Unit Director Sport & Lifestyle',
                'details'   => <<<D
06/2020 – heute
Business Unit Director Sport & Lifestyle
- Gesamtverantwortung BU Sport & Lifestyle (340 Mio EUR Umsatz, 220 FTE)
- Turnaround der BU: EBIT-Marge von -2% auf +9% in 3 Jahren
- Neuausrichtung Category-Portfolio (Sportswear, Athleisure, Outdoor)
- Verhandlung der neuen Rahmenvereinbarung mit dem Gesamtbetriebsrat
- Aufbau Nachhaltigkeits-Reporting (CSRD-konform ab 2024)

06/2020 – 12/2021
Interim Geschäftsführung BU Fashion
- Parallel zur Director-Rolle, 8 Monate interim GF
- Führung durch Covid-Phase und Supply-Chain-Krise
D,
            ],
            [
                'time'      => '02/2015 – 05/2020',
                'employer'  => 'Nordstern Textilwerke GmbH, Münster',
                'positions' => 'Head of Category Sportswear',
                'details'   => <<<D
02/2015 – 05/2020
Head of Category Sportswear
- Kategorie-P&L EUR 140 Mio
- Launch der Eigenmarke „Nord Active" (+EUR 22 Mio in 2 Jahren)
- Einführung Direct-to-Consumer-Kanal mit eigenem Flagship Berlin
D,
            ],
            [
                'time'      => '10/2008 – 01/2015',
                'employer'  => 'Moda Rhein AG, Düsseldorf',
                'positions' => 'Director Buying Sport',
                'details'   => <<<D
10/2008 – 01/2015
Director Buying Sport
- Einkaufsvolumen EUR 85 Mio p.a.
- Aufbau strategischer Lieferanten in Portugal und Rumänien
- Führung eines 9-köpfigen Einkaufsteams
D,
            ],
        ],
    ],
    // Collection B — Retail Store Manager
    [
        'collection' => 'B',
        'slug' => 'tobias-krueger',
        'scalars' => [
            'target-position'  => 'Store Manager Flagship Berlin Kurfürstendamm',
            'month'            => 'April',
            'year'             => '2026',
            'fullname'         => 'Tobias Krüger',
            'address1'         => 'Maximilianstraße 12',
            'address2'         => 'München',
            'zip'              => '80539',
            'birthdate'        => '18.08.1988',
            'nationality'      => 'deutsch',
            'maritalstatus'    => 'ledig',
            'number'           => '+49 152 4412877',
            'email'            => 'tobias.krueger@example.de',
            'currentposition'  => 'Store Manager Flagship München Kaufingerstraße',
            'moving'           => 'Ja',
            'travelorcommute'  => 'Ja',
            'noticeperiod'     => '3 Monate zum Monatsende',
            'currentansalary'  => 'EUR 62.000 brutto p.a. + 15% Bonus',
            'expectedansalary' => 'EUR 78.000 brutto p.a. + 20% Bonus',
            'education'        => 'Handelsfachwirt (IHK) — Handelskammer München, 2012; Einzelhandelskaufmann, 2009',
            'workinghours'     => '40 Std./Woche, 6-Tage-Woche mit rollierenden freien Tagen',
        ],
        'checkboxes' => ['moving' => 'yes', 'commute' => 'yes', 'travel' => 'yes'],
        'optional'   => ['firmenwagen' => ''],
        'lists' => [
            'relevantposlist' => [
                'Store Manager Flagship München — Beckert Mode GmbH',
                'Assistant Store Manager — Hamburg Retail Fashion AG',
                'Department Lead Damenoberbekleidung — Moda Rhein AG',
            ],
            'relevantfortargetposlist' => [
                '24 Mitarbeitende (davon 3 Schichtleitungen)',
                'Store-Fläche 1.850 m² auf 3 Etagen',
                'Jahresumsatz Flagship EUR 14,8 Mio',
                'Einstellungs- und Kündigungsverantwortung im Store',
                'Aufbau des Visual-Merchandising-Teams',
            ],
            'languageslist' => [
                'Deutsch (Muttersprache)',
                'Englisch (fließend, C1)',
                'Italienisch (konversationsfähig, B1)',
            ],
            'benefitslist' => [
                'Fahrtkostenzuschuss MVV',
                'Variable Vergütung 15% auf Store-KPIs',
                'bAV mit 50% AG-Zuschuss',
                '28 Urlaubstage',
                'Mitarbeiterrabatt 40%',
                'Weiterbildungsbudget EUR 1.500 p.a.',
            ],
            'otherskillslist' => [
                'Retail-KPI-Steuerung (UPT, ATV, Conversion)',
                'Workforce-Management (ATOSS)',
                'Visual-Merchandising-Zertifikat (EHI)',
                'MS Office 365, SAP Retail',
                'Mitarbeiterführung nach GROW-Modell',
            ],
        ],
        'stations' => [
            [
                'time'      => '04/2021 – heute',
                'employer'  => 'Beckert Mode GmbH, München',
                'positions' => 'Store Manager Flagship Kaufingerstraße',
                'details'   => <<<D
04/2021 – heute
Store Manager Flagship München
- Führung eines 24-köpfigen Teams inkl. 3 Schichtleitungen
- Umsatzverantwortung EUR 14,8 Mio p.a., +11% YoY 2024
- Einführung Clienteling-Programm (+19% Wiederkaufrate)
- Umbau der Damen-Etage bei laufendem Betrieb durchgeführt
D,
            ],
            [
                'time'      => '09/2017 – 03/2021',
                'employer'  => 'Hamburg Retail Fashion AG, Hamburg',
                'positions' => 'Assistant Store Manager',
                'details'   => <<<D
09/2017 – 03/2021
Assistant Store Manager, Flagship Mönckebergstraße
- Stellvertretung des Store Managers (1.400 m², 18 FTE)
- Verantwortlich für Personaleinsatzplanung und Einarbeitung
- KPIs: Conversion von 9,8% auf 12,4% gesteigert
D,
            ],
            [
                'time'      => '08/2012 – 08/2017',
                'employer'  => 'Moda Rhein AG, Düsseldorf',
                'positions' => 'Department Lead Damenoberbekleidung',
                'details'   => <<<D
08/2012 – 08/2017
Department Lead Damenoberbekleidung
- 9 direkte Mitarbeitende
- Umsatzsteigerung DOB um +22% in 3 Jahren
- Einführung eines Schulungskonzepts für Neueinsteiger
D,
            ],
        ],
    ],
    [
        'collection' => 'B',
        'slug' => 'anja-becker',
        'scalars' => [
            'target-position'  => 'Area Manager Retail Süd (DE/AT)',
            'month'            => 'April',
            'year'             => '2026',
            'fullname'         => 'Anja Becker',
            'address1'         => 'Rosengartenstraße 5',
            'address2'         => 'Nürnberg',
            'zip'              => '90409',
            'birthdate'        => '07.02.1983',
            'nationality'      => 'deutsch',
            'maritalstatus'    => 'verheiratet',
            'number'           => '+49 163 2298774',
            'email'            => 'anja.becker@example.de',
            'currentposition'  => 'District Manager Süddeutschland bei Aachener Couture Group',
            'moving'           => 'Nein',
            'travelorcommute'  => 'Ja',
            'noticeperiod'     => '3 Monate zum Quartalsende',
            'currentansalary'  => 'EUR 82.000 brutto p.a. + 18% Bonus',
            'expectedansalary' => 'EUR 95.000 brutto p.a. + 20% Bonus',
            'education'        => 'Bachelor of Arts, Modemanagement — AMD Akademie Düsseldorf, 2007',
            'workinghours'     => 'Vollzeit, 40 Std./Woche, hoher Reiseanteil',
        ],
        'checkboxes' => ['moving' => 'no', 'commute' => 'yes', 'travel' => 'yes'],
        'optional'   => ['firmenwagen' => 'VW Passat Variant, Dienstwagen mit Privatnutzung'],
        'lists' => [
            'relevantposlist' => [
                'District Manager Süd — Aachener Couture Group (seit 2020)',
                'Store Manager — Stuttgarter Textilhaus KG (2015–2020)',
                'Assistant Buyer — Nordstern Textilwerke GmbH (2010–2015)',
            ],
            'relevantfortargetposlist' => [
                '11 Stores unter Verantwortung (DE/AT)',
                '148 Mitarbeitende indirekt geführt',
                'Store-Fläche gesamt 9.200 m²',
                'Umsatzverantwortung EUR 42 Mio p.a.',
                'Einstellungen und Kündigungen für Store-Manager-Ebene',
                'Turnaround von 2 unterperformenden Stores in 18 Monaten',
            ],
            'languageslist' => [
                'Deutsch (Muttersprache)',
                'Englisch (verhandlungssicher, C1)',
                'Spanisch (B1)',
            ],
            'benefitslist' => [
                'Firmenwagen Mittelklasse, privat nutzbar',
                'Variable Vergütung 18% auf Area-KPIs',
                'bAV, 10% AG-Zuschuss',
                '30 Urlaubstage',
                'Reisekostenpauschale',
            ],
            'otherskillslist' => [
                'Multi-Store-KPI-Steuerung',
                'Field-Coaching und Training-on-the-Job',
                'SAP Retail, Microsoft Dynamics',
                'Lean-Retail-Methoden',
                'Arbeitsrecht-Grundkenntnisse (SHK-Zertifikat)',
            ],
        ],
        'stations' => [
            [
                'time'      => '07/2020 – heute',
                'employer'  => 'Aachener Couture Group, Nürnberg (Regionalbüro)',
                'positions' => 'District Manager Süddeutschland',
                'details'   => <<<D
07/2020 – heute
District Manager Süddeutschland
- 11 Stores in Bayern, BW und Österreich, EUR 42 Mio Umsatz
- Direkte Führung von 11 Store Managern, 148 FTE indirekt
- Store-Eröffnung Salzburg (2022) und Augsburg (2024) verantwortet
- Turnaround-Programm für 2 defizitäre Stores erfolgreich abgeschlossen
D,
            ],
            [
                'time'      => '03/2015 – 06/2020',
                'employer'  => 'Stuttgarter Textilhaus KG, Stuttgart',
                'positions' => 'Store Manager Flagship Königstraße',
                'details'   => <<<D
03/2015 – 06/2020
Store Manager Flagship Stuttgart Königstraße
- 1.600 m², 22 FTE, EUR 11 Mio Umsatz
- Einführung der neuen Store-Rolle „Style Advisor"
- ATV-Steigerung +14% durch Personal-Training-Programm
D,
            ],
            [
                'time'      => '10/2010 – 02/2015',
                'employer'  => 'Nordstern Textilwerke GmbH, Münster',
                'positions' => 'Assistant Buyer Womenswear',
                'details'   => <<<D
10/2010 – 02/2015
Assistant Buyer Womenswear
- Unterstützung des Einkaufs für 4 Kollektionen p.a.
- Sortimentsanalyse und Lieferantenbesuche (IT, PT)
- Wechsel in Retail: Store-Manager-Programm
D,
            ],
        ],
    ],
    [
        'collection' => 'B',
        'slug' => 'david-koehler',
        'scalars' => [
            'target-position'  => 'Visual Merchandising Lead DACH',
            'month'            => 'April',
            'year'             => '2026',
            'fullname'         => 'David Köhler',
            'address1'         => 'Talstraße 29',
            'address2'         => 'Köln',
            'zip'              => '50676',
            'birthdate'        => '23.09.1986',
            'nationality'      => 'deutsch',
            'maritalstatus'    => 'ledig, in Partnerschaft',
            'number'           => '+49 177 8823451',
            'email'            => 'david.koehler@example.de',
            'currentposition'  => 'Visual Merchandising Manager bei Schmidt & Falkenberg Fashion',
            'moving'           => 'Ja',
            'travelorcommute'  => 'Ja',
            'noticeperiod'     => '3 Monate zum Monatsende',
            'currentansalary'  => 'EUR 68.000 brutto p.a. + 10% Bonus',
            'expectedansalary' => 'EUR 82.000 brutto p.a. + 12–15% Bonus',
            'education'        => 'Diplom-Designer (FH), Modedesign — HTW Berlin, 2010',
            'workinghours'     => 'Vollzeit, 40 Std./Woche, hoher Reiseanteil in DACH',
        ],
        'checkboxes' => ['moving' => 'yes', 'commute' => 'yes', 'travel' => 'yes'],
        'optional'   => ['firmenwagen' => 'Poolfahrzeug für Dienstreisen'],
        'lists' => [
            'relevantposlist' => [
                'Visual Merchandising Manager — Schmidt & Falkenberg Fashion',
                'Senior VM Stylist — Moda Rhein AG',
                'VM Specialist — Beckert Mode GmbH',
            ],
            'relevantfortargetposlist' => [
                '6 Direct Reports (VM Stylists)',
                'Verantwortlich für 38 Stores in DACH',
                'Saisonale Window-Konzepte (4 pro Jahr)',
                'Budgetverantwortung VM EUR 1,2 Mio p.a.',
                'Kollaboration mit Buying und Marketing auf Kollektions-Ebene',
            ],
            'languageslist' => [
                'Deutsch (Muttersprache)',
                'Englisch (verhandlungssicher, C1)',
                'Französisch (B2)',
            ],
            'benefitslist' => [
                'Reisekosten-Full-Service',
                'Variable Vergütung 10%',
                'bAV 8% AG-Zuschuss',
                '30 Urlaubstage',
                'Kreativ-Weiterbildungsbudget EUR 2.000 p.a.',
                'Mitarbeiterrabatt 45%',
            ],
            'otherskillslist' => [
                'Adobe Creative Suite (Illustrator, InDesign, Photoshop)',
                'SketchUp, Vectorworks (Store-Planung)',
                'Retail-Design-Trends und Materialkunde',
                'Projektmanagement nach PRINCE2 Foundation',
                'Fotografie und Produkt-Styling',
            ],
        ],
        'stations' => [
            [
                'time'      => '11/2019 – heute',
                'employer'  => 'Schmidt & Falkenberg Fashion, Köln',
                'positions' => 'Visual Merchandising Manager DACH',
                'details'   => <<<D
11/2019 – heute
Visual Merchandising Manager DACH
- Führung eines 6-köpfigen VM-Teams
- Konzept und Rollout von 4 Saison-Kampagnen p.a. in 38 Stores
- Budgetverantwortung EUR 1,2 Mio
- Entwicklung modularer VM-Elemente (Kosteneinsparung 22%)
D,
            ],
            [
                'time'      => '05/2015 – 10/2019',
                'employer'  => 'Moda Rhein AG, Düsseldorf',
                'positions' => 'Senior VM Stylist',
                'details'   => <<<D
05/2015 – 10/2019
Senior Visual Merchandising Stylist
- Umsetzung der Kollektionspräsentation in Flagship-Stores
- Einarbeitung von 14 neuen VM-Kolleginnen
- Aufbau VM-Handbuch für das Retail-Netzwerk
D,
            ],
            [
                'time'      => '06/2010 – 04/2015',
                'employer'  => 'Beckert Mode GmbH, München',
                'positions' => 'Visual Merchandising Specialist',
                'details'   => <<<D
06/2010 – 04/2015
Visual Merchandising Specialist
- Einstieg nach Diplom in der Zentrale, später Außendienst
- VM-Support für 12 Stores in Bayern
- Saisonale Fotoshootings und Look-Dokumentation
D,
            ],
        ],
    ],
];

// ---------------------------------------------------------------------------
// Collections definition
// ---------------------------------------------------------------------------

$collections = [
    'A' => [
        'id'          => 1,
        'slug'        => 'fashion-executive-profiles',
        'name'        => 'Fashion Executive Profiles (DE)',
        'description' => 'Senior und Executive Rollen in der Modebranche — Marketing, E-Commerce, Business Unit. Verwendet das vollständige Variablen-Set aus example-variables.md.',
        'language'    => 'de',
        'template'    => 'demo-template1.docx',
        'fields'      => $allFields,
    ],
    'B' => [
        'id'          => 2,
        'slug'        => 'fashion-retail-manager-profiles',
        'name'        => 'Fashion Retail Manager Profiles (DE)',
        'description' => 'Store Manager, Area Manager und Visual Merchandising Rollen im stationären Fashion-Retail. Identisches Variablen-Set, kompakteres Template-Layout (demo-template2.docx).',
        'language'    => 'de',
        'template'    => 'demo-template2.docx',
        'fields'      => $allFields,
    ],
];

// ---------------------------------------------------------------------------
// Template fill routine — self-contained (ZipArchive + regex)
// ---------------------------------------------------------------------------

const CHECKBOX_YES = '☒';
const CHECKBOX_NO  = '☐';

function escapeForWordXml(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    if (preg_match('/\r|\n/', $escaped) === 1) {
        $escaped = preg_replace('/\r\n|\r|\n/', '</w:t><w:br/><w:t>', $escaped);
    }
    return $escaped;
}

/** Find a <w:tr> containing any of the {{stations.*.N}} placeholders and duplicate it. */
function cloneStationRows(string $xml, int $count): string
{
    if ($count < 1) {
        return $xml;
    }
    $pattern = '#<w:tr\b[^>]*>(?:(?!</w:tr>).)*?\{\{stations\.[a-zA-Z]+\.N\}\}(?:(?!</w:tr>).)*?</w:tr>#s';
    return preg_replace_callback($pattern, function (array $m) use ($count): string {
        $orig = $m[0];
        $out = '';
        for ($i = 1; $i <= $count; $i++) {
            $copy = preg_replace_callback(
                '/\{\{stations\.([a-zA-Z]+)\.N\}\}/',
                fn(array $mm) => '{{stations.' . $mm[1] . '.N#' . $i . '}}',
                $orig
            );
            $out .= $copy;
        }
        return $out;
    }, $xml, 1);
}

function applyScalarReplacements(string $xml, array $pairs): string
{
    foreach ($pairs as $key => $value) {
        $escaped     = escapeForWordXml((string) $value);
        $placeholder = '{{' . $key . '}}';
        $xml = str_replace($placeholder, $escaped, $xml);
    }
    return $xml;
}

function expandListPlaceholders(string $xml, array $listValues): string
{
    foreach ($listValues as $key => $items) {
        $placeholder = '{{' . $key . '}}';
        if (!str_contains($xml, $placeholder)) {
            continue;
        }
        $pattern = '#<w:p\b[^>]*>(?:(?!</w:p>).)*?' . preg_quote($placeholder, '#') . '(?:(?!</w:p>).)*?</w:p>#s';
        $xml = preg_replace_callback($pattern, function (array $m) use ($placeholder, $items): string {
            if (empty($items)) {
                return '';
            }
            $out = '';
            foreach ($items as $item) {
                $escaped = escapeForWordXml((string) $item);
                $out .= str_replace($placeholder, $escaped, $m[0]);
            }
            return $out;
        }, $xml);
    }
    return $xml;
}

function parseStationDetails(string $details): array
{
    $lines = preg_split('/\r\n|\r|\n/', $details) ?: [];
    $dateRange = '~^\s*\d{1,2}[./]\d{4}\s*[\-–—]{1,2}\s*(?:heute|today|laufend|\d{1,2}[./]\d{4})\s*$~iu';
    $yearRange = '~^\s*\d{4}\s*[\-–—]{1,2}\s*(?:heute|today|laufend|\d{4})\s*$~iu';
    $bullet    = '~^[\-*•·–—]\s+(.*)$~u';
    $blocks = [];
    foreach ($lines as $line) {
        $s = trim($line);
        if ($s === '') {
            $blocks[] = ['type' => 'spacer'];
            continue;
        }
        if (preg_match($dateRange, $s) || preg_match($yearRange, $s)) {
            $blocks[] = ['type' => 'date', 'text' => $s];
            continue;
        }
        if (preg_match($bullet, $s, $bm)) {
            $blocks[] = ['type' => 'bullet', 'text' => trim($bm[1])];
            continue;
        }
        $blocks[] = ['type' => 'text', 'text' => $s];
    }
    $collapsed = [];
    $lastSpacer = false;
    foreach ($blocks as $b) {
        if ($b['type'] === 'spacer') {
            if ($lastSpacer) {
                continue;
            }
            $lastSpacer = true;
        } else {
            $lastSpacer = false;
        }
        $collapsed[] = $b;
    }
    while (!empty($collapsed) && $collapsed[0]['type'] === 'spacer') {
        array_shift($collapsed);
    }
    while (!empty($collapsed) && end($collapsed)['type'] === 'spacer') {
        array_pop($collapsed);
    }
    return $collapsed;
}

function renderStationDetailsXml(string $details, string $basePPr): string
{
    $blocks = parseStationDetails($details);
    if (empty($blocks)) {
        return '';
    }
    $bulletPPr = '<w:pPr><w:spacing w:after="0"/><w:ind w:left="360" w:hanging="360"/></w:pPr>';
    $out = '';
    foreach ($blocks as $b) {
        switch ($b['type']) {
            case 'spacer':
                $out .= '<w:p>' . $basePPr . '</w:p>';
                break;
            case 'date':
                $out .= '<w:p>' . $basePPr
                    . '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'
                    . escapeForWordXml($b['text']) . '</w:t></w:r></w:p>';
                break;
            case 'bullet':
                $out .= '<w:p>' . $bulletPPr
                    . '<w:r><w:t xml:space="preserve">• '
                    . escapeForWordXml($b['text']) . '</w:t></w:r></w:p>';
                break;
            default:
                $out .= '<w:p>' . $basePPr
                    . '<w:r><w:t xml:space="preserve">'
                    . escapeForWordXml($b['text']) . '</w:t></w:r></w:p>';
        }
    }
    return $out;
}

function expandStationDetails(string $xml, array $stations): string
{
    foreach ($stations as $i => $station) {
        $num      = $i + 1;
        $details  = (string) ($station['details'] ?? '');
        foreach (["{{stations.details.N#{$num}}}", "{{stations.details#{$num}}}"] as $ph) {
            if (!str_contains($xml, $ph)) {
                continue;
            }
            if (trim($details) === '') {
                $xml = str_replace($ph, '', $xml);
                continue;
            }
            $pattern = '#<w:p\b[^>]*>(?:(?!</w:p>).)*?' . preg_quote($ph, '#') . '(?:(?!</w:p>).)*?</w:p>#s';
            $xml = preg_replace_callback($pattern, function (array $m) use ($details): string {
                $basePPr = '';
                if (preg_match('#<w:pPr>.*?</w:pPr>#s', $m[0], $pm)) {
                    $basePPr = $pm[0];
                }
                return renderStationDetailsXml($details, $basePPr);
            }, $xml);
        }
    }
    return $xml;
}

function fillTemplate(string $srcDocx, string $destDocx, array $candidate): void
{
    if (!copy($srcDocx, $destDocx)) {
        throw new RuntimeException("copy failed: $srcDocx -> $destDocx");
    }

    $zip = new ZipArchive();
    if ($zip->open($destDocx) !== true) {
        throw new RuntimeException("cannot open $destDocx");
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        throw new RuntimeException('word/document.xml missing');
    }

    $stations = $candidate['stations'] ?? [];
    $xml = cloneStationRows($xml, count($stations));

    $scalars = $candidate['scalars'] ?? [];

    foreach (['moving', 'commute', 'travel'] as $kind) {
        $state = $candidate['checkboxes'][$kind] ?? null;
        $scalars["checkb.$kind.yes"] = ($state === 'yes') ? CHECKBOX_YES : CHECKBOX_NO;
        $scalars["checkb.$kind.no"]  = ($state === 'no')  ? CHECKBOX_YES : CHECKBOX_NO;
    }

    foreach (($candidate['optional'] ?? []) as $key => $value) {
        $scalars['optional.' . $key] = $value;
    }

    foreach ($stations as $i => $station) {
        $num = $i + 1;
        foreach (['employer', 'time', 'positions'] as $field) {
            $scalars["stations.$field.N#$num"] = (string) ($station[$field] ?? '');
        }
    }

    $xml = applyScalarReplacements($xml, $scalars);
    $xml = expandListPlaceholders($xml, $candidate['lists'] ?? []);
    $xml = expandStationDetails($xml, $stations);

    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

printf("[1/4] Building demo-template1.docx (Executive)...\n");
buildTemplate1($template1);
printf("      %s\n", $template1);

printf("[2/4] Building demo-template2.docx (Retail Store Manager)...\n");
buildTemplate2($template2);
printf("      %s\n", $template2);

printf("[3/4] Writing collections.json ...\n");
$datasetsOut = [];
foreach ($candidates as $cand) {
    $collKey = $cand['collection'];
    $datasetsOut[] = [
        'collection'     => $collections[$collKey]['slug'],
        'collection_id'  => $collections[$collKey]['id'],
        'name'           => $cand['scalars']['fullname'],
        'slug'           => $cand['slug'],
        'scalars'        => $cand['scalars'],
        'checkboxes'     => $cand['checkboxes'],
        'optional'       => $cand['optional'],
        'lists'          => $cand['lists'],
        'stations'       => $cand['stations'],
    ];
}
$manifest = [
    'generated_at' => date('c'),
    'source'       => 'tests/demo/seed-demo.php',
    'note'         => 'Fictional fashion-industry candidate data in German. Synthetic, no real persons.',
    'collections'  => array_values($collections),
    'datasets'     => $datasetsOut,
];
file_put_contents($jsonFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
printf("      %s\n", $jsonFile);

printf("[4/4] Filling %d candidate documents ...\n", count($candidates));
foreach ($candidates as $cand) {
    $collKey = $cand['collection'];
    $coll    = $collections[$collKey];
    $src     = ($coll['template'] === 'demo-template1.docx') ? $template1 : $template2;
    $dst     = $filledDir . '/' . $coll['slug'] . '__' . $cand['slug'] . '.docx';
    fillTemplate($src, $dst, $cand);
    printf("      %s  (%s)\n", basename($dst), $coll['slug']);
}

printf("\nDONE.\n");
printf("  Templates : %s, %s\n", basename($template1), basename($template2));
printf("  Manifest  : %s\n", basename($jsonFile));
printf("  Filled    : %s/\n", basename($filledDir));
exit(0);
