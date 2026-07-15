<?php

declare(strict_types=1);

/**
 * Phase G regression test — intra-list bullet de-duplication (v4.3.1).
 *
 * The v4.3 extraction change blends the CV with certificates/Arbeitszeugnisse
 * into the SAME `details` bullet list, which could emit the same activity
 * twice ("Die Unterpunkte werden alle doppelt aufgeführt"). These primitives
 * collapse exact duplicates (case/spacing/punctuation-insensitive) while
 * preserving order and structural (date/title/spacer) lines. Inlined,
 * dependency-free copies of the controller helpers.
 *
 * Usage: php tests/phase-g-dedupe.php
 */

function bulletDedupKey(string $text): string
{
    $lower = mb_strtolower(trim($text));
    $key = preg_replace('/[^\p{L}\p{N}]+/u', '', $lower);

    return is_string($key) ? $key : $lower;
}

/**
 * @param list<string> $items
 * @return list<string>
 */
function dedupeListStrings(array $items): array
{
    $seen = [];
    $kept = [];
    foreach ($items as $item) {
        $s = (string) $item;
        $key = bulletDedupKey($s);
        if ($key !== '' && isset($seen[$key])) {
            continue;
        }
        if ($key !== '') {
            $seen[$key] = true;
        }
        $kept[] = $s;
    }

    return array_values($kept);
}

/**
 * @param list<array{type: string, text?: string}> $blocks
 * @return list<array{type: string, text?: string}>
 */
function dedupeBulletBlocks(array $blocks): array
{
    $seen = [];
    $kept = [];
    foreach ($blocks as $b) {
        $type = $b['type'] ?? '';
        if ($type === 'date') {
            $seen = [];
            $kept[] = $b;
            continue;
        }
        if ($type === 'bullet') {
            $key = bulletDedupKey((string) ($b['text'] ?? ''));
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            if ($key !== '') {
                $seen[$key] = true;
            }
        }
        $kept[] = $b;
    }

    return array_values($kept);
}

$fails = [];
$check = static function (string $n, bool $ok, string $d = '') use (&$fails) {
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $n, $d !== '' ? " — {$d}" : '');
    if (!$ok) {
        $fails[] = $n;
    }
};

// --- dedupeListStrings ---------------------------------------------------
$check(
    'exact duplicate collapsed',
    dedupeListStrings(['Aufbau der Cloud', 'Aufbau der Cloud']) === ['Aufbau der Cloud'],
);
$check(
    'case/punctuation/space-insensitive collapse',
    dedupeListStrings(['Aufbau der Cloud.', 'aufbau  der cloud']) === ['Aufbau der Cloud.'],
);
$check(
    'distinct bullets are kept',
    dedupeListStrings(['Aufbau der Cloud', 'Leitung des Teams']) === ['Aufbau der Cloud', 'Leitung des Teams'],
);
$check(
    'first occurrence + order preserved',
    dedupeListStrings(['A', 'B', 'A', 'C', 'B']) === ['A', 'B', 'C'],
);

// --- dedupeBulletBlocks --------------------------------------------------
$in = [
    ['type' => 'date', 'text' => '01/2020 – 12/2021'],
    ['type' => 'text', 'text' => 'Interim-CTO'],
    ['type' => 'bullet', 'text' => 'Aufbau der Cloud'],
    ['type' => 'bullet', 'text' => 'Aufbau der Cloud'],
    ['type' => 'bullet', 'text' => 'Leitung des Teams'],
];
$out = dedupeBulletBlocks($in);
$bulletTexts = array_values(array_map(
    static fn ($b) => $b['text'] ?? '',
    array_filter($out, static fn ($b) => ($b['type'] ?? '') === 'bullet'),
));
$check('duplicate bullet block dropped', $bulletTexts === ['Aufbau der Cloud', 'Leitung des Teams']);
$check('date + title blocks preserved', count($out) === 4);

// Same activity under a DIFFERENT period must survive (seen-set resets on date).
$in2 = [
    ['type' => 'date', 'text' => '01/2020 – 12/2021'],
    ['type' => 'bullet', 'text' => 'Projektleitung'],
    ['type' => 'date', 'text' => '01/2022 – heute'],
    ['type' => 'bullet', 'text' => 'Projektleitung'],
];
$check('same bullet under new date kept', count(dedupeBulletBlocks($in2)) === 4);

echo "\n";
if ($fails !== []) {
    echo 'FAIL — ' . count($fails) . " assertion(s) failed\n";
    exit(1);
}
echo "PASS — phase G intra-list de-duplication holds.\n";
exit(0);
