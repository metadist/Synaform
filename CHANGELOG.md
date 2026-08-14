# Changelog

All notable changes to the Synaform plugin are documented here.
The project follows the HHFF close-out release plan
(`hhff/planning/07-V4-Release-Plan.md`).

## [4.4.3] - No silent failures: page alerts, size/timeout, auto-continue

### Fixed

- **Large iPhone scans appearing to "do nothing" (2–3 clicks).** Production
  sits behind Cloudflare, which returns HTML 524 if the origin is silent for
  ~100 s. A multi-page phone scan falls through Tika to Vision OCR (up to
  10 pages × 5–15 s) and was killed mid-request with no lasting UI. Now:
  - `parse-documents` returns JSON at an 80 s wall-clock budget (before the
    proxy timeout), persists OCR cache **per file**, and flags `deadline_hit`
    / `ocr_incomplete` instead of dying silently.
  - The UI auto-retries up to 4 rounds on timeout/524/`deadline_hit`, with a
    sticky page-top alert so the user sees "continuing large scan".
  - Uploads show per-file progress (bytes + percent). Size, type, empty-file,
    PHP `UPLOAD_ERR_*`, and dropped-connection errors are explicit JSON — never
    a bare "No file uploaded".
- **Upload timeout used the parse/AI error string.** Large files on a slow
  link aborted at 120 s with a message about a slow model. Timeout now scales
  with file size (up to 5 min) and the copy names the upload.

### Added

- Sticky **page-top alert** for success / failure / timeout / in-progress
  (upload, parse, generate). Toasts remain for short confirmations (saved,
  deleted).
- Client-side 80 MB cap and HEIC/HEIF on the source-document allow-list
  (iPhone camera photos). Template uploads share the same error mapping.

## [4.4.2] - Clear extraction progress / failure feedback

### Fixed

- **Silent or misleading end states after “Read files & auto-fill”.** The UI
  always toasted a green “done” even when sources were unreadable, the AI
  failed, or only a subset of fields filled — so users thought nothing was
  wrong and clicked again. Outcome is now explicit (`complete` / `partial` /
  `failed`), persisted on the candidate as `last_parse`, and shown as a lasting
  status card (not only a fleeting toast).
- **Green check on files that were never scanned.** Untouched sources now show
  “Not scanned yet”; unreadables show the concrete error inline; readable
  sources show strategy (text / Vision / cached).

### Added

- Backend `last_parse` summary on every parse-documents result (including HTTP
  4xx “no text extracted” / all-groups-failed paths).
- Outcome toasts with counts: sources readable + fields filled.
- Retry affordance on the extraction card when outcome is not complete.

## [4.4.1] - Cluster NFS: stop silent multi-click scans

### Fixed

- **"Read & auto-fill" needed 2–3 clicks on production (web1/2/3).** Uploads
  are shared via NFS (`up/`), but Cloudflare round-robins with no sticky
  sessions. Metadata lands in Galera immediately; the bytes can still be
  invisible to a sibling node for ~`actimeo` seconds. Synaform used plain
  `is_file()` and — for additional docs — **silently skipped** missing files,
  so the first click only OCR'd a subset of sources. Subsequent clicks saw
  more files as the NFS cache caught up. Now:
  - Upload finalize clears PHP/NFS stat cache and sets permissions.
  - Parse waits with `FileHelper::fileExistsNfs()` (short retry, ~1.6 s).
  - Missing-after-wait sources are reported as unreadable (no silent drop).
  - Candidate is only marked `extracted` when every declared source was read.

## [4.4.0] - HHFF daily-use feedback round (UX + extraction)

### Fixed

- **"Erfasst"/done card missing after a successful scan.** The live
  `parse-documents` path never set the candidate status, so after auto-fill
  the dataset stayed in `draft` and the "extraction done / go to review" card
  only appeared after a lucky second run. The backend now marks the candidate
  `extracted` on every successful parse (upgrade-only: `reviewed`/`generated`
  are never downgraded) and records the model used in `extraction_info`.

### Added

- **Drag-and-drop reordering** for variables (editor), dataset fields
  (reorder mode), and table rows — the up/down arrows stay as an
  accessible fallback. New shared `enableDragReorder()` helper.
- **Download / inline preview for uploaded source files.** New endpoint
  `GET /candidates/{id}/files/{slot}/{index}/download`; PDFs and images are
  served inline (browser tab preview), other formats download as attachments.
  Filenames in the Source Documents card are now clickable.
- **"Fill missing fields" second run.** When the first pass leaves gaps, the
  extraction card offers an incremental re-run (`?onlyMissing=1`) that only
  targets still-empty fields. The backend additionally filters incremental
  suggestions server-side so filled/corrected values are never overwritten.
- **`validation_model` setting is now wired.** When configured, incremental
  re-runs and the automatic missing-field second pass use it as a
  second-opinion model (previously dead config).
- **Image placeholder detection.** Template scan suggests `type: image` for
  placeholders named like a photo (`{{picture}}`, `{{foto}}`,
  `{{candidate_photo}}`, …; token match, so `ausbildung` stays text), which
  activates profile-photo auto-extraction from the CV and DOCX embedding.
- **Sticky save bar** on the variables editor and dataset data form, plus a
  floating **back-to-top button** on long pages.

## [4.3.1] - Fix duplicated station sub-items

### Fixed

- **Duplicated `details` bullets ("Die Unterpunkte werden alle doppelt
  aufgeführt").** The v4.3.0 change that blends the CV with
  certificates/Arbeitszeugnisse into the same `details` list could emit the
  same activity twice when both source documents describe it. List entries are
  now de-duplicated (case/spacing/punctuation-insensitive, first occurrence
  kept, order preserved) at the **data layer** (`collectArrayData` →
  `dedupeListColumnValues`) so every template render path is covered, plus a
  defence-in-depth pass in the bullet renderers (`renderBulletList`,
  `renderStationBlocksXml`). Applies to existing datasets on the next document
  generation — no re-extraction needed.
- New helpers: `bulletDedupKey()`, `dedupeListStrings()`,
  `dedupeBulletBlocks()`, `dedupeListColumnValues()`. Regression test:
  `tests/phase-g-dedupe.php`.

## [4.3.0] - Umbrella/nested periods + station selection (WS-F, WS-G)

Completes the v4 HHFF feature set: feedback #7, #9, #10.

### Added — WS-F: employer umbrella + nested sub-positions (feedback #9, #10)

- The structured Stations `details` renderer now supports, driven by optional
  row fields:
  - `time_total` → an **umbrella header line** showing the employer's overall
    span (bold) followed by the **employer name in italic** (feedback #9 + #10
    "Arbeitgeber kursiv").
  - `sub_of_previous` → the whole block is **indented one level** for a role
    whose period lies inside another (feedback #10 nested positions). Accepts
    `true` or "ja"/"yes"/"1"/"true" from the editor.
- Additive and back-compatible: rows without these fields render exactly as
  before. New render helpers `mergeRPrAddItalic()` and `addLeftIndent()`.
- Extraction prompts now optionally capture `time_total` / `sub_of_previous`
  (only when the Stations table declares those columns; never invented).

### Added — WS-G: pick relevant positions for page 1 (feedback #7)

- A per-row **"Rel." checkbox** appears in the Stations table (any table with
  designer flag `selectable`, or the `stations` table by convention). The
  selection persists on the row as `selected`.
- Ticked rows are collected into a derived **`{{relevant_positions}}`** list —
  deterministic format "Zeitraum, Position, Arbeitgeber", in table order — so a
  template can show the chosen highlights on page 1. Fast and predictable (no
  extra AI round-trip); an override still wins.

### Tested

- New offline regression `tests/phase-f-nested.php` (indent + italic
  primitives).
- WS-F full render (umbrella bold + italic employer, nested indent, string
  "ja") and WS-G derivation verified against the **live controller** via
  reflection.
- `phase-a/b/c/d/e3` rendering regressions re-run green in the container.

### Notes

- To use WS-F/WS-G in a template, add the relevant columns/placeholders:
  `time_total` (text) and `sub_of_previous` (text/checkbox) columns on the
  Stations table, and a `{{relevant_positions}}` list placeholder on page 1.

## [4.2.0] - Bullet formatting control (WS-E, feedback #4)

### Added

- **Per-column bullet shape + indent (feedback #4).** List columns (incl. the
  Stations `details`) gained a bullet **glyph** picker (default •, plus ■, ▪, ●,
  –, ♥, ▸) and an **indent** field (cm) in the variable designer. A custom glyph
  is applied deterministically **even when the Word template defines no bullet
  list** — so square bullets for the N&H profile now work despite the template
  carrying no numbering definition. Stored as `bullet_char` / `bullet_indent_cm`
  on the column; read by the generator via `bulletStyleForColumn()`.
- Documented in `docs/TEMPLATE_AUTHORING.md` (implicitly via the designer).

### How it renders

- Custom glyph → literal character bullet with a tokenised `w:ind` indent,
  bypassing `numbering.xml` (no template numId needed).
- No custom glyph → unchanged: real Word numbering when the template defines it,
  else the previous "• " fallback.
- Structured Stations keep bold date headers and no-bullet title lines; only the
  task lines carry the chosen glyph.

### Tested

- New offline regression `tests/phase-e3-bullet-style.php` locks the glyph /
  indent / numId-override contract.
- The identical logic was verified against the **live controller** via
  reflection (14 assertions), and `phase-a/b/c/d` rendering regressions still
  pass inside the container.

### Still to come

- WS-F: umbrella periods + nested/indented sub-positions (feedback #9, #10).
- WS-G: station "relevant" checkbox → `{{relevant_positions}}` (feedback #7).
- WS-E follow-up: optional AI mapping of a free-text "make the bullets hearts"
  instruction onto these deterministic settings.

## [4.1.0] - Photo from CV + per-template output language

First slice of the v4.1 feature line (customer feedback items #8 + language).

### Added

- **Profile photo auto-extraction from the CV (feedback #8).**
  `autoExtractProfileImages()` now handles **DOCX** CVs as well as PDFs: it
  scans `word/media/*`, picks the largest portrait-ratio image (min 100x100,
  aspect 0.5-1.15, landscape logos ignored), normalises it to PNG, and fills
  an empty image variable. Edit Details shows a **"from CV"** badge; the
  recruiter can replace/remove it any time. Documented the `{{photo}}`
  placeholder contract in `docs/TEMPLATE_AUTHORING.md`.

### Confirmed

- **Per-template output language.** A template's `language` (e.g. Français)
  already overrides the Collection language via `resolveExtractionLanguage()`,
  and the extraction prompt translates all descriptive free text into that
  language while keeping proper nouns/dates verbatim — so French output from
  German source files works. Set it under a template's language selector.

### Still to come in the v4.1 line

- WS-E: Word bullet glyph/indent/alignment control + prompt-controlled
  formatting (feedback #4, square bullets for N&H).
- WS-F: umbrella periods + nested/indented sub-positions (feedback #9, #10).
- WS-G: station "relevant" checkbox feeding `{{relevant_positions}}` on
  page 1 (feedback #7).

## [4.0.1] - No-hang guarantees + investigable errors

Hardening after a test run where "Read files & auto-fill" appeared to hang
(~217s). Root cause: the configured extraction model was GPT-5.5 Pro (a slow
reasoning model) and the v4.0 second pass added a second sequential call. This
release makes an endless wait structurally impossible and every failure
investigable.

### Fixed / Added

- **Every frontend request is now time-bounded.** `api()` and `apiUpload()`
  enforce a default timeout (90s / 120s); AI-hitting calls (auto-fill, extract,
  generate, enhance-fields, import-parse, AI template suggest, PDF preview)
  carry explicit larger limits (180-240s). The two remaining raw `fetch` calls
  got `AbortController` timeouts too. A stuck backend can no longer make the UI
  spin forever — it stops with a clear, actionable message.
- **Hard server-side wall-clock budget (180s)** on the grouped-extraction
  fallback. Individual provider calls were already bounded, but many sequential
  group calls could still stack into minutes; the loop now stops starting new
  calls past the budget and returns what it has, flagged `deadline_hit` with a
  clear UI message ("switch to a faster extraction model").
- **Investigable AI telemetry.** Every extraction AI call is wrapped with
  structured logging — `event=synaform.ai.chat` / `synaform.ai.chat_error`
  with phase (single_call / group:<key> / second_pass), candidate, requested +
  actual model, provider, elapsed_ms, and on failure the error class + message.
  Greppable after the fact for support.
- **Second pass is conditional.** It only fires when the first pass left a
  substantial gap (>= 25% of fields, min 3), so the common case is a single
  call again — no latency doubling.

## [4.0.0] - Reliability core (HHFF feedback round 2)

First stage of the v4 line. Focus: make "Read files & auto-fill" reliable in
one click, stop "Edit Details" edits from getting lost on save, and surface
documents that could not be read.

### Fixed

- **One-click auto-fill (feedback #1).** Extraction now runs a single pipeline.
  The frontend no longer fires the legacy `POST /extract` call in parallel with
  `parse-documents` (that caused two AI round-trips and confusing partial
  results). Server-side, the accepted single-call result is followed by an
  automatic, focused **second pass** that retries only the fields left empty —
  in the same request — so the user no longer has to press the button twice.
  Token budgets are unchanged (quality over token savings).
- **Save no longer drops "Edit Details" edits (feedback #2).** Adding, removing,
  or moving a Stations row re-renders the form; the harvest step now snapshots
  the **entire** form (scalar fields, lists, checkboxes and every table) instead
  of only the mutated table, so scalar edits made before a row action survive.
- **Save button feedback (feedback #2).** The Save button now shows a spinner
  and "Saving…" while the request is in flight and a "Saved" state on success,
  so there is no reason to click Save twice. A save is blocked while auto-fill
  runs, and auto-fill is blocked while a save runs (no more last-write-wins
  race).

### Added

- **Per-file readability status (feedback #5).** Each source file is stamped
  with an extraction report (strategy, character count, ok/failed). The Sources
  list shows a per-file status — green (readable), amber (read via Vision OCR),
  red "Not readable" with the reason — and a warning toast names any files that
  could not be read after auto-fill.
- **Document labelling for extraction.** Source text handed to the AI is now
  labelled by kind (CV vs. Certificate/Zeugnis vs. Document) based on the
  filename, so the model can attribute content correctly.

### Changed

- **Extraction prompt (feedback #3 + #6).** Default `extraction_rules` now
  instruct the AI to **blend CV activities with matching Arbeitszeugnis/
  certificate statements** in the Stations `details` bullets, and to capture
  **every** education entry (Studium and Schule included) regardless of where it
  appears in the documents.

### Notes

- The legacy `POST /candidates/{id}/extract` endpoint still exists for API
  compatibility but is no longer part of the auto-fill flow.
- Deferred to v4.1.0 (per the release plan): the Prompt-Manager v2 GUI
  (DE/EN + "improve with AI"), the model-to-task settings matrix, Word bullet/
  formatting control, umbrella/nested periods, station selection for page 1,
  photo auto-extraction hardening, and per-template output language.
