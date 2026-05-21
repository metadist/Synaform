# Synaform — 2026-05-21: Setup Wizard + Collection-page restructure

Customer feedback: "users are overwhelmed by the gathering of variables and
how to handle those". This session ships the four-PR restructure that hides
the setup-time concepts (Variables + Target Templates) behind a single
"Set up Collection" button with both a guided wizard and a manual escape
hatch, and reframes the daily work surface (Datasets) as the primary place
to land.

Each PR lives on its own branch off `main` and can be reviewed in order or
merged independently. All four ship with passing CI gates (prettier,
php-cs-fixer, i18n key parity en/de/es/tr) and Playwright UI tests.

| # | Branch | Subject | Frontend Δ | Tests added |
|---|--------|---------|------------|-------------|
| 1 | `refactor/collection-tabs-restructure` | Fold Variables + Target Templates behind a single Set up tab; move Danger Zone to a kebab menu | +168 / -31 in `index.js`, 5 new i18n keys | 4 new UI tests |
| 2 | `feature/setup-split-button-and-drafts` | Replace "New Collection" with a split-button (Guided wizard / From template / From text / Manual); add a Drafts section to the Collections list | +255 / -10 in `index.js`, 17 new i18n keys | 4 new UI tests |
| 3 | `feature/setup-wizard` | Ship the 5-step Setup Wizard modal (Chooser → Basics → Template → Fields → Review) with progressive draft persistence | +810 / -35 in `index.js`, 33 new i18n keys | 4 new UI tests |
| 4 | `feature/dataset-first-defaults` | Ready Collections land on Datasets, add "Continue last" button, number dataset detail tabs | +95 / -16 in `index.js`, 1 new i18n key | 4 new UI tests |

Total: ~1,330 lines of frontend code added, 56 i18n keys, 16 new
Playwright UI tests. **No PHP backend changes** in any PR — every change
is a frontend orchestration on top of existing endpoints.

## PR 1 — `refactor: fold variables + templates behind a single Set up tab`

The Collection cockpit used to expose six tabs: Overview / Variables /
Target Templates / Datasets / Export / Danger Zone. New users were
overwhelmed by setup-time concepts mixed with daily-work surfaces, and
Danger Zone was one click away from any tab.

**Tabs after PR1**: `Overview · Datasets · Set up · Export`.

- **Set up** stacks the existing `renderVariablesTab(c)` and
  `renderTemplatesTab(c)` verbatim inside one focused setup surface
  (no editor rewrites; pure layout change).
- **Danger Zone** moves into a `⋯` kebab menu next to the Collection
  title and renders as a modal when picked. Typed-name confirm UI is
  unchanged.
- **Legacy hashes survive**. `#tx-c/<id>/variables` and
  `#tx-c/<id>/templates` map to `setup` via a new
  `normalizeCollectionTab()`. `#tx-c/<id>/danger` pops the modal
  automatically. Bookmarks don't break.
- **Cross-links** (overview wizard step buttons, summary callouts,
  statCards, empty-state CTAs) all repoint to the new `setup` tab.

**The bug that ate 20 minutes**: `VALID_TABS` in `parseHash()` didn't
include `"setup"`, so the URL bounced `/setup → /overview` 34 ms after
every click via the `hashchange` listener. Fixed by adding `setup` to
the array and keeping the legacy names so old bookmarks still parse.

**Tests** (e2e/synaform-plugin.spec.ts):
- 4 new: new 4-tab structure, legacy hash redirect, Set up stacks both
  sections, kebab menu exposes Edit + Danger Zone modal.
- 2 existing tests updated for the new tab IDs.
- 1 self-seeding `beforeAll` so the suite no longer requires demo data.

## PR 2 — `feat: add "Set up" split-button and Drafts section`

A single front door for every setup-time action plus a place for
abandoned drafts to live.

- **Split button** replaces the "New Collection" button. Primary click
  runs the guided wizard; chevron dropdown exposes four entry points
  (Guided wizard / From a Word template / From a pasted variable list
  / Open manual editor). Every menu item carries a short hint.
- **Drafts section** at the top of the Collections list. Heuristic:
  `isDraftCollection(c) = vars === 0 && templates === 0 && datasets === 0`.
  Each draft card has a "Continue setup" button (jumps into the wizard
  in PR3) and a "Discard" button (typed-name-free `confirm()` +
  DELETE /forms/{id}).
- **Wizard stub**: in PR2 all dropdown modes still open the existing
  manual New Collection modal. The chosen mode is stashed on
  `state.pendingSetupMode` and read on save so wizard/template/text
  paths land on Set up and manual lands on Overview — matching the
  future flow exactly.

**Tests**: split-button structure (primary + chevron + 4 items +
backdrop close), wizard-mode create-and-land-on-Set-up,
Drafts/Continue-setup, Drafts/Discard with API-level verification.

## PR 3 — `feat: ship the 5-step Setup Wizard modal`

The big one. Replaces the PR2 stub with the real wizard modeled on
synaplan's `WidgetCreationWizard.vue`.

```
[1 Chooser] → [2 Basics] → [3 Template]* → [4 Fields] → [5 Review]
              ↘ (noTemplate path skips Template) ↗
```

- **Step 0 Chooser**: 2-card split — "I have a Word template" vs
  "I don't have one yet". Drives `state.wizard.mode`. The dropdown
  shortcuts (Template / Text / Wizard) can skip step 0 entirely.
- **Step 1 Basics**: Name + description + language. On Next, persists
  via `POST /forms` (first time) or `PUT /forms/{id}` (resume). After
  step 1 the draft is real on the backend — cancelling at any later
  step leaves a resumable draft in the Drafts section.
- **Step 2 Template** (template path only): drop a `.docx`, uploaded
  to `POST /templates`, linked to the form, and a fresh
  `GET /templates/{id}/variable-suggestions` is fetched to pre-populate
  step 3. A "Skip" button drops the user into the noTemplate path
  mid-flow.
- **Step 3 Fields**:
  - Template path: pre-ticked checkbox table from
    `variable-suggestions` (reuses the deterministic mapping from the
    existing `renderVariablesTplImportPreview`).
  - Text path: paste-and-parse via `POST /forms/import-parse`.
  - Either way, "Continue" merges the chosen fields with any existing
    ones (resume safety) and `PUT`s them onto the form.
- **Step 4 Review**: Summary card + two CTAs. Primary "Add your first
  dataset" always goes to Datasets; secondary "Open Collection" goes
  to Datasets if the Collection is ready, else Overview (post-PR4).

**Persistence**: every step that produces state writes through, so the
draft is always resumable. Resume opens the wizard on step 1 with the
existing name/description/language pre-filled and auto-infers the
mode from whether any templates are attached.

**TX_VERSION** bumped from `v3.2.3` to `v3.3.0` so clients with cached
translations from the previous release pick up the new wizard.* keys.

**Tests**: chooser-opens-modal, template-mode-skips-chooser, full
noTemplate happy path (with API-level confirmation), cancel-leaves-
resumable-draft. Two PR2 tests updated to match the new wizard-first
behaviour (PR2's "primary opens manual modal" is now "manual mode
opens manual modal", behind the chevron dropdown).

## PR 4 — `feat: land on Datasets first + Continue last + numbered dataset tabs`

The polish layer that makes daily work feel like daily work.

- **Default landing tab**: `defaultLandingTab(c)` returns `datasets`
  when `isCollectionReady(c)` (≥1 var AND ≥1 template), else
  `overview`. Wired into the open-collection card click and the
  wizard's "Open Collection" CTA. Explicit tab clicks are untouched —
  this is *landing* policy only.
- **Continue last dataset**: "Continue last" button next to "+ New
  Dataset" on the Datasets tab. `findContinuableDataset()` returns the
  most-recently-updated dataset whose status is NOT `generated`,
  matching the single most repeated daily action ("pick up the
  half-extracted one I was on this morning").
- **Numbered dataset detail tabs**: `Sources / Edit Details / All
  variables / Generate Docs` become `1. Sources / 2. Edit Details /
  3. All variables / 4. Generate Docs`. Mirrors the wizard's stepped
  progress bar so the dataset flow has the same visual rhythm.

**Tests**: ready Collection lands on Datasets, incomplete Collection
still lands on Overview, Continue last button appears + opens the
dataset, numbered tab prefixes render correctly. One PR3 wizard test
updated to use the primary CTA so it stays independent of the new
readiness heuristic.

## Verification across all 4 PRs

```bash
# Plugin lint
npx prettier --check 'synaform-plugin/frontend/**/*.js'     # clean
docker compose -f /wwwroot/synaplan/docker-compose.yml exec -T \
  backend vendor/bin/php-cs-fixer fix --dry-run --diff \
  --rules=@PSR12 --using-cache=no /plugins/synaform/backend/  # clean

# i18n key parity (en/de/es/tr): 0 missing, 0 extra on every locale
# Total keys after PR4: 473

# Playwright UI suite (CI=1)
npx playwright test tests/e2e/synaform-plugin.spec.ts -g "Synaform UI Tests"
#   22/22 pass — ~2:45 min on this Docker stack
```

API-level Playwright tests (`-g "Synaform API Tests"`) had 3 pre-existing
failures — all are AI-quality assertions (`body.extracted.stations.length`,
expected employer names from specific CVs) that depend on a commercial
AI provider. The local Ollama-only stack returns different extractions.
These failures are unrelated to any of the 4 PRs — none of them touch
PHP backend code or AI prompts.

## Files touched

| File | PR 1 | PR 2 | PR 3 | PR 4 |
|------|-----:|-----:|-----:|-----:|
| `synaform-plugin/frontend/index.js`     | +168 / -31  | +255 / -10  | +810 / -35  | +95 / -16   |
| `synaform-plugin/frontend/i18n/en.json` | +5          | +21         | +35         | +1          |
| `synaform-plugin/frontend/i18n/de.json` | +5          | +21         | +35         | +1          |
| `synaform-plugin/frontend/i18n/es.json` | +5          | +21         | +35         | +1          |
| `synaform-plugin/frontend/i18n/tr.json` | +5          | +21         | +35         | +1          |
| `tests/e2e/synaform-plugin.spec.ts`     | +91 / -10   | +118        | +155 / -13  | +150 / -7   |

No PHP, no migrations, no docker, no CI workflow changes.

## Branches

```
main
  └─ refactor/collection-tabs-restructure   (PR 1)
       └─ feature/setup-split-button-and-drafts   (PR 2)
            └─ feature/setup-wizard               (PR 3)
                 └─ feature/dataset-first-defaults  (PR 4)
```

Each branch is based off the previous one to make the PR chain
reviewable in order. They could also be rebased onto `main`
independently if the reviewer prefers a flatter history.
