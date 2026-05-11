# Synaform — Tests & Demo

This folder contains three independent ways to exercise the plugin:

| Layer | What it proves | Runs offline? | Needs Synaplan? |
|---|---|:---:|:---:|
| **Regression tests** (`phase-a-lists.php`, `phase-b-stations.php`) | The list-expansion and station-details-rendering algorithms behave exactly as the production controller does. | yes | no |
| **Demo scenario** (`demo/`) | The full product narrative — collections, typed variables, filled DOCX — works end-to-end on a real Synaplan instance. | partial (step 1 offline) | yes, for step 2 |
| **E2E tests** (`e2e/`) | API + UI flows validated with Playwright. | no | yes |

If you want the one-sentence answer: **to demonstrate the plugin on another
computer, follow the `Demo scenario` section below**.

---

## Demo scenario (portable walkthrough)

The demo shows:

- **2 Word templates** (Executive layout, Retail layout) with 36 `{{placeholders}}` each
- **2 Collections** (`Fashion Executive Profiles`, `Fashion Retail Manager Profiles`) with 31 typed fields — scalar, list, select, and a `stations` table
- **6 fictional German fashion candidates** (salaries, companies, career stations, benefits — all synthetic)
- **6 filled DOCX** generated in-app and attached to each candidate

It takes about 5 minutes to seed and 10 minutes to walk through.

### Prerequisites (per machine, one-time)

1. **Synaplan running locally**, reachable at `http://localhost:8000`
   (override with `SYNAPLAN_API_URL` if different).
   Typical local setup:

   ```bash
   cd ../synaplan && docker compose up -d
   docker compose ps   # wait for "healthy"
   ```

2. **Synaform plugin installed for the demo user.** See
   [`../INSTALL.md`](../INSTALL.md) for the full platform install, or for a
   workspace dev setup:

   ```bash
   # From this repo:
   make sync-and-clear   # or: cp -r synaform-plugin/ ../synaplan/plugins/synaform/

   # Register for user id=1 (admin by default):
   cd ../synaplan && docker compose exec backend \
     php bin/console app:plugin:install 1 synaform
   ```

3. **PHP 8.2+** on the host (used by the seed/install scripts; the plugin
   itself runs inside the Synaplan container).

4. **Admin credentials.** Defaults are
   `admin@synaplan.com` / `admin123` — override via env vars below.

### Step 1 — Generate the demo files (offline)

```bash
php tests/demo/seed-demo.php
```

Produces, fully deterministically, into `tests/demo/`:

- `demo-template1.docx`, `demo-template2.docx`
- `collections.json` (manifest of the 2 Collections + 6 Datasets)
- `filled/*.docx` (one rendered DOCX per candidate, useful as an offline
  reference before ever talking to the plugin)

Re-running is safe: it overwrites every artifact.

### Step 2 — Install into the running plugin

```bash
php tests/demo/install-demo.php --wipe --generate
```

| Flag | Meaning |
|---|---|
| `--wipe` | First delete any previously installed `[FashionDemo]` records (idempotent re-installs). Always use it on the demo machine. |
| `--generate` | After creating each candidate, ask the plugin to render its filled DOCX in-app so every candidate already has a downloadable document before you start the walkthrough. |

Environment overrides:

```bash
SYNAPLAN_API_URL=http://localhost:8000 \
SYNAPLAN_USER_ID=1 \
SYNAPLAN_ADMIN_EMAIL=admin@synaplan.com \
SYNAPLAN_ADMIN_PASS=admin123 \
  php tests/demo/install-demo.php --wipe --generate
```

You should see:

```
[tpl ] Uploading demo-template1.docx ...  tpl_... (placeholders: 36)
[tpl ] Uploading demo-template2.docx ...  tpl_... (placeholders: 36)
[form] Creating Collection '[FashionDemo] Fashion Executive Profiles (DE)' ...
[form] Creating Collection '[FashionDemo] Fashion Retail Manager Profiles (DE)' ...
[cand] Creating 'Lena Hartmann' ... Maximilian Graf ... Sophie Wagner ...
[cand] Creating 'Tobias Krüger' ... Anja Becker ... David Köhler ...
[gen ] Generating filled documents in-app ...  6 × doc_...
```

**If any template reports `placeholders: 0`, stop and investigate — the
DOCX was rejected (usually malformed XML).**

### Step 3 — Walk the audience through the UI

Open `http://localhost:8000/plugins/synaform` (or whichever host).
Suggested flow, ~2 minutes per stop:

1. **Collections list** — show the two `[FashionDemo]` Collections. Point
   out that each Collection owns its own variable set + templates +
   datasets: one customer, one workflow.

2. **Open `[FashionDemo] Fashion Executive Profiles`** — show the 31
   typed fields: text, `select` options (Ja/Nein), `list`, and the
   `stations` table with columns `employer / time / positions / details`.
   Emphasise: variables are defined once per Collection, not per document.

3. **Open candidate "Lena Hartmann"** — show the pre-filled scalars
   (`fullname`, `target-position`, salaries in EUR) and the multi-entry
   `stations` table (3 career stations with multi-line `details`).

4. **Switch to the Variables tab** on the candidate — show the resolved
   variables and their source (`form`, `ai`, `override`). Explain how AI
   extraction would override scalars once a CV PDF is uploaded.

5. **Documents tab** on the candidate — download the pre-generated DOCX
   (`--generate` produced it during install). Open it in Word / LibreOffice
   and show: salaries populated, bullet lists per `languageslist`,
   checkboxes ticked for moving/commute/travel, career stations rendered
   with bold date headers and bulleted achievements (Phase B rendering).

6. **Back to Collections list → open the Retail one → candidate "Tobias
   Krüger"** — same variable set, different layout. Download his DOCX to
   show the compact layout with identical data contract. Point of this
   stop: *one customer can maintain multiple output formats against the
   same data.*

7. **Regenerate live** (optional) — on any candidate, change a value
   (e.g. `expectedansalary`), click "Regenerate document", download the
   new version. Shows that the template engine is fully deterministic
   and fast.

### Step 4 — Reset after the demo

```bash
php tests/demo/install-demo.php --wipe    # removes all [FashionDemo] records
```

Nothing remains in the target instance afterwards. Files on disk in
`tests/demo/` are idempotent and can stay.

---

## Regression tests (offline)

Two pure-PHP scripts that exercise the core expansion algorithms the
controller uses, against the synthetic `tests/fixtures/test_template.docx`
fixture (no customer data). They run in well under one second each.

```bash
php tests/phase-a-lists.php      # {{list}} placeholders → one <w:p> per item
php tests/phase-b-stations.php   # {{stations.details.N}} → bold date headers + bullets
```

Exit code is `0` on pass, non-zero on failure. No dependencies beyond PHP
8.2 with the `zip` extension.

---

## E2E tests (Playwright)

Full API + UI flows against a running Synaplan. One-time install:

```bash
npm install
npx playwright install --with-deps
```

Run:

```bash
npm run test           # all e2e
npm run test:api       # API-only (tag: @api)
npm run test:ui        # UI-only (tag: @ui)
```

The e2e suites live in `tests/e2e/`:

- `beta-scenarios.spec.ts` — CRUD, uploads, AI extraction, pagination, UI.
- `synaform-plugin.spec.ts` — focused plugin flows.

They use the synthetic CVs in `tests/fixtures/cv_*.pdf`.

---

## File reference

```
tests/
├── README.md                  ← you are here
├── phase-a-lists.php          ← regression: list expansion
├── phase-b-stations.php       ← regression: station details
│
├── demo/                      ← portable demo scenario
│   ├── README.md              ← in-depth notes on the demo
│   ├── seed-demo.php          ← step 1: build templates + JSON + filled files
│   ├── install-demo.php       ← step 2: push everything into running plugin
│   ├── example-variables.md   ← placeholder catalogue (reference)
│   ├── collections.json       ← 2 Collections + 6 Datasets manifest
│   ├── demo-template1.docx    ← Executive layout (generated)
│   ├── demo-template2.docx    ← Retail layout (generated)
│   └── filled/*.docx          ← 6 pre-rendered candidates (offline reference)
│
├── fixtures/
│   ├── create_template.php    ← builds the regression fixture
│   ├── test_template.docx     ← neutral fixture used by phase-a/phase-b
│   ├── generate_test_cvs.js   ← builds the sample CVs below
│   └── cv_*.pdf               ← fictional CVs used by e2e tests
│
└── e2e/
    ├── playwright.config.ts
    ├── beta-scenarios.spec.ts
    └── synaform-plugin.spec.ts
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `install-demo.php` → `HTTP 401` on login | Wrong credentials or Synaplan not up | Check `curl -X POST $SYNAPLAN_API_URL/api/v1/auth/login -d '{"email":"...","password":"..."}' -H content-type:application/json` first. |
| `install-demo.php` → template uploads succeed but `placeholders: 0` | DOCX is malformed XML (e.g. unescaped `&`) — the plugin's parser bails | Re-run `seed-demo.php` to regenerate; the seed script only uses safe characters. |
| Plugin UI shows the two Collections but candidates have no values | Install ran without `--wipe` after a previous partial install — stale forms got created | `php tests/demo/install-demo.php --wipe --generate`. |
| `phase-a-lists.php` / `phase-b-stations.php` fail | Test fixture missing or damaged | Regenerate with `php tests/fixtures/create_template.php` (needs PhpWord). |
| `seed-demo.php` → `PhpOffice\PhpWord autoloader not found` | The script tried three paths and none exist | Install PhpWord locally (`composer require phpoffice/phpword`) or set the autoload path to your Synaplan backend's `vendor/autoload.php`. |
| Demo files in `tests/demo/filled/` can't be overwritten | They were created as `root` from inside a Docker container in a previous run | `rm tests/demo/filled/*.docx` (works because the directory is yours) and re-run `seed-demo.php`. |
