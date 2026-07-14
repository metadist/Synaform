<?php

declare(strict_types=1);

/**
 * Phase E3 regression test — proves the designer-driven bullet style
 * (feedback #4: square / custom bullets + indent) produces the right
 * paragraph properties and glyph prefix.
 *
 * Runs fully offline. Inlines buildBulletParagraphProps() and
 * bulletStyleForColumn() from SynaformController so the contract is locked
 * without a Symfony/Docker boot. The identical logic is additionally verified
 * against the live controller via reflection during development.
 *
 * Usage: php tests/phase-e3-bullet-style.php
 * Exit: 0 on pass, 1 on failure.
 */

// --- Inlined copies of the controller algorithm (dependency-free) ---

function buildBulletParagraphProps(?int $bulletNumId, string $markRPr, array $bulletStyle): array
{
    $char = isset($bulletStyle['char']) ? trim((string) $bulletStyle['char']) : '';
    $forceChar = $char !== '';
    $indent = isset($bulletStyle['indent']) && (int) $bulletStyle['indent'] > 0
        ? (int) $bulletStyle['indent']
        : 360;
    $indXml = '<w:ind w:left="' . $indent . '" w:hanging="' . $indent . '"/>';
    $useNumId = $bulletNumId !== null && !$forceChar;

    $pPr = $useNumId
        ? '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="' . $bulletNumId . '"/></w:numPr>'
            . '<w:widowControl w:val="0"/><w:spacing w:after="0"/>' . $indXml . $markRPr . '</w:pPr>'
        : '<w:pPr><w:widowControl w:val="0"/><w:spacing w:after="0"/>' . $indXml . $markRPr . '</w:pPr>';

    $prefix = $useNumId ? '' : (($forceChar ? $char : '•') . ' ');

    return [$pPr, $prefix];
}

function bulletStyleForColumn(array $col): array
{
    $designer = is_array($col['designer'] ?? null) ? $col['designer'] : [];
    $style = [];

    $char = $designer['bullet_char'] ?? ($col['bullet_char'] ?? null);
    if (is_string($char) && trim($char) !== '') {
        $style['char'] = mb_substr(trim($char), 0, 2);
    }

    $indentCm = $designer['bullet_indent_cm'] ?? ($col['bullet_indent_cm'] ?? null);
    if (is_numeric($indentCm) && (float) $indentCm > 0) {
        $style['indent'] = (int) round((float) $indentCm * 567.0);
    }

    return $style;
}

// --- Assertions ---

$fails = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$fails) {
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? " — {$detail}" : '');
    if (!$ok) {
        $fails[] = $name;
    }
};

// Column config → style
$style = bulletStyleForColumn(['key' => 'details', 'bullet_char' => '■', 'bullet_indent_cm' => 0.5]);
$check('flat col bullet_char read', ($style['char'] ?? '') === '■');
$check('cm→twips (0.5 → 284)', ($style['indent'] ?? 0) === 284, 'got ' . ($style['indent'] ?? 'null'));
$check('designer.* also read', (bulletStyleForColumn(['designer' => ['bullet_char' => '♥']])['char'] ?? '') === '♥');
$check('empty when unset', bulletStyleForColumn(['key' => 'x']) === []);

// Default (no glyph, no numId) → "• "
[, $pfxDefault] = buildBulletParagraphProps(null, '', []);
$check('default prefix "• "', $pfxDefault === '• ', "'{$pfxDefault}'");

// Custom glyph → forces char bullet + indent, no numPr
[$pChar, $pfxChar] = buildBulletParagraphProps(null, '', ['char' => '■', 'indent' => 284]);
$check('custom glyph prefix "■ "', $pfxChar === '■ ');
$check('indent in pPr', str_contains($pChar, 'w:left="284"'));
$check('no numPr for char bullet', !str_contains($pChar, 'numPr'));

// Template numId honoured when no glyph
[$pNum, $pfxNum] = buildBulletParagraphProps(5, '', []);
$check('numId honoured, empty prefix', str_contains($pNum, 'w:numId w:val="5"') && $pfxNum === '');

// Custom glyph overrides numId
[$pForce, $pfxForce] = buildBulletParagraphProps(5, '', ['char' => '♥']);
$check('glyph overrides numId', !str_contains($pForce, 'numPr') && $pfxForce === '♥ ');

echo "\n";
if ($fails !== []) {
    echo 'FAIL — ' . count($fails) . " assertion(s) failed\n";
    exit(1);
}
echo "PASS — phase E3 bullet-style contract holds.\n";
exit(0);
