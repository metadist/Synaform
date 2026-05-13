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
  `phase-d-layout.php`, `phase-t-tableblock.php`.
- New live bench documented above; can be re-run any time with
  `docker compose exec backend php /tmp/synaform-template-bench.php
  /tmp/v4/Profil_hhff_DE_v4.docx /tmp/v4/Profil_NeedleHaystack_DE_v4.docx`.
