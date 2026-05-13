# v4 customer templates — font preservation pass

Date: 2026-05-13  
Triggered by: customer's v4 hhff template + the long-standing "fonts get lost
when data is rendered, especially in child elements" complaint.

## TL;DR

Two production fixes shipped together, in `SynaformController.php`:

1. **`cleanTemplateMacros` now bakes an explicit `<w:rFonts>` (+ `<w:sz>`,
   `<w:szCs>`) into every run that contributes to a `{{placeholder}}`.**
   Source for the font is, in order:
   - the first run in the same paragraph that already declares one,
   - else the most common `<w:rFonts>` across the whole document
     (excluding theme-only declarations),
   - else nothing — the run keeps its pre-fix behaviour and falls back to
     the document default.
2. **Phase B / "rich row column" rendering (`renderBulletList`,
   `renderStationBlocksXml`, `renderRichColumnXml`,
   `renderStationDetailsXml`) now inherits the host paragraph's run rPr.**
   Bullet items, date headers, position-title paragraphs and free-text
   blocks generated for a `stations.details` (or any other `list`-typed
   table column) all get the host cell's font baked into their `<w:r>`,
   instead of falling back to the document default font.

Both fixes are non-invasive: any run that already had explicit font
properties is left untouched, so re-running on already-clean templates
produces a byte-identical output.

## Why the bug existed

PhpWord's `TemplateProcessor::setValue()` only swaps the text node inside
the placeholder run. The run's `<w:rPr>` is preserved verbatim. That works
when the run already carries `<w:rFonts>`. It silently regresses when:

- the run has **no** `<w:rPr>` at all (NH-style template — body paragraphs
  rely entirely on the doc/style default), **or**
- the run's `<w:rPr>` exists but lacks `<w:rFonts>` (Word frequently writes
  partial rPrs after autocorrect / cut-paste).

In both cases the placeholder text — once replaced — falls back to
`rPrDefault` from `styles.xml`, which on most modern templates resolves to
`asciiTheme="minorHAnsi"` → Calibri (theme1.xml). The user sees Arial /
Helvetica-Light / Bebas Neue around the placeholder and Calibri inside it.

The "child element" half of the bug had a different cause: every paragraph
emitted by `renderBulletList` / `renderStationBlocksXml` had a bare
`<w:r><w:t>…</w:t></w:r>` with no `<w:rPr>`. So even when the original
placeholder run was font-perfect, the post-pass-rendered bullets in
`stations.details` came out in the document default font.

## Reproduction

Bench script lives next to this file:
[`synaform-template-bench.php`](./synaform-template-bench.php). Drives the
real `generate()` pipeline against any DOCX, then introspects the
resulting `word/document.xml` to count runs with/without explicit
`<w:rFonts>` and any leftover `{{placeholder}}` tokens.

### Before the fix (NH-style template fed through pipeline)

```
After cleanTemplateMacros (font-bake pass):
  runs touching a placeholder: 39 (3 with explicit <w:rFonts>, 36 without)

Generated DOCX:
  Total runs: 111
  with explicit <w:rFonts>: 12
  without:                  99
  Fonts seen: Helvetica-Light(4), MS Gothic(6), Bebas Neue(2)
  → Calibri leakage: every body paragraph
```

### After the fix (same template, same data)

```
After cleanTemplateMacros (font-bake pass):
  runs touching a placeholder: 29 (29 with explicit <w:rFonts>, 0 without)
  Fonts on placeholder runs: Helvetica-Light (29)

Generated DOCX:
  Total runs: 39
  with explicit <w:rFonts>: 34
  without:                  5  (rPrDefault is Calibri — only fields not
                                touched by the bake pass, harmless)
  Fonts seen: Helvetica-Light(26), MS Gothic(6), Bebas Neue(2)
  → no Calibri leakage in placeholder content
```

The hhff DE v4 template was already font-clean (the customer authors it in
Word with explicit Arial); the same bench against it shows
`runs_with_font=137 / runs_total=137`, no leftover placeholders, fonts
`Arial(134) + MS Gothic(3)`.

## Source-side helper

[`normalize-template-fonts.php`](./normalize-template-fonts.php) is a
standalone CLI that runs the same normalization directly against a .docx
on disk (no Synaplan boot required). Useful for re-baking the customer's
own master templates after a manual edit in Word so the source itself is
already font-clean. Idempotent; creates a `.bak` sibling on first run.

```
$ php _devnotes/normalize-template-fonts.php /path/to/Profil.docx
OK  Profil.docx  touched_paragraphs=26 gained_rfonts=74 already_explicit=6 doc_dominant_font=Helvetica-Light
```

## Why we never invent a font

The fallback chain stops at "most common font in the document". We
deliberately do **not** fall back to a hard-coded "Arial" or "Calibri" or
peek at theme1.xml. The reasoning:

- For theme-driven templates (NH style) the visible body font is one of
  many declared in concrete `<w:rFonts ascii="...">` runs; that's the right
  signal.
- For style-driven templates (most Office templates) the body font lives
  in styles.xml and runs intentionally have empty rPrs to inherit from
  their `<w:pStyle>`. Baking a font there would silently override a
  designer's choice. Leaving those runs untouched preserves the existing
  pre-fix behaviour, which is correct on style-driven templates.

## Files changed

- `synaform-plugin/backend/Controller/SynaformController.php`
  - `cleanTemplateMacros()` calls new `normalizePlaceholderRunFonts()`.
  - New: `normalizePlaceholderRunFonts`, `detectDominantRunStyle`,
    `detectDocumentDominantRunStyle`, `topTallyKey`, `ensureRunHasFont`,
    `extractFirstRunRPr`, `mergeRPrAddBold`.
  - `expandRichRowColumns` now extracts the host paragraph's first
    text-bearing run rPr and threads it through
    `renderRichColumnXml` → `renderBulletList` /
    `renderStationBlocksXml` / `renderStationDetailsXml` as the new
    `$baseRPr` argument.
  - `renderBulletList`, `renderStationBlocksXml`,
    `renderStationDetailsXml`, `renderRichColumnXml`: gained a final
    `string $baseRPr = ''` parameter (defaulted, so no caller changes
    required outside the controller). Date-header runs merge `<w:b/>`
    on top of the inherited rPr via `mergeRPrAddBold`.

Net diff: 1 file, +205 / -16 lines.

## Scope and out-of-scope

- The fix targets the runs that PhpWord and our own post-passes
  manipulate. SDT content controls (interactive checkboxes generated
  by `convertCheckboxMarkersToContentControls`) already inherit the
  surrounding rPr because the rewrite preserves the original run's
  `<w:rPr>`; nothing to change there.
- Not addressed: the NH multi-row-per-station limitation (one logical
  station spread across 3 `<w:tr>`s — only the anchor row gets cloned).
  This is a known limitation noted in [`hhff/word-files/v3/ANALYSIS-v3.md
  §3a`](../../hhff/word-files/v3/ANALYSIS-v3.md). The bench correctly
  flags it (leftover `stations.position.N` and `stations.details.N`
  placeholders for the NH template). Either flatten the visual design
  into a single-row table or add a multi-row clone path to the
  generator. Out of scope for this font-preservation pass.

## Regression coverage

- All offline regression tests still pass:
  `phase-a-lists.php`, `phase-b-stations.php`, `phase-c-tables.php`,
  `phase-d-layout.php`, `phase-t-tableblock.php`,
  and the new `phase-e-checkbox-sdt.php` (see follow-up below).
- New live bench documented above; can be re-run any time with
  `docker compose exec backend php /tmp/synaform-template-bench.php
  /tmp/v4/Profil_hhff_DE_v4.docx /tmp/v4/Profil_NeedleHaystack_DE_v4.docx`.

## Follow-up: SDT-overrun bug surfaced by the v4 hhff template

After shipping the font-preservation pass, generating a real candidate
through the v4 hhff template produced a DOCX that Word and LibreOffice
both refused to open ("unbalanced `</w:sdt>` end tag"). Inspection of
`word/document.xml` showed 2 `<w:sdt>` opens vs 3 `</w:sdt>` closes — the
checkbox-SDT post-pass had emitted one orphan close + a bare `<w:r>` for
the second SYNCB marker in the same paragraph.

### Root cause

`convertCheckboxMarkersToContentControls()` runs in a loop because a
single Word run can contain multiple `[[SYNCB|…]]` markers (the
canonical hhff `{{checkb.X.yes}} Ja     {{checkb.X.no}} Nein` shape
emits two markers inside one `<w:t>`). The regex was:

```
<w:r\b[^>]*>(<w:rPr\b[^/]*?>.*?</w:rPr>|<w:rPr\b[^/]*?/>)?(<w:t…>) … SYNCB … </w:t></w:r>
```

On iteration 2, the regex engine scanned forward, hit the `<w:r>`
inside the SDT that iteration 1 had just emitted, found no SYNCB inside
its `<w:t>`, and **backtracked the lazy `.*?` in the rPr alternative
until it found the next `</w:rPr>`** — which lives inside the leftover
*next* run, AFTER `</w:sdtContent></w:sdt>`. The match then succeeded,
but the captured rPr fragment now contained the SDT-close. The
replacement re-emitted that rPr twice (around `$before` and `$after`),
producing one extra `</w:sdtContent></w:sdt>` and a bare `<w:r>` for
the second checkbox glyph.

Reproduction is deterministic and embedded in the new
`tests/phase-e-checkbox-sdt.php`.

### Fix

Replace the unconstrained inner with a tempered greedy token that can
never cross a run boundary or reach beyond its own `</w:rPr>`:

```
<w:rPr\b[^/]*?>(?:(?!</w:rPr>|<w:r\b|</w:r>).)*?</w:rPr>
```

This keeps the existing capture semantics (rPr / tOpen / before / after)
intact while making the regex impossible to backtrack past a run
boundary.

### Verification

Re-running the smoke test against the v4 hhff template:

```
Generated DOCX (80.4 KB)
  Total runs in body: 86
  with explicit rFonts: 86
  Fonts seen: Arial(84), MS Gothic(2)
  Leftover {{…}}: none
  <w:sdt> opens=2 closes=2  -> balanced
  <w:sdtContent> opens=2 closes=2  -> balanced
  XML parses OK
```

Compare with the broken pre-fix output the customer hit:
86,694 bytes, 137 runs, 2 opens / 3 closes — Word refused.

Net diff: 1 file, +28 / -3 lines (regex tightening + comment).
New test: `tests/phase-e-checkbox-sdt.php` (1 file, +153 lines).

## Follow-up: header / footer placeholder substitution

Customer-reported, same v4 hhff template: the `{{fullname}}` /
`{{target_position}}` placeholders in the upper-right "Profil von …"
header line never got replaced. The body was perfect; the header still
showed the literal `{{...}}`.

### Root cause

Every Synaform pre/post pass (`cleanTemplateMacros`,
`extractPlaceholders`, `expandTableBlocks`, `expandListParagraphs`,
`cloneParagraphGroupsPrepass`, `expandRichRowColumns`,
`applyTableLayoutHelpers`, `convertCheckboxMarkersToContentControls`)
operated on `word/document.xml` only. PhpWord's own
`TemplateProcessor::setValue()` does walk `word/header*.xml` and
`word/footer*.xml` automatically — but it cannot find a placeholder
whose `{{` and `}}` were split across multiple `<w:r>` runs by Word's
autocorrect / cut-paste, and that's exactly the state of every
freshly-authored .docx. The defragmentation step lives inside
`cleanTemplateMacros`, so when that step skipped the headers, PhpWord's
`getVariables()` reported header placeholders as 200-character XML
fragments (`</w:t></w:r><w:proofErr.../>...header_name...`) instead of
the clean key `header_name`, and `setValue('header_name', '…')` never
matched any node in the header.

### Fix

- Introduced `collectDocumentPartNames(\ZipArchive $zip)` that returns
  `['word/document.xml', 'word/header1.xml', 'word/header2.xml',
  'word/footer1.xml', …]`.
- `cleanTemplateMacros()` now loops over every part — both the
  brace-defragmentation and the new font-preservation pass apply to
  body + headers + footers.
- `extractPlaceholders()` likewise scans every part, so "Detect
  placeholders" picks up header/footer-only variables too (not the
  case before — a header-only `{{slug}}` wasn't visible in the
  Variables tab).
- `convertCheckboxMarkersToContentControls()` was split into a
  per-part `convertCheckboxMarkersInPart()` that runs on body +
  headers + footers, with the `xmlns:w14` injection extended from
  `<w:document>` to also cover `<w:hdr>` and `<w:ftr>` roots so SDT
  checkboxes inside a header still render as clickable controls.
- Tables/lists (`expandListParagraphs`, `expandTableBlocks`,
  `cloneParagraphGroupsPrepass`, `expandRichRowColumns`,
  `applyTableLayoutHelpers`) are intentionally **left body-only**:
  multi-row data structures inside a Word header are vanishingly rare
  and the engine assumptions (single section / cantSplit) don't apply
  cleanly there. SCALAR + checkbox placeholders in headers/footers are
  fully supported; tables/lists in headers/footers are documented as a
  current limitation.

### Verification

Bench output for v4 hhff DE after the header/footer pass:

```
Generated DOCX (82.3 KB)
  body runs with explicit rFonts: 82/82
  header substitution: OK — Alex Beispiel + Head of Marketing both present
  no leftover {{…}} in body or headers
```

Both `tests/phase-h-headers-footers.php` (synthetic split-run header +
clean footer; offline) and `_devnotes/v4-api-smoketest.php` (full HTTP
upload+generate against the real hhff template, with sentinel-check on
`word/header*.xml`) now PASS and would catch any regression that puts
the passes back into body-only mode.

Net diff: 1 file (`SynaformController.php`), +95 / -34 lines.
New test: `tests/phase-h-headers-footers.php` (1 file, +178 lines).
