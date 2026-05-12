# PR #916 evaluation — Copilot review findings, locally verified

PR: https://github.com/metadist/synaplan/pull/916  
Verified against: `/wwwroot/synaplan/` running stack on 2026-05-12.

## TL;DR

Copilot raised 5 concerns. Three are **real and reproducible** PHP bugs in
`TemplateHtmlPreviewService`. One is a real but **non-manifesting**
portability concern. One is a meta comment about the PR description.
The 3 PHP bugs are fixed in this commit; the SQL portability concern
is documented but not changed (verified working against MariaDB 12 +
PDO MySQL on the live driver stack).

| # | Copilot file:line | Verdict | Action |
|---|-------------------|---------|--------|
| 1 | `migrations/001_setup.sql:7-22` — multi-statement migration may fail with single `executeStatement()` | **Real concern, but does not manifest.** Verified all 6 INSERTs run on a fresh user (count = 6). Doctrine + MariaDB driver path supports multi-statement under our config. | Documented; no change. |
| 2 | `manifest.json:1-12` — PR description scope mismatch | Meta-only. The PR is now effectively a no-op against `main` after my untrack commit. | No code change. |
| 3 | `TemplateHtmlPreviewService.php:206` — `./w:r` only catches direct paragraph children, drops hyperlinks | **Reproduced.** Test docx with `addLink('https://example.com', 'CLICK_HERE')` produces preview HTML with `CLICK_HERE` **completely missing**. | Fixed (collectRuns walks paragraph children in document order, recurses into `<w:hyperlink>` / `<w:smartTag>` / `<w:fldSimple>` / `<w:sdt>` / `<w:sdtContent>` / `<w:customXml>`). |
| 4 | `TemplateHtmlPreviewService.php:216` — `<w:br>` collected after all `<w:t>`, not in order | **Reproduced** + worse than reported: PhpWord emits `<w:br>` as a paragraph-level **sibling** of `<w:r>` (not as a child), so the previous code's `xpath('.//w:br', $run)` missed every break entirely. Test produced `LINE_ONELINE_TWO` with no break. | Fixed (runText walks run children in order; collectRuns also handles paragraph-level `<w:br>` and `<w:tab>` siblings by appending to the previous run's text). |
| 5 | `TemplateHtmlPreviewService.php:75` — `@$doc->loadXML($xml)` swallows parse errors | **Real.** With `@`, a malformed `word/document.xml` (truncated upload, weird ZIP) just silently produces an empty preview with no log line — extremely hard to diagnose. | Fixed (libxml_use_internal_errors + libxml_get_errors; logs the first 3 errors at WARNING level; returns the existing `emptyResult()` graceful fallback). |

## Reproduction & verification

### Bug #3 + #4 reproducer

```php
$pw = new PhpWord();
$s = $pw->addSection();
$s->addText('Before hyperlink, ');
$s->addLink('https://example.com', 'CLICK_HERE_LINK_TEXT');
$s->addText(' after hyperlink.');

$tr = $pw->addSection()->addTextRun();
$tr->addText('LINE_ONE');
$tr->addTextBreak();
$tr->addText('LINE_TWO');
```

**Before fix:**

```html
<p>Before hyperlink, </p>
<p class="tx-empty">&nbsp;</p>      ← hyperlink anchor text DROPPED
<p> after hyperlink.</p>
<p>LINE_ONELINE_TWO</p>             ← no break between lines
```

**After fix:**

```html
<p>Before hyperlink, </p>
<p>CLICK_HERE_LINK_TEXT</p>
<p> after hyperlink.</p>
<p>LINE_ONE<br/>LINE_TWO</p>
```

Placeholders inside the same run still work after the fix:
`Hi {{firstname}}<br>See you at {{location}}.` correctly extracts both
`firstname` and `location` and renders the break between them.

### Bug #5 reproducer

A docx whose `word/document.xml` is the literal string `<this is not valid xml`:

**Before fix:** silent empty preview, no log entry, no clue why.
**After fix:** preview falls back to "Preview unavailable" *and* the
application log gets a WARNING with the libxml error messages
(`Couldn't find end of Start Tag this line 1` etc.).

### #1 verification (multi-statement SQL)

Re-ran `app:plugin:install 99999 synaform` on a fresh user, then:

```sql
SELECT COUNT(*) FROM BCONFIG WHERE BOWNERID=99999 AND BGROUP='P_synaform';
-- 6
```

All six `INSERT IGNORE` statements were applied. The Doctrine + MariaDB
PDO stack on this Docker image supports multi-statement queries via
its default driver options, so the migration works as written. If a
future host runs against a driver that disables multi-statement
(`PDO::MYSQL_ATTR_MULTI_STATEMENTS = false`), `001_setup.sql` would
need to be split into six files (`001_…sql`, `002_…sql`, …) or the
PluginManager would need to split-and-loop. We can do that pre-emptively
later if the platform stack changes; not blocking today.

## Diff overview

`synaform-plugin/backend/Service/TemplateHtmlPreviewService.php`:

- `build()` — replace `@$doc->loadXML($xml)` with libxml error queue +
  WARNING log on parse failure.
- `renderRuns()` — delegate to new `collectRuns()` instead of running
  `xpath('./w:r', $p)` directly.
- New `collectRuns(\DOMElement, \DOMXPath, &array)` — recursive walk
  of paragraph children in document order; emits one entry per `<w:r>`,
  recurses into inline containers, and handles paragraph-level `<w:br>` /
  `<w:tab>` siblings.
- New `runText(\DOMElement)` — walks run children in document order and
  emits text/`\n`/`\t` at the correct positions.

Net change: 1 file, +63 / -7 lines.
