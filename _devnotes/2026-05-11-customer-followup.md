## Synaform — 2026-05-11 (round 2): customer requests

Three additional customer-driven changes on top of the earlier rename + UX
work in `2026-05-11-rename-and-fixes.md`.

## 1. Clickable Word checkboxes (Checkboxen im Word anklickbar)

Generated checkboxes now render as real Word content-control checkboxes
(`<w:sdt>` with `<w14:checkbox>`) so the customer can click them open and
closed in Word 2010+ instead of being stuck with a static glyph.

Backend changes (`SynaformController.php`):

- `processCheckboxes` now (when the new designer flag `clickable_checkbox`
  is true — which is the default) substitutes a unique marker
  `[[SYNCB|state|checkedGlyph|uncheckedGlyph]]` into the docx instead of
  the static `☒` / `☐` glyph. The PhpWord pass keeps working as before;
  the marker just survives until post-processing.
- New post-pass `convertCheckboxMarkersToContentControls(string $docxPath)`
  runs right after `applyTableLayoutHelpers`. It opens the saved DOCX,
  finds every `<w:r>…[[SYNCB|…]]…</w:r>` (handles surrounding text,
  multiple markers per run via a bounded loop, splits one run into up to
  three: text-before + SDT + text-after, preserving the original
  `<w:rPr>` so font/size/colour are inherited), and replaces them with a
  proper `<w:sdt>` content-control checkbox pre-set to the resolved
  state. Adds `xmlns:w14` to `<w:document>` if the template did not
  already declare it.
- Helper `glyphToHex()` converts the configured glyph to its 4-digit hex
  codepoint for `w14:checkedState`/`w14:uncheckedState`. Falls back to
  `2612` / `2610` if the input is empty or a multi-char emoji.
- `normalizeDesignerConfig()` now persists the `clickable_checkbox`
  designer flag for the `checkbox` field type.

Frontend changes (`index.js`):

- Variable designer (checkbox type) gains a new "Make it a clickable
  Word checkbox" toggle, default on. The save handler reads
  `fd_${idx}_clickable_checkbox` and writes it through to the backend.
- New i18n keys `variables.designer_checkbox_clickable` and
  `variables.designer_checkbox_clickable_hint` in en/de/es/tr.

Verified end-to-end: a synthetic DOCX with three SYNCB markers in one
run was processed through the post-pass; output contains 3 `<w:sdt>`
blocks with correct `w14:val="1"`/`"0"`, surrounding text preserved
("Status:", "(yes), and", "(no), final", "item."), no markers left
behind, and a custom `✅` glyph round-trips correctly.

## 2. Empty line before/after lists (Leerzeile in Listen)

List-type variables can now insert a blank visual line above the first
item or below the last item — matches the customer's "empty last/first
line" request without re-architecting list rendering.

Backend (`SynaformController.php`):

- `normalizeDesignerConfig()` now persists `top_blank_line` and
  `bottom_blank_line` booleans on `list`-type fields.
- `expandListParagraphs()` (the proper-paragraph expansion path used
  for templates with bullet/numPr lists) prepends/appends a
  `buildBlankSpacerParagraph()` paragraph based on those flags. The
  spacer inherits the source paragraph's `<w:pPr>` so its line-height
  matches, but strips `<w:numPr>` (no stray bullet) and `<w:keepNext/>`
  (so it doesn't glue to the next paragraph).
- `processLists()` (the `<w:br/>` fallback path used for templates
  without proper bullet markup) gained the same flags via a parallel
  array prepend/append into the joined items.

Frontend (`index.js`):

- Variable designer (list type) gained a new "Spacing around the list"
  block with two checkboxes: "Add an empty line above/below the list".
- Save handler writes `top_blank_line` / `bottom_blank_line`.
- New i18n keys `variables.designer_list_spacing*`,
  `variables.designer_list_top_blank`, `variables.designer_list_bottom_blank`
  in en/de/es/tr.

## 3. GDPR retention timer with overdue warning (DSGVO Löschfrist)

Per-dataset auto-deletion period with the requested dropdown set
(never / 3 / 6 / 9 / 12 / 18 months) and an overdue warning banner on
the datasets list.

Backend (`SynaformController.php`):

- New helpers:
  - `normalizeRetentionMonths(mixed)`: coerces input to one of the
    allowed values (3/6/9/12/18) or `null` for "never". Anything else
    (including 0, 7, 24, "never", false, empty string) collapses to
    null. Verified.
  - `computeExpiresAt(?string $baseIso, ?int $months)`: returns
    `updated_at + months` as ISO-8601, or null when months is null.
- `candidatesCreate`/`candidatesUpdate` accept `delete_after_months`
  in the JSON body, persist it on the entry, and recompute
  `expires_at` from `updated_at + months` on every write.
- `candidatesList` and `candidatesGet` now return both
  `delete_after_months` and a freshly computed `expires_at` on every
  entry, including legacy entries created before this feature
  existed (their `delete_after_months` defaults to null = never).

Frontend (`index.js`):

- New retention picker `<select>` rendered next to the dataset
  detail header's status badge. Triggers an immediate PUT to
  `/candidates/{id}` on `change`, refreshes the dataset list, shows
  a toast "Retention setting updated".
- The detail header subtitle now appends "expires DATE" / "expired
  DATE" (the latter in red) so users see the deadline at a glance.
- `renderDatasetsTab()` computes overdue datasets via
  `datasetIsOverdue()` (works on the client from `delete_after_months`
  + `updated_at`/`created_at`) and prepends a red warning banner:
  - lists up to 5 overdue datasets by name + last-update date,
  - "Cancel" hides the banner for the session
    (`state.retentionBannerDismissed`),
  - "Delete overdue now" prompts a `confirm()` then batch-DELETEs
    every overdue dataset, refreshes the list, and toasts the
    completed count.
- New `clock` icon, helpers `datasetExpiresAt`, `datasetIsOverdue`,
  `renderRetentionPicker`, `renderExpiryHint`, `renderOverdueBanner`.
- New i18n keys `datasets.retention_*` in en/de/es/tr.

## Verification

- Backend lint (`make -C backend lint`) — clean (482 files, 0 fixable).
- Backend PHP-CS-Fixer on plugin (`/plugins/synaform/backend/`) — clean.
- Plugin-related PHPUnit tests — 10 passed, 33 assertions.
- Frontend lint (`make -C frontend lint`) — clean (Prettier + ESLint).
- Frontend type check (`docker compose exec frontend npm run check:types`) — clean.
- Frontend Vitest suite — 45 files, 374 tests, all green.
- Frontend Zod schemas regenerated (329 readable aliases).
- All four i18n JSON files parse; `de.json` matches `en.json` 1:1
  on the new keys; `es.json`/`tr.json` carry the new keys (the
  pre-existing 19-key gap in those two locales is unchanged).
- Smoke tests via `php -r` against the running backend:
  - `normalizeRetentionMonths` returns null for invalid inputs and
    the right ints for 3/6/9/12/18.
  - `computeExpiresAt('2026-01-01', 6)` → `2026-07-01`.
  - `glyphToHex(☒)` → `2612`, `(☐)` → `2610`, `(X)` → `0058`,
    `(😀)` → `1F600`, empty → fallback.
  - End-to-end: a 3-marker DOCX round-trips with 3 SDT blocks,
    correct `w14:val="1"`/`"0"`, and surrounding text preserved.
