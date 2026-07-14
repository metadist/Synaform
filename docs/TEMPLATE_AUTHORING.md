# Template Authoring Guide

Synaform fills your Word `.docx` documents by replacing `{{placeholders}}` with
data from a Collection. The engine handles a wide variety of layouts —
scalars, lists, repeating tables, paired checkboxes, images, header/footer
substitution — but a few authoring patterns produce broken output. This guide
is the contract between the people who design templates in Word and the
engine that fills them.

If you follow the 10 rules below, your templates render exactly the way they
look in Word.

> **Tip:** every time you upload a template, Synaform runs the **Template
> Doctor** automatically and shows any issues it finds — including the
> placeholder, the line where it lives, and a one-sentence fix. You can also
> re-run the doctor at any time from the template's row in the **Set up**
> tab.

---

## Quick reference

| Placeholder shape | What it does | Where it works |
|---|---|---|
| `{{firstname}}` | Single text value | body, headers, footers |
| `{{relevant_positions}}` | Multi-line list — one bullet per item | body only |
| `{{stations.time.N}}`, `{{stations.employer.N}}`, `{{stations.details.N}}` | Repeating table row, one row per station | body, **inside a `<w:tr>`** |
| `{{checkb.moving.yes}}` / `{{checkb.moving.no}}` | Paired checkbox glyphs (☒ / ☐) | body, headers, footers |
| `{{candidate_photo}}` (declared as `image` variable) | Inline or floating image (per the variable's design options) | body, headers, footers |

The Template Doctor uses these shapes to figure out which rules apply.

**Empty variables stay visible.** When a variable has no value at generation
time, the placeholder is kept in the document as literal `{{key}}` text with a
**yellow highlight** — it is never silently blanked out. Fill the value (or
re-run "Read files & auto-fill") and generate again to resolve it.

**Image positioning.** An `image` variable renders inline at the placeholder
by default. In the variable's design options you can switch it to **Floating**:
the picture is then anchored to the page (e.g. upper right corner = Right +
Top, relative to the page margins) and body text wraps around it. If a source
PDF (e.g. a CV) contains a portrait photo, "Read files & auto-fill"
automatically assigns it to an empty image variable; a manually uploaded
image always wins.

---

## The 10 rules

### Rule 1 — One scalar per paragraph, separated visually

If a paragraph contains two or more scalar placeholders, **put a visible
separator between them**: a space, a tab, a line break, or a different table
cell. The Doctor flags `{{a}}{{b}}` as an **error** because the values will
collide ("02/2021 – heuteBeispiel AG").

✅ `Profil {{fullname}} {{generated_month}} {{generated_year}}`
✅ `{{zip}} {{city}}`
✅ `{{stations.time.N}}<TAB><TAB>{{stations.employer.N}}` (works, but rule 6 is better)

❌ `{{stations.time.N}}{{stations.employer.N}}` — fused, no separator.

### Rule 2 — Lists belong on their own paragraph

A list-typed placeholder (`{{benefits}}`, `{{relevant_positions}}`,
`{{languages}}`, `{{other_skills}}`, `{{education}}`, anything ending in
`list`) is rendered as **one paragraph per item**. The engine clones the host
paragraph for each item, so any text that shares the paragraph gets
duplicated for every list item.

✅
```
Sonstige Leistungen:
{{benefits}}
```
(`Sonstige Leistungen:` on its own paragraph; `{{benefits}}` on the next.)

❌
```
Sonstige Leistungen: {{benefits}}
```
(every benefit will be prefixed with "Sonstige Leistungen:")

### Rule 3 — Repeating data uses one table row, not multiple

The `stations` table (`{{stations.time.N}}`, `{{stations.employer.N}}`,
`{{stations.position.N}}`, `{{stations.details.N}}`) is cloned once per data
row. **Put all four placeholders inside a single `<w:tr>`** — the engine
clones that row, not the whole table or a multi-row block.

The recommended layout is a 2-column table:

| left cell (~3.5 cm wide) | right cell (~12 cm wide) |
|---|---|
| `{{stations.time.N}}` | `{{stations.employer.N}}` (bold) |
|  | `{{stations.position.N}}` |
|  | `{{stations.details.N}}` |

This is exactly the shape used in the canonical
`Profil hhff DE v5.docx` and `Profil NeedleHaystack DE v5.docx`.

❌ Spreading time / employer / position / details across **three separate
rows** of the same table — only the row containing the first placeholder
(`{{stations.time.N}}`) gets cloned, so rows 2 and 3 appear once for the
whole Werdegang regardless of how many stations you have.

### Rule 4 — Tables must use attribute-bearing `<w:tr>`

Word's standard "Insert Table" command always emits rows with `w:rsidR`
attributes. If you build a row programmatically (for example with a third-
party tool that emits bare `<w:tr>`), Synaform's pre-pass auto-injects a
synthetic attribute so PhpWord's `cloneRow` can find the row reliably. This
defensive patch ensures the row never gets confused with a row in the
*previous* table on the same page (the bug that caused the "sonstige
Kenntnisse leaks into Werdegang" issue on the v5 NeedleHaystack template).

The Template Doctor reports this as **info** (not an error) — your file
still renders correctly, but the source `.docx` will be cleaner if you
re-edit the row in Word: *click in the row → Table Properties → OK*. Saving
re-emits the row with proper attributes.

### Rule 5 — Lists and repeating tables go in the body, not headers/footers

The body of the document is the only place where the engine performs list
expansion (`{{benefits}}`) and table-row cloning (`{{stations.*.N}}`).
Headers and footers support **only scalar placeholders** like `{{fullname}}`,
`{{generated_year}}`, `{{target_position}}`. Putting a list in a header
silently produces a single concatenated string instead of bullet points.

Rule of thumb: if the placeholder ends in `list` or carries an `.N` suffix,
keep it in the body.

### Rule 6 — Use real Word tables for column-split data

Whenever you have data that needs to live in two columns of a single line
(time + employer, label + value, key + amount), **use a Word table cell**,
not tabs. Tabs work for simple cases (rule 1 will accept tab-separated
placeholders), but the resulting layout is fragile: if the value gets longer
than the tab stop, the line wraps and the columns no longer align.

Examples that should be tables, not tabs:
- Werdegang: `time` left | `employer / position / details` right
- Personal data: label cell | value cell
- Salary line: `Currency` cell | `Amount` cell

### Rule 7 — Don't nest placeholders in content controls

A `<w:sdt>` content control (the grey-background, label-prefixed boxes in
Word) hides its inner text from the placeholder substitution engine. Any
`{{...}}` inside a content control will appear as literal text in the
output.

Fix: select the content control in Word → *Developer* tab → *Properties* →
turn off *Contents cannot be edited* (or remove the content control
entirely). The Template Doctor flags this as a **warning**.

### Rule 8 — Paired checkbox lines need a tab or a space, not nothing

The `{{checkb.X.yes}} Ja   {{checkb.X.no}} Nein` shape is the canonical
"clickable checkbox" line. The engine post-processes both placeholders into
proper Word content-control checkboxes (`<w:sdt>` with `<w14:checkbox>`),
preserving any inline content (tabs, breaks, symbols) between rPr and
`<w:t>`. This works on every modern Word client (Word 2010+, Word for the
web, Word for Mac, LibreOffice Writer).

Things to keep in mind:

- Type the line as plain text in Word — don't use Word's built-in checkbox
  symbols. Synaform produces its own SDT-based checkbox.
- The space (or tab) between `{{checkb.X.yes}}` and the label "Ja" is
  preserved in the rendered output, so you control the spacing.
- The whole line can sit inside a header / footer — checkbox post-processing
  is body + header + footer aware.

### Rule 9 — Author placeholders in one shot, no autocorrect breaks

Word's spell-checker likes to insert `<w:proofErr>` tags into runs that
contain unusual character sequences like `{{slug}}`. The engine's pre-pass
defragments these so you don't have to think about them, BUT — the
defragmentation only runs for body, header, and footer parts, and it only
recognises `{{...}}` braces. If your authored text accidentally splits the
braces (`{` `{` typed in two separate runs because you went back to type a
character in between), Word can write that to disk in a way our regex
doesn't catch.

Practical workaround: when typing a placeholder, **type the whole token in
one go** (`{{firstname}}`), then move on. If you spot red wavy underlines
under a placeholder, right-click → *Ignore* before saving. The Template
Doctor automatically defragments before counting placeholders, so its
"placeholders detected" number is the source of truth.

### Rule 10 — Fonts are inherited from the surrounding paragraph

You don't have to declare a font on the `{{placeholder}}` itself. The engine
walks the paragraph at upload time, picks up the dominant `<w:rFonts>` /
`<w:sz>` / `<w:szCs>`, and bakes that style onto every run that hosts a
placeholder. So you can author placeholders in Word's default font and they
will still render in your customer-brand font (Arial 12pt for the hhff
template, Helvetica-Light 10pt for NeedleHaystack) at generation time.

If you do want a placeholder bold or underlined or coloured, set the
formatting in Word the normal way (select the `{{placeholder}}` text → Ctrl
+ B, etc.). The engine preserves the run-level formatting verbatim.

---

## Working with the Template Doctor

Every template upload returns a `lint` block in the `template` JSON. The
plugin UI surfaces it like this:

- **0 findings**: green toast "Saved!", template appears in the Set-up tab.
- **Warnings or infos only**: blue info toast "Template uploaded — doctor
  flagged some items (1 warning, 2 info)". Click the template row to see
  details.
- **Errors**: blocking modal listing every error, code, message, and the
  one-line fix. Generation will produce broken output until errors are
  resolved.

You can re-run the doctor at any time from the API:

```bash
curl -s https://your.synaplan.example/api/v1/user/1/plugins/synaform/templates/{tplId}/lint | jq
```

The response shape is:

```json
{
  "success": true,
  "lint": {
    "ok": true,
    "summary": {
      "errors": 0,
      "warnings": 0,
      "infos": 0,
      "placeholders": 32,
      "paragraphs_with_placeholder": 26,
      "bare_rows_promoted": 0
    },
    "findings": []
  }
}
```

When `lint.ok === true` and `lint.summary.errors === 0`, the template is
release-ready. When `errors > 0`, fix in Word, re-upload, repeat.

---

## Frequently asked

**My template renders Calibri instead of my brand font (Arial / Helvetica /
Bebas Neue / …)** — that's fixed in v3.1.0+. The engine bakes the dominant
font into every placeholder run during upload. Make sure your placeholder
sits inside a paragraph that has at least one run with an explicit
`<w:rFonts>` declaration; if every run on the paragraph relies on the
document default, the engine falls back to that default at render time.

**Some placeholders show up as raw `{{slug}}` text in the output** — this
means the placeholder lives in a part of the document where the engine
doesn't run substitution (most likely inside a content control — see rule 7).
Move the placeholder out of the content control.

**My checkboxes are not clickable in Word** — make sure the variable is
declared as `type: "checkbox"` in the Collection and the template uses the
paired form `{{checkb.X.yes}} / {{checkb.X.no}}` (not the plain `{{X}}`).
The engine post-processes the paired form into Word content-control
checkboxes; the plain form renders as text "Ja" / "Nein".

**A `{{stations.position.N}}` placeholder isn't being replaced** — make sure
all four `{{stations.*.N}}` placeholders sit inside the **same `<w:tr>`**.
PhpWord's `cloneRow` only clones one row — the one containing the first
placeholder. Placeholders in adjacent rows of the same table only render
once, for the first station.

**The Werdegang time + employer collide on one line** — this is the
release-blocking layout bug fixed in v3.2.0 + v5 templates. The fix is
both engine-side (the SDT post-pass now tolerates inline `<w:tab/>` and
`<w:br/>` between rPr and `<w:t>`) and template-side (use a 2-column table
row, see rule 3). The reference templates `Profil hhff DE v5.docx` and
`Profil NeedleHaystack DE v5.docx` show the canonical shape.

**The doctor flagged a header/footer placeholder as a list issue** — drop
the `list` suffix from the variable name and store the data as a single
string, or move the placeholder into the document body. List expansion only
runs on the body.

---

## Profile photo (`{{photo}}`) and auto-extraction from the CV

Declare an **image** variable (e.g. key `photo`) and place its `{{photo}}`
placeholder where the portrait should appear. Size and positioning
(inline vs. floating, width/height) are controlled from the variable
designer — the placeholder itself is just the anchor.

Since v4.1.0 the engine tries to **auto-fill an empty image variable with a
portrait found in the uploaded CV** during "Read files & auto-fill":

- **PDF CVs** — embedded images are extracted with `pdfimages`.
- **DOCX CVs** — images under `word/media/*` are scanned.
- The **largest portrait-ratio** image (aspect ratio between 0.5 and 1.15,
  min 100×100 px) wins; landscape logos/banners are ignored.
- The result is stored as PNG and shown in Edit Details with a **"from CV"**
  badge. The recruiter can always replace or remove it by hand.

Naming the variable/label with `photo`, `foto`, `portrait`, `bild`,
`picture`, `profil` or `headshot` makes the auto-extractor prefer that field
when the Collection has several image variables.

If no suitable portrait is found the field simply stays empty — generation is
never blocked.

---

## Mini-checklist before shipping a template

- [ ] Open the rendered DOCX with realistic data filled in. Does it look
      like the reference?
- [ ] Run the Template Doctor — `lint.ok === true`, `summary.errors === 0`.
- [ ] All `{{stations.*.N}}` placeholders sit in the same single `<w:tr>`.
- [ ] No two placeholders are fused (`{{a}}{{b}}`).
- [ ] List-typed placeholders sit on their own paragraphs.
- [ ] Headers and footers use only scalar placeholders.
- [ ] No `{{...}}` is nested inside a content control.
- [ ] Brand fonts render correctly on a fresh CV (no Calibri leakage).
- [ ] Checkboxes are clickable in Word and pre-set to the resolved state.
