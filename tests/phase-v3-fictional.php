<?php

declare(strict_types=1);

/**
 * Phase V3 fictional-candidates test — render the `v3` target template with
 * three **synthetic** candidates modelled after the three real profiles in
 * the private `hhff` repo. All names, addresses, emails, phone numbers, and
 * employer names are fabricated so this script can travel with the public
 * `synaplan-synaform` repo without leaking customer data.
 *
 * Findings surfaced by this test:
 *
 *   1. PhpWord TemplateProcessor static-state bug (CRITICAL):
 *        The library keeps `$macroOpeningChars` / `$macroClosingChars` as
 *        STATIC properties. Our `candidatesGenerate()` calls
 *        `setMacroOpeningChars('{{')` AFTER constructing a TemplateProcessor,
 *        so the first candidate generated in a fresh PHP process runs the
 *        constructor's internal `fixBrokenMacros` with the library default
 *        `'${'` — which is safe. Every *subsequent* generation in the same
 *        worker runs with `'{{'` in the static state, and the fixBrokenMacros
 *        regex `/\{(?:\{|[^{$]*\>\{)[^}$]*\}/U` greedy-matches a drawing's
 *        URI GUID `{28A0092B-…}` against the nearest later `>{` from a
 *        `<w:t>{{placeholder` — then strip_tags() eats the drawing XML.
 *        Reproduced here by running all three candidates in one PHP process.
 *        In production this hits any FrankenPHP / PHP-FPM worker that
 *        serves >1 generate request. The workaround this test uses (reset
 *        the static before `new TemplateProcessor`) is also the right fix
 *        for the controller.
 *
 *   2. V3 hhff DE template: `{{travel}}` placeholder missing.
 *        V2 only had `{{travelorcommute}}`; rebuild_v3_templates.php renamed
 *        that to `{{commute}}`, which dropped the `travel` side. Add a
 *        second placeholder row by hand in Word.
 *
 *   3. V3 hhff DE template: checkboxes rendered as "1" / "" instead of a
 *        glyph pair.
 *        Template carries plain `{{moving}}{{commute}}` adjacent to the
 *        labels. processScalars casts the PHP bool → "1" / "" (empty).
 *        Real customer profiles use the paired glyph approach
 *        `☐ Ja ☒ Nein`. Options to fix:
 *          a) rewrite the V3 template to use `{{checkb.moving.yes}} Ja
 *             {{checkb.moving.no}} Nein` pairs (matches ANALYSIS-v3.md
 *             recommendation and N&H style), or
 *          b) add a bool → "Ja"/"Nein" shortcut in processScalars for
 *             checkbox-typed fields so plain `{{moving}}` renders sanely.
 *
 * The three fictional characters cover the same variety we see in the real
 * profiles so the template stress-test is meaningful:
 *
 *   1. Senior Brand Manager (DE, verheiratet, DACH retail, lots of stations,
 *      many bullets per station) — mirrors the "A. Findeisen Deichmann" shape.
 *   2. Store Manager (DE, ledig, concrete Anson's/BestSecret-like career with
 *      recent promotions, multi-store responsibilities) — mirrors "A. Moussaoui".
 *   3. Junior Sales Executive (English profile, DE-residence, short career,
 *      education-heavy) — mirrors "C. Fabri Fitflop".
 *
 * How the test runs:
 *   1. Copy `v3_hhff_de.docx` (placed at /tmp by docker-cp) three times.
 *   2. For each copy, drive the full SynaformController pipeline (the same
 *      one candidatesGenerate() calls at request time).
 *   3. Write the filled DOCX to /tmp/v3-fictional/<slug>.docx.
 *   4. Assert: no raw {{placeholder}} left, file size reasonable, lists
 *      expanded to more than one paragraph.
 *
 * Usage (from host):
 *   docker cp "/wwwroot/hhff/word-files/v3/Profil hhff DE v3.docx" \
 *       synaplan-backend:/tmp/v3_hhff_de.docx
 *   docker cp tests/phase-v3-fictional.php \
 *       synaplan-backend:/tmp/phase-v3-fictional.php
 *   docker compose exec backend php /tmp/phase-v3-fictional.php
 *   docker cp synaplan-backend:/tmp/v3-fictional ./out-v3-fictional
 */

require '/var/www/backend/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

$templatePath = '/tmp/v3_hhff_de.docx';
$outDir = '/tmp/v3-fictional';

if (!is_file($templatePath)) {
    fwrite(STDERR, "FAIL: template not found at $templatePath\n");
    fwrite(STDERR, "Hint: docker cp '/wwwroot/hhff/word-files/v3/Profil hhff DE v3.docx' synaplan-backend:/tmp/v3_hhff_de.docx\n");
    exit(1);
}

if (!is_dir($outDir)) {
    mkdir($outDir, 0o777, true);
}

// ---------------------------------------------------------------------------
// Fictional datasets. Each mirrors the *shape* of a real profile without
// copying any real identifiers.
// ---------------------------------------------------------------------------

$candidates = [
    // --- 1. Senior Brand Manager (modelled on the "Findeisen" shape).
    'maren_birkholz' => [
        // Identity
        'fullname'    => 'Maren Birkholz',
        'birthdate'   => '12.09.1986',
        'nationality' => 'Deutsch',
        'maritalstatus' => 'verheiratet',
        'email'       => 'maren.birkholz@example.de',
        'phone'       => '+49 (0)170 555 18 24',

        // Address
        'street' => 'Am Kronenpark 7',
        'zip'    => '40235',
        'city'   => 'Düsseldorf',

        // Target
        'target_position'        => 'Senior Brand Manager DACH, Nordstern Fashion',
        'current_position'       => 'Head of Retail & Marketing DACH, Fair Silk Intimates, Köln',
        'current_annual_salary'  => '78.000,00 EUR + Bonus',
        'notice_period'          => '3 Monate zum Quartalsende, verhandelbar',
        'working_hours'          => '40 Stunden / Woche',

        // Checkboxes
        'moving' => false,
        'commute' => true,
        'travel'  => true,

        // Lists
        'relevant_positions' => [
            'Head of Retail & Marketing DACH, Fair Silk Intimates, Köln',
            'Brand Manager, Cordova Sports GmbH, Nürnberg',
            'Produktmanagerin, Cordova Sports GmbH, Nürnberg',
        ],
        'relevant_positions_for_target' => [
            'Langjährige Erfahrung im Marketing und Brand Management im Fashion- und Sportswear-Bereich',
            'Konzeption und Implementierung von 360° Trade-Marketing-Kampagnen für Retail und Onlinehandel',
            'Verantwortung für strategische Markenführung und Kampagnenmanagement (B2B / B2C)',
            'Betreuung und Weiterentwicklung von Key Accounts inklusive Preis- und Konditionsverhandlungen',
            'Beobachtung, Analyse und Ableitung von Handlungsempfehlungen aus Abverkaufs- und Kundenverhaltens-KPIs',
            'Planung des Marketing-Jahreskalenders inklusive Budgetverantwortung',
            'Regelmäßige Messereisen zur Kollektionsbesprechung bei Lieferanten und Partnern',
        ],
        'benefits' => [
            'Firmenwagen zur privaten Nutzung',
            'Jahresbonus auf KPI-Basis',
            'Betriebliche Altersvorsorge',
            '30 Urlaubstage',
        ],
        'languages' => [
            'Deutsch: Muttersprache',
            'Englisch: fließend (C1)',
            'Französisch: konversationsfähig (B1)',
            'Spanisch: Grundkenntnisse (A2)',
        ],
        'other_skills' => [
            'MS Office (Word, Excel, PowerPoint)',
            'Adobe Photoshop',
            'Canva',
            'SAP Retail',
        ],
        'education' => [
            'Abitur',
            'Diplom-Modelistin und -Stilistin (ESMOD)',
            'Textilbetriebswirtin (BTE)',
            'Ausbildereignungsschein',
        ],

        // Stations
        'stations' => [
            [
                'time'     => '04/2023 – heute',
                'employer' => 'Fair Silk Intimates, Köln',
                'position' => 'Head of Retail & Marketing DACH',
                'details'  => [
                    'Fachliche Leitung Retail und Marketing DACH, Verantwortung für bis zu 28 Mitarbeiter',
                    'Betreuung und strategische Weiterentwicklung von Schlüsselkunden (Galeria, Zalando, Breuninger)',
                    'KPI-Analysen, Budgetplanung, -steuerung und -überwachung',
                    'Konzeption 360°-Trade-Marketing-Kampagnen für WHS, eigenen Retail und Online',
                    'Zusammenarbeit mit dem internationalen Marketingteam zur Umsetzung der Markenstrategie',
                    'Planung, Organisation und Durchführung aller relevanten Fachmessen',
                ],
            ],
            [
                'time'     => '11/2018 – 04/2023',
                'employer' => 'Selbstständig — Slow-Fashion Label "Kleine Blume"',
                'position' => 'Gründerin & Inhaberin',
                'details'  => [
                    'Aufbau und Führung eines Slow-Fashion-Labels für Kinder- und Mütter-Essentials',
                    'Sortimentsentwicklung, Einkauf und Auslieferungssteuerung',
                    'Preis- und Lieferterminverhandlungen mit europäischen Produzenten',
                    'Sourcing neuer Materialien, Qualitäten und Lieferanten',
                    'Aufbau eines Wiederverkäufer-Netzwerks (7 Stores)',
                    'Konzeption von Social-Media- und Fotoshooting-Kampagnen',
                ],
            ],
            [
                'time'     => '11/2015 – 09/2018',
                'employer' => 'Cordova Sports GmbH, Nürnberg',
                'position' => 'Brand Manager / Produktmanagerin',
                'details'  => [
                    'Sortimentsentwicklung inkl. Planung, Einsteuerung und Auslieferung',
                    'Fachliche Führung von Design und Development',
                    'Strategische Markenausrichtung und Kampagnenmanagement (B2B / B2C)',
                    'Relaunch Taschen- und Accessoires-Linie',
                    'Preis- und Lieferterminverhandlungen mit Lieferanten EU / Asien',
                    'Aufbau Social-Media-Kanal und Konzeption neuer Online-Shop',
                ],
            ],
            [
                'time'     => '06/2013 – 06/2015',
                'employer' => 'Nordpol Apparel Group, Hamburg',
                'position' => 'Senior Specialist Buying — Denim Female',
                'details'  => [
                    'Warengruppenverantwortung: Strick, Denim, Accessoires, NOS',
                    'Kapazitäts- und strategische Planung',
                    'Regelmäßige Fernostreisen zur Auftragsplatzierung und Kollektionsbesprechung',
                    'Lieferantenanalysen und -akquisition',
                    'Qualitätssicherung (Basisqualitäten, Passform, Farbe)',
                ],
            ],
            [
                'time'     => '11/2011 – 01/2013',
                'employer' => 'Blauhimmel Fashion Services International, Braunschweig',
                'position' => 'Junior Buyer — DOB / Outerwear',
                'details'  => [
                    'Abverkaufsanalysen und Preisreduzierungen',
                    'Trendrecherchen, Modellentwürfe, Kollektionsvorbereitungen',
                    'Budgetplanung und Lieferantenakquisition',
                ],
            ],
            [
                'time'     => '09/2008 – 07/2010',
                'employer' => 'LDT, Nagold',
                'position' => 'Studium Textilbetriebswirtschaft',
                'details'  => [
                    'Spezialisierung DOB, Wholesale',
                    'Abschluss: Textilbetriebswirtin (BTE)',
                ],
            ],
            [
                'time'     => '09/2004 – 07/2007',
                'employer' => 'ESMOD Deutschland / International',
                'position' => 'Modedesign-Studium',
                'details'  => [
                    'Abschluss: Diplom-Modelistin und -Stilistin',
                ],
            ],
        ],

        'generated_month' => 'April',
        'generated_year'  => '2026',
    ],

    // --- 2. Store Manager (modelled on the "Moussaoui" shape).
    'juna_tabari' => [
        'fullname'    => 'Juna Tabari',
        'birthdate'   => '04.06.1994',
        'nationality' => 'Deutsch',
        'maritalstatus' => 'ledig',
        'email'       => 'juna.tabari@example.com',
        'phone'       => '+49 (0)179 221 47 88',

        'street' => 'Eschenhöhe 42',
        'zip'    => '40595',
        'city'   => 'Düsseldorf',

        'target_position'        => 'Store Manager Opal Fashion Frankfurt am Main',
        'current_position'       => 'General Sales Manager / Geschäftsstellenleitung, Kessler Menswear Flagship Store Mülheim an der Ruhr',
        'current_annual_salary'  => '76.500,00 EUR + Bonus 6.000,00 EUR p.a.',
        'notice_period'          => '6 Monate zum Monatsende (verhandelbar)',
        'working_hours'          => '40 Stunden / Woche',

        'moving'  => true,
        'commute' => true,
        'travel'  => false,

        'relevant_positions' => [
            'General Sales Manager / Geschäftsstellenleitung, Kessler Menswear, Store Sulzbach (Taunus), Main-Taunus-Zentrum',
        ],
        'relevant_positions_for_target' => [
            'Verantwortung für bis zu 60 Mitarbeitende, 7 Abteilungsleitende und 15 Mio. EUR Umsatz',
            'Berichtslinie an die Geschäftsführung',
            'Umsatz- und Personalplanung, KPI-Analysen',
            'Implementierung neuer Verkaufsorganisationen und Verkaufsstrategien',
            'Schulung und Coaching von Führungskräften',
            'Strategische Neuausrichtung und Filialstabilisierung mit Fokus auf Umsatzsanierung und Ertragssteigerung',
            'Optimierung interner Abläufe zur Effizienzsteigerung sowie Verbesserung des Personalmanagements',
            'Maßnahmen zur Erhöhung der Filialfrequenz und Kundenbindung',
        ],
        'benefits' => [
            'Bonus 6.000,00 EUR p.a.',
            'Weiterbildung Train-the-Trainer',
            'Betriebliche Altersvorsorge',
        ],
        'languages' => [
            'Deutsch: Muttersprache',
            'Englisch: fließend (C1)',
            'Französisch: Grundkenntnisse (A2)',
            'Spanisch: Grundkenntnisse (A2)',
        ],
        'other_skills' => [
            'MS Office (Word, Excel, PowerPoint)',
        ],
        'education' => [
            'Fachabitur',
            'Kauffrau im Einzelhandel',
            'Train-the-Trainer / Seminarleiterausbildung',
        ],

        'stations' => [
            [
                'time'     => '08/2014 – heute',
                'employer' => 'Kessler Menswear',
                'position' => 'Verschiedene Stationen bis zur Geschäftsstellenleitung',
                'details'  => [],
            ],
            [
                'time'     => '10/2022 – heute',
                'employer' => 'Kessler Menswear, Flagship Store Mülheim an der Ruhr',
                'position' => 'General Sales Manager / Geschäftsstellenleitung',
                'details'  => [
                    '10/2022 – heute: Flagship Store Mülheim an der Ruhr, 2.000 m², bis zu 45 Mitarbeitende, 4 Mio. EUR Umsatz p.a.',
                    '07/2024 – 12/2025: Flagship Store Essen, 2.500 m², bis zu 40 Mitarbeitende, 4 Mio. EUR Umsatz p.a.',
                    '02/2022 – 10/2024: Store Sulzbach (Taunus), Main-Taunus-Zentrum, 1.500 m², bis zu 30 Mitarbeitende, 13 Mio. EUR Umsatz p.a.',
                    'Selbstverantwortliche Gesamtführung der Filiale mit Fokus auf wirtschaftlichen Erfolg',
                    'KPI-Überwachung, Umsatz- und Personalplanung',
                    'Implementierung neuer Verkaufsorganisationen und -strategien',
                    'Strategische Neuausrichtung mit Fokus auf Umsatzsanierung und Ertragssteigerung',
                ],
            ],
            [
                'time'     => '06/2020 – 02/2022',
                'employer' => 'Kessler Menswear, Store Nürnberg',
                'position' => 'Department Manager / General Sales Manager Assistant',
                'details'  => [
                    'Verantwortlich für 4.000 m² und 13 Mio. EUR Umsatz p.a.',
                    'Erstvertretung des General Sales Managers inkl. Urlaubsvertretung',
                    'Einarbeitung zum General Sales Manager und Prüfungsvorbereitung',
                    'Steuerung von Umsatz, Personal und Verkaufsförderungsmaßnahmen unter Berücksichtigung der KPIs',
                ],
            ],
            [
                'time'     => '02/2019 – 06/2020',
                'employer' => 'Kessler Menswear, Store Dortmund',
                'position' => 'Department Manager / General Sales Manager Assistant',
                'details'  => [
                    'Leitung und Koordinierung einer Abteilung im siebenstelligen Umsatzbereich',
                    'Budgeterreichung, Kennzahlenanalyse und Zielsetzung auf Tages-, Wochen- und Monatsbasis',
                    'Mitarbeitendenauswahl und -entwicklung',
                ],
            ],
            [
                'time'     => '01/2018 – 02/2019',
                'employer' => 'Kessler Menswear, Store Essen',
                'position' => 'Department Manager / Abteilungsleitung',
                'details'  => [
                    'Leitung und Koordinierung einer Abteilung im siebenstelligen Umsatzbereich',
                    'Einarbeitung zum General Sales Manager Assistant und Prüfungsvorbereitung',
                    'Budgeterreichung, Kennzahlenanalyse, Zielsetzung und Mitarbeitendenentwicklung',
                ],
            ],
            [
                'time'     => '08/2014 – 08/2016',
                'employer' => 'Kessler Menswear, Store Düsseldorf',
                'position' => 'Ausbildung zur Kauffrau im Einzelhandel',
                'details'  => [],
            ],
            [
                'time'     => '06/2012 – 08/2014',
                'employer' => 'Heinrich-Heine-Gesamtschule, Düsseldorf',
                'position' => 'Schulabschluss',
                'details'  => [
                    'Abschluss: Fachabitur',
                ],
            ],
        ],

        'generated_month' => 'April',
        'generated_year'  => '2026',
    ],

    // --- 3. Junior Sales Executive (modelled on the "Fabri" shape).
    //     (Content is German; the v3_hhff_de.docx template is German.)
    'lenya_bachhuber' => [
        'fullname'    => 'Lenya Bachhuber',
        'birthdate'   => '22.08.2001',
        'nationality' => 'Deutsch',
        'maritalstatus' => 'ledig',
        'email'       => 'lenya.bachhuber@example.de',
        'phone'       => '+49 (0)160 274 30 91',

        'street' => 'Turmstraße 14',
        'zip'    => '79312',
        'city'   => 'Emmendingen',

        'target_position'        => 'Sales Executive Süddeutschland & Schweiz, Alpenschuh GmbH',
        'current_position'       => 'Junior Sales Representative, Volga Brands, Region Baden-Württemberg',
        'current_annual_salary'  => '41.500,00 EUR (12 × 3.458,33)',
        'notice_period'          => '6 Wochen zum Monatsende',
        'working_hours'          => '37,5 Stunden / Woche',

        'moving'  => true,
        'commute' => true,
        'travel'  => true,

        'relevant_positions' => [
            'Social Media Manager & Assistant Store Manager, Concept Store "Happy Place", Emmendingen',
        ],
        'relevant_positions_for_target' => [
            'Verantwortung für das Kundensegment Baden-Württemberg',
            'Erreichen definierter Umsatzziele',
            'Kundenfeedback sammeln und an das Management reporten',
            'Reisetätigkeit zu Bestands- und Potenzialkunden im zugewiesenen Gebiet',
            'Präsentation des Unternehmens und Aufbau Markenbekanntheit',
            'Telefonische und persönliche Betreuung inklusive Terminvor- und -nachbereitung',
            'Langfristige Kundenbeziehungen pflegen und ausbauen',
            'Sales-Management-Prozess zur Priorisierung von Kunden und Prospects mitgestalten',
            'Effektive Kommunikation und Service-Orientierung im Tagesgeschäft',
        ],
        'benefits' => [
            'Firmenwagen',
            'Mobilfunk-Pauschale',
        ],
        'languages' => [
            'Deutsch: Muttersprache',
            'Englisch: fließend (C1)',
        ],
        'other_skills' => [
            'MS Office (Word, Excel, PowerPoint)',
            'Adobe Creative Cloud',
        ],
        'education' => [
            'Abitur',
            'Bachelor of Arts in Fashion Management',
        ],

        'stations' => [
            [
                'time'     => '08/2025 – heute',
                'employer' => 'Volga Brands, Region Baden-Württemberg',
                'position' => 'Junior Sales Representative',
                'details'  => [
                    'Aufbau der Marke in der Region',
                    'Neukunden-Akquise und Bestandskunden-Betreuung',
                    'Kollektionspräsentationen',
                    'KPI-Auswertung',
                    'Vor- und Nachbereitung von Ordertagen',
                    'Visual Merchandising im Handel',
                ],
            ],
            [
                'time'     => '03/2023 – 07/2025',
                'employer' => 'Concept Store "Happy Place", Emmendingen',
                'position' => 'Social Media Manager & Assistant Store Manager',
                'details'  => [
                    '08/2024 – 07/2025: Social Media Manager & Assistant Store Manager',
                    '03/2024 – 08/2024: Sales Associate',
                    '03/2023 – 03/2024: Werkstudentin / Sales Assistant',
                    'Visual Merchandising — Konzeption und Umsetzung',
                    'Koordination und Schulung von Mitarbeitenden',
                    'Content-Produktion (Foto/Video) und Performance-Auswertung',
                    'Organisation von Events wie Jubiläen',
                ],
            ],
            [
                'time'     => '10/2019 – 03/2024',
                'employer' => 'Hochschule Macromedia Freiburg',
                'position' => 'Studium Fashion Management',
                'details'  => [
                    'Abschluss: Bachelor of Arts',
                ],
            ],
            [
                'time'     => '12/2018 – 02/2019',
                'employer' => 'Kontor West, Filiale Freiburg',
                'position' => 'Werkstudentin / Sales Assistant',
                'details'  => [],
            ],
            [
                'time'     => '04/2019 – 10/2020',
                'employer' => 'Bauer-Discount AG',
                'position' => 'Teilzeit Sales Assistant',
                'details'  => [],
            ],
            [
                'time'     => '09/2016 – 09/2019',
                'employer' => 'Staatliches Gymnasium Emmendingen',
                'position' => 'Allgemeine Hochschulreife',
                'details'  => [
                    'Abschluss: Abitur',
                ],
            ],
        ],

        'generated_month' => 'April',
        'generated_year'  => '2026',
    ],
];

// ---------------------------------------------------------------------------
// Controller plumbing — mirror phase-r-real-profiles.php.
// ---------------------------------------------------------------------------

$controllerRef = new ReflectionClass(\Plugin\Synaform\Controller\SynaformController::class);
$controller = $controllerRef->newInstanceWithoutConstructor();
$logProp = $controllerRef->getProperty('logger');
$logProp->setAccessible(true);
$logProp->setValue($controller, new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void {}
});

function callPriv(object $controller, string $method, array $args): mixed
{
    $ref = new ReflectionMethod(\Plugin\Synaform\Controller\SynaformController::class, $method);
    $ref->setAccessible(true);
    return $ref->invoke($controller, ...$args);
}

// Declared form fields — matches the V3 variable schema in ANALYSIS-v3.md.
// Shape mirrors what candidatesGenerate() passes around internally (see
// controller; field definition keys are key/type/(columns)).
$formFields = [
    ['key' => 'fullname',                      'type' => 'text'],
    ['key' => 'birthdate',                     'type' => 'text'],
    ['key' => 'nationality',                   'type' => 'text'],
    ['key' => 'maritalstatus',                 'type' => 'select'],
    ['key' => 'email',                         'type' => 'text'],
    ['key' => 'phone',                         'type' => 'text'],
    ['key' => 'street',                        'type' => 'text'],
    ['key' => 'zip',                           'type' => 'text'],
    ['key' => 'city',                          'type' => 'text'],
    ['key' => 'target_position',               'type' => 'text'],
    ['key' => 'current_position',              'type' => 'text'],
    ['key' => 'current_annual_salary',         'type' => 'text'],
    ['key' => 'notice_period',                 'type' => 'text'],
    ['key' => 'working_hours',                 'type' => 'text'],
    ['key' => 'moving',                        'type' => 'checkbox'],
    ['key' => 'commute',                       'type' => 'checkbox'],
    ['key' => 'travel',                        'type' => 'checkbox'],
    ['key' => 'relevant_positions',            'type' => 'list'],
    ['key' => 'relevant_positions_for_target', 'type' => 'list'],
    ['key' => 'benefits',                      'type' => 'list'],
    ['key' => 'languages',                     'type' => 'list'],
    ['key' => 'other_skills',                  'type' => 'list'],
    ['key' => 'education',                     'type' => 'list'],
    [
        'key'     => 'stations',
        'type'    => 'table',
        'columns' => [
            ['key' => 'time',     'type' => 'text'],
            ['key' => 'employer', 'type' => 'text'],
            ['key' => 'position', 'type' => 'text'],
            ['key' => 'details',  'type' => 'list'],
        ],
    ],
    ['key' => 'generated_month', 'type' => 'text'],
    ['key' => 'generated_year',  'type' => 'text'],
];

$listKeys = array_map(
    static fn (array $f): string => (string) $f['key'],
    array_filter($formFields, static fn (array $f): bool => ($f['type'] ?? '') === 'list'),
);

// Designer map — mirror the V3 recommendation: bullet (ul), prevent_orphans
// on long lists so we don't dangle the last item on a new page.
$designerMap = [
    'relevant_positions_for_target' => ['list_style' => 'ul', 'prevent_orphans' => true],
    'benefits'                      => ['list_style' => 'ul'],
    'languages'                     => ['list_style' => 'ul'],
    'other_skills'                  => ['list_style' => 'ul'],
    'education'                     => ['list_style' => 'ul'],
    'relevant_positions'            => ['list_style' => 'ul'],
];

$fails = [];
$summary = [];

// ---------------------------------------------------------------------------
// Run each fictional candidate through the full generator pipeline.
// ---------------------------------------------------------------------------

// DEBUG: allow focusing on a single candidate via env var.
$only = getenv('ONLY') ?: null;
if ($only) {
    $candidates = array_intersect_key($candidates, [$only => true]);
}
foreach ($candidates as $slug => $candidate) {
    // 1. Split candidate into scalar variables, checkboxes, list arrays,
    //    and the single stations table.
    $variables = [];
    $arrays = [];

    foreach ($candidate as $k => $v) {
        if ($k === 'stations') {
            $arrays['stations'] = $v;
            continue;
        }
        if (in_array($k, $listKeys, true)) {
            $arrays[$k] = $v;
            $variables[$k] = $v;
            continue;
        }
        // checkbox, scalar, select — all use $variables.
        $variables[$k] = $v;
    }

    // Mirror what `SynaformController::resolveVariables()` does in
    // production: for every `type: checkbox` form field whose value is a
    // bool, auto-generate the paired `checkb.KEY.yes` / `.no` bools that
    // the glyph-pair templates (both V3 hhff DE/EN and N&H DE/EN) depend
    // on. Without this, the paired-glyph placeholders in the rebuilt V3
    // hhff template would fall through to `processScalars` and render as
    // boolean-coerced "1" / "".
    foreach ($formFields as $field) {
        if (($field['type'] ?? '') !== 'checkbox' || empty($field['key'])) {
            continue;
        }
        $k = (string) $field['key'];
        if (!array_key_exists($k, $variables)) {
            continue;
        }
        $yes = $variables[$k] === true
            || (is_string($variables[$k]) && in_array(strtolower($variables[$k]), ['ja', 'yes', '1', 'true'], true));
        $variables['checkb.' . $k . '.yes'] = $yes;
        $variables['checkb.' . $k . '.no']  = !$yes;
    }

    // Apply the same Layer-A normalization (bool → "Ja"/"Nein") that the
    // controller does for plain `{{moving}}` / `{{commute}}` / `{{travel}}`
    // placeholders. Harmless here (the rebuilt V3 template uses the glyph
    // pair, not the plain form) but keeps this test honest about the full
    // candidatesGenerate() semantics.
    foreach ($formFields as $field) {
        if (($field['type'] ?? '') !== 'checkbox' || empty($field['key'])) {
            continue;
        }
        $k = (string) $field['key'];
        if (!array_key_exists($k, $variables) || !is_bool($variables[$k])) {
            continue;
        }
        $variables[$k] = $variables[$k] ? 'Ja' : 'Nein';
    }

    // 2. Copy template → per-candidate working file.
    $work = sys_get_temp_dir() . "/tx_v3_{$slug}.docx";
    copy($templatePath, $work);

    // 3. Same pipeline as SynaformController::candidatesGenerate().
    $cleanedPath = callPriv($controller, 'cleanTemplateMacros', [$work]);

    $richSubfields = callPriv($controller, 'getRichRowSubfields', [$formFields]);

    $expandedTableKeys = callPriv($controller, 'expandTableBlocks', [
        $cleanedPath,
        $formFields,
        $arrays,
        $richSubfields,
    ]);

    $phKeys = array_column(callPriv($controller, 'extractPlaceholders', [$cleanedPath]), 'key');

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
        $richSubfields,
    ]);

    // Repro guard for TemplateProcessor's static-state bug (see doc
    // at top of file): reset the static macroOpeningChars/closingChars
    // to PhpWord's library defaults ('${' / '}') BEFORE constructing the
    // next TemplateProcessor. Otherwise the constructor's fixBrokenMacros
    // pass runs with whatever was last set (i.e. '{{' / '}}' after the
    // first candidate), and its regex greedy-matches drawing URI GUIDs
    // like `<a:ext uri="{28A0092B-...}">...<w:t>{{placeholder}` and
    // strip_tags() then eats the drawing XML. In production the same
    // static lives across a persistent FrankenPHP / PHP-FPM worker, so
    // this guard matches prod behaviour.
    $openRP = new ReflectionProperty(TemplateProcessor::class, 'macroOpeningChars');
    $openRP->setAccessible(true);
    $openRP->setValue(null, '${');
    $closeRP = new ReflectionProperty(TemplateProcessor::class, 'macroClosingChars');
    $closeRP->setAccessible(true);
    $closeRP->setValue(null, '}');

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

    callPriv($controller, 'processRowGroups',  [$tp, $classified['rowGroups'], $arrays, $designerMap, $richSubfields]);
    callPriv($controller, 'processBlockGroups',[$tp, $classified['blockGroups'], $arrays]);
    callPriv($controller, 'processCheckboxes', [$tp, $classified['checkboxes'], $variables, $designerMap]);
    callPriv($controller, 'processLists',      [$tp, $classified['lists'], $variables]);
    callPriv($controller, 'processScalars',    [$tp, $classified['scalars'], $variables]);

    $out = $outDir . "/{$slug}.docx";
    $tp->saveAs($out);

    callPriv($controller, 'expandRichRowColumns',   [$out, $richSubfields, $arrays, $formFields]);
    callPriv($controller, 'applyTableLayoutHelpers',[$out, $arrays, $designerMap]);

    if (is_file($cleanedPath)) {
        unlink($cleanedPath);
    }

    // 4. Post-flight checks: no raw placeholders, reasonable file size.
    $zip = new ZipArchive();
    $zip->open($out);
    $outXml = $zip->getFromName('word/document.xml');
    $zip->close();

    $remaining = [];
    if (preg_match_all('/\{\{([^}]+)\}\}/', strip_tags((string) $outXml), $m)) {
        foreach ($m[1] as $raw) {
            $raw = trim($raw);
            if ($raw === '' || str_starts_with($raw, '#') || str_starts_with($raw, '/')) {
                continue;
            }
            $remaining[] = $raw;
        }
    }

    if (!empty($remaining)) {
        $fails[] = "$slug: unsubstituted placeholders: " . implode(', ', array_slice(array_unique($remaining), 0, 8));
    }

    // Post-flight check 2: XML must parse. The TemplateProcessor
    // static-state bug surfaces as a mismatched-closing-tag parse error
    // at the first drawing's <a:ext> (see file docblock).
    try {
        new SimpleXMLElement((string) $outXml);
    } catch (Throwable $e) {
        $fails[] = "$slug: output document.xml not well-formed — {$e->getMessage()}";
    }

    $summary[$slug] = [
        'out'            => $out,
        'size_kb'        => (int) round(filesize($out) / 1024),
        'lists_expanded' => count($expandedListKeys),
        'row_prepass'    => count($preClonedGroups),
        'table_block'    => count($expandedTableKeys),
        'stations_rows'  => count($candidate['stations']),
    ];
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

foreach ($summary as $slug => $info) {
    printf(
        "  %-24s %4d KB  lists=%-2d rowPrepass=%-2d tblBlock=%-2d stations=%-2d -> %s\n",
        $slug,
        $info['size_kb'],
        $info['lists_expanded'],
        $info['row_prepass'],
        $info['table_block'],
        $info['stations_rows'],
        $info['out'],
    );
}

if (!empty($fails)) {
    echo "\nFAIL\n";
    foreach ($fails as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "\nPASS — v3 fictional candidates rendered, no unsubstituted placeholders.\n";
exit(0);
