## Synaform — 2026-05-11 work session

This summary documents the four tasks requested in the offline session.

## 1. Rename plugin from `templatex` → `synaform`

The plugin's identifier, namespace, controller class, routes,
config group, `plugin_data` type prefixes, file paths, CI workflow,
Makefile targets, README/INSTALL docs, tests and demo references
have all been renamed from `templatex` / `TemplateX` to
`synaform` / `Synaform`.

Done across all three repos:

- `Synaform` repo, branch `chore/rename-to-synaform`,
  commit `chore: rename plugin from templatex to synaform`
  (renamed `templatex-plugin/` → `synaform-plugin/`,
  `TemplateXController.php` → `SynaformController.php`).
- `synaplan` repo, branch `fix/plugins`, commit
  `chore: rename templatex plugin entry to synaform in .gitignore`.
  Removed old `plugins/templatex/`, freshly installed
  `plugins/synaform/`, ran `app:plugin:install 1 synaform`,
  regenerated frontend Zod schemas.
- `synaplan-platform` repo, branch `main`, commit
  `docs: rename TemplateX references to Synaform in research report`.
  The platform `plugins/` directory was already empty (only `.gitkeep`),
  so it is ready for a fresh `synaform` install.

This is a non-data-preserving rename: BCONFIG group changes from
`P_templatex` to `P_synaform`, `plugin_data` type prefixes change,
and upload directories move from `<userId>/templatex/` to
`<userId>/synaform/`. As requested, the plugin needs to be
installed fresh on production.

Verification: `docker compose exec backend php bin/console debug:router | grep synaform`
returns 43 routes; `curl /api/v1/user/1/plugins/synaform/setup-check`
returns 401 (auth required), confirming the route is registered (not 404).

## 2. Slow document recognition — user-facing progress notification

Investigated the extraction pipeline. The 20+ second wait you observed
when adding 2 JPGs is structural: `App\Service\File\FileProcessor`
detects images and routes them to `extractFromImage`, which calls the
Vision AI provider once per image (~5–15 s each). PDFs go through
Tika first; only on low-quality output does the rasterise + Vision AI
fallback kick in. There is no per-file streaming endpoint, so the
fix is on the frontend: make the wait visible.

Frontend changes (commit
`feat(frontend): single auto-fill button, live progress, template language picker`):

- Live elapsed-time counter that ticks every second.
- File count is computed from the dataset's CV + additional files + URL
  sources and shown in the status text ("Extracting text from 2 file(s)…").
- Detection of image files vs office docs swaps in a content-aware hint
  ("Images are processed with Vision AI — about 5–15 seconds per image"
  vs "Office documents are extracted with Apache Tika first; if that
  fails the AI takes over (slower)").
- Progress bar now blends the discrete step number with a time-based
  curve so it keeps creeping forward during the slow Vision AI roundtrip
  instead of freezing at one step.
- A "Please keep this tab open until the analysis finishes" warning so
  users do not navigate away mid-request.

New i18n keys added to all four language files (en, de, es, tr):
`analyze_status_reading_files`, `analyze_status_running_ai`,
`analyze_status_image_hint`, `analyze_status_doc_hint`,
`analyze_status_elapsed`, `analyze_dont_close`.

## 3. Duplicate "extraction" buttons removed

Same commit. The "Source Documents" card had a `parse-documents` button
("Read files & auto-fill") and the "AI Extraction" card below had an
`extract` button. They invoked different endpoints (one fills form
fields, one fills `ai_extracted` for variable resolution fallback) so
both were technically useful but visually duplicated.

Resolution:

- The "Read files & auto-fill" button is now the single trigger.
- It calls `POST /candidates/{id}/parse-documents` and
  `POST /candidates/{id}/extract` in parallel via `Promise.all` so
  both data sinks are populated and the user only waits for the
  longer of the two roundtrips.
- The "AI Extraction" card is now status-only: it appears when
  extraction has completed (or is running) and shows the model used.
  No second button.
- Dead `extract` click handler is left in place harmlessly; it cannot
  fire because the button no longer renders.

## 4. Multi-language target template support

Form processor (AI extraction) now respects per-template language so
an English Word template gets English values even when the source CV
is in German.

Backend (commit `feat(backend): per-template language for AI extraction`):

- `POST /templates` accepts an optional `language` multipart field.
- New `PATCH /templates/{templateId}` endpoint to edit `name` and
  `language` on existing templates without re-uploading the .docx.
- Helper `resolveExtractionLanguage(int $userId, ?array $form)` picks
  the target language with priority:
  1. consensus across all templates attached to the form,
  2. otherwise the form/collection language,
  3. otherwise the historical default `de`.
- Both prompt builders (`buildExtractionPrompt` and the inline prompt
  in `candidatesParseDocuments`) gain an explicit "Output language"
  directive that translates free-text values to the target language
  while keeping proper nouns, emails, phones, URLs, and dates verbatim.
- Helpers `normalizeLanguage` (canonicalises input, accepts BCP-47)
  and `languageName` (returns English language name for the LLM).
- Supported codes: `de en es fr it tr pt nl pl`.

Frontend (same commit as #2/#3):

- Template upload form gets a language dropdown with "Use Collection
  language" as the default (shows the inherited code in parentheses).
- Each existing template row gets an inline language `<select>` that
  PATCHes the change to the backend on `change`.
- New i18n keys: `templates.language_label`, `templates.language_hint`,
  `templates.language_inherit`, `templates.language_updated`.

## Verification & tests

- Backend lint (`make -C backend lint`) — clean (482 files, 0 fixable).
- Backend PHP-CS-Fixer on plugin (`/plugins/synaform/backend/`) — clean.
- Frontend lint (`make -C frontend lint`) — clean (Prettier + ESLint).
- Frontend type check (`docker compose exec frontend npm run check:types`) — clean.
- Plugin-related PHPUnit tests — 10 passed, 33 assertions, only
  unrelated PHP 8.4 deprecation notices.
- Frontend Zod schemas regenerated via `make -C frontend generate-schemas`;
  328 → 329 readable aliases (the `+1` is the new PATCH route).
- Routes verified via `docker compose exec backend php bin/console debug:router`:
  43 `api_plugin_synaform_*` routes including the new
  `api_plugin_synaform_templates_update` (PATCH).
- Smoke test against running backend: `GET setup-check` and
  `PATCH templates/foo` both return 401 (auth) — proving the routes
  are wired, not 404.
- All four i18n JSON files parse and the new keys are present in all
  four languages. The pre-existing 19-key gap in `es.json`/`tr.json`
  (unrelated to this session) is unchanged.

## Branches not yet pushed

These commits are local only — push when ready:

- `Synaform`: `chore/rename-to-synaform` → 3 commits ahead of `main`.
- `synaplan`: `fix/plugins` → 1 new commit (`.gitignore`).
- `synaplan-platform`: `main` → 1 new commit (research report rename).

The `plugins/synaform/` install in `synaplan` is a working copy of
the `Synaform` repo and is gitignored on purpose — it is the local
running instance, kept in sync via `cp -r`.
