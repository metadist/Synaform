<?php

declare(strict_types=1);

/**
 * Phase F regression test — WS-F primitives for umbrella periods + nested
 * sub-positions (feedback #9/#10): the left-indent helper and the italic
 * run-property merge. Inlined copies of the controller helpers (dependency-
 * free); the full umbrella/indent render is additionally verified against the
 * live controller via reflection during development.
 *
 * Usage: php tests/phase-f-nested.php
 */

function addLeftIndent(string $pPrXml, int $twips): string
{
    if ($twips <= 0) {
        return $pPrXml;
    }
    if ($pPrXml === '') {
        return '<w:pPr><w:ind w:left="' . $twips . '"/></w:pPr>';
    }
    if (preg_match('#<w:ind\b[^>]*\bw:left="(\d+)"#', $pPrXml, $m) === 1) {
        $newLeft = (int) $m[1] + $twips;
        $out = preg_replace('#(<w:ind\b[^>]*\bw:left=")\d+(")#', '${1}' . $newLeft . '$2', $pPrXml, 1);
        return is_string($out) ? $out : $pPrXml;
    }
    if (preg_match('#<w:ind\b[^>]*/>#', $pPrXml) === 1) {
        $out = preg_replace('#(<w:ind\b[^>]*?)(/>)#', '$1 w:left="' . $twips . '"$2', $pPrXml, 1);
        return is_string($out) ? $out : $pPrXml;
    }
    $out = preg_replace('#(<w:pPr\b[^>]*>)#', '$1<w:ind w:left="' . $twips . '"/>', $pPrXml, 1);
    return is_string($out) ? $out : $pPrXml;
}

function mergeRPrAddItalic(string $baseRPr): string
{
    if ($baseRPr === '') {
        return '<w:rPr><w:i/></w:rPr>';
    }
    if (preg_match('#<w:i\s*/>#', $baseRPr)) {
        return $baseRPr;
    }
    $injected = preg_replace('#(<w:rPr\b[^>]*>)#', '$1<w:i/>', $baseRPr, 1);
    return is_string($injected) ? $injected : $baseRPr;
}

$fails = [];
$check = static function (string $n, bool $ok, string $d = '') use (&$fails) {
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $n, $d !== '' ? " — {$d}" : '');
    if (!$ok) {
        $fails[] = $n;
    }
};

$check('indent added to empty pPr', addLeftIndent('<w:pPr></w:pPr>', 360) === '<w:pPr><w:ind w:left="360"/></w:pPr>');
$check('indent bumps existing left', str_contains(addLeftIndent('<w:pPr><w:ind w:left="200" w:hanging="200"/></w:pPr>', 360), 'w:left="560"'));
$check('indent added when ind has no left', str_contains(addLeftIndent('<w:pPr><w:ind w:right="100"/></w:pPr>', 360), 'w:left="360"'));
$check('zero indent is a no-op', addLeftIndent('<w:pPr/>', 0) === '<w:pPr/>');
$check('italic added to empty rPr', mergeRPrAddItalic('') === '<w:rPr><w:i/></w:rPr>');
$check('italic injected into existing rPr', str_contains(mergeRPrAddItalic('<w:rPr><w:sz w:val="20"/></w:rPr>'), '<w:i/>'));
$check('italic not duplicated', substr_count(mergeRPrAddItalic('<w:rPr><w:i/></w:rPr>'), '<w:i/>') === 1);

echo "\n";
if ($fails !== []) {
    echo 'FAIL — ' . count($fails) . " assertion(s) failed\n";
    exit(1);
}
echo "PASS — phase F umbrella/nested primitives hold.\n";
exit(0);
