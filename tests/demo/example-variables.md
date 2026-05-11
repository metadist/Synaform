# Example Variables — Fashion HR Candidate Profile

This is a freshly transcribed, Synaform-friendly version of the original
HR "variable-list" document used as the source of truth for a German-language
fashion-industry candidate-profile workflow.

Every row lists a `{{placeholder}}` plus where the value usually comes from
(manual form, PDF CV extraction, or a derived fallback). The placeholders are
exactly the ones expected by `demo-template1.docx` and `demo-template2.docx`
produced by `seed-demo.php`.

## Scalar placeholders

| Placeholder | Meaning / Source |
|---|---|
| `{{target-position}}` | Die offene Stelle (Zielposition). Kommt aus dem Formular. |
| `{{month}}` | Monat in dem das Profil erstellt wurde. |
| `{{year}}` | Jahr in dem das Profil erstellt wurde. |
| `{{fullname}}` | Vollständiger Name. Aus dem Lebenslauf zu extrahieren. |
| `{{address1}}` | Straße und Hausnummer. Aus dem Lebenslauf zu extrahieren. |
| `{{address2}}` | Ort. Aus dem Lebenslauf zu extrahieren. |
| `{{zip}}` | Postleitzahl. Aus dem Lebenslauf zu extrahieren. |
| `{{birthdate}}` | Geburtsdatum. Aus dem Lebenslauf zu extrahieren, Formular als Fallback. |
| `{{nationality}}` | Nationalität. Kommt aus dem Formular. |
| `{{maritalstatus}}` | Familienstand. Kommt aus dem Formular. |
| `{{number}}` | Telefonnummer. Formular bevorzugt, sonst aus dem Lebenslauf. |
| `{{email}}` | E-Mail-Adresse. Formular bevorzugt, sonst aus dem Lebenslauf. |
| `{{currentposition}}` | Aktuelle Position. Meist aus dem Lebenslauf. |
| `{{moving}}` | Umzugsbereitschaft (Ja / Nein). Aus dem Formular. |
| `{{travelorcommute}}` | Pendel-/Reisebereitschaft (Ja / Nein). Aus dem Formular. |
| `{{noticeperiod}}` | Kündigungsfrist. Aus dem Formular. |
| `{{currentansalary}}` | Aktuelles Bruttojahresgehalt. Aus dem Formular. |
| `{{expectedansalary}}` | Erwartetes Bruttojahresgehalt. Aus dem Formular. Weglassen, wenn „nicht relevant". |
| `{{education}}` | Ausbildung und Studium. Meist aus dem Lebenslauf, Formular als Fallback. |
| `{{workinghours}}` | Vertragliche Arbeitszeit. Aus dem Formular. Weglassen, wenn „nicht relevant". |

## List placeholders (one paragraph per item)

The template engine treats the following placeholders as **list-type**:
every item in the value array is rendered as its own `<w:p>` paragraph,
preserving the surrounding paragraph formatting.

| Placeholder | Meaning / Source |
|---|---|
| `{{relevantposlist}}` | Manuell gepflegte Liste vorheriger relevanter Positionen. |
| `{{relevantfortargetposlist}}` | Für die Zielposition relevante Erfahrung — Direct Reports, Budget-/P&L-Verantwortung, Store-Flächen, BR-Erfahrung, Einstellungen/Entlassungen. |
| `{{languageslist}}` | Sprachkenntnisse mit Level (Muttersprache, Fließend, Verhandlungsfähig, …). |
| `{{benefitslist}}` | Benefits (Firmenwagen, Bonus, bAV, Urlaubstage, …). Aus dem Formular. |
| `{{otherskillslist}}` | Sonstige Kenntnisse (Tools, Software, Zertifikate). |

## Checkbox placeholders (pairs)

Each pair consists of a `yes` and a `no` placeholder. Exactly one of them is
filled with the checkbox character (☒), the other stays empty (☐).

| Placeholder pair | Meaning / Source |
|---|---|
| `{{checkb.moving.yes}}` / `{{checkb.moving.no}}` | Umzugsbereitschaft. Formular, Lebenslauf als Fallback. |
| `{{checkb.commute.yes}}` / `{{checkb.commute.no}}` | Pendelbereitschaft. Formular, Lebenslauf als Fallback. |
| `{{checkb.travel.yes}}` / `{{checkb.travel.no}}` | Reisebereitschaft. Formular, Lebenslauf als Fallback. |

## Optional / conditional placeholders

| Placeholder | Meaning / Source |
|---|---|
| `{{optional.firmenwagen}}` | Nur ausgeben, wenn im Formular aktiviert. Sonst leerlassen. |

## Station placeholders (career path, `.N` = row index)

Each career station is expanded from a single template row into N rendered
rows via PhpWord's `cloneRow()`. The `.N` marker is the suffix PhpWord
leaves behind (`{{stations.employer.N}}` → `{{stations.employer.N#1}}`,
`{{stations.employer.N#2}}`, …). The `details` field accepts multi-line
input with date headers, position titles, and bullet lines — see the
Phase B regression test (`tests/phase-b-stations.php`) for the supported
block formats.

| Placeholder | Meaning / Source |
|---|---|
| `{{stations.employer.N}}` | Arbeitgeber pro Station (Firma, ggf. Firmensitz). Aus dem Lebenslauf. |
| `{{stations.time.N}}` | Zeitraum pro Station (z. B. „02/2021 – heute"). Aus dem Lebenslauf. |
| `{{stations.positions.N}}` | Berufsbezeichnung pro Station (z. B. „Store Manager"). Aus dem Lebenslauf. |
| `{{stations.details.N}}` | Detailtexte pro Station — Datumsheader, Titel, Bullet-Listen. Aus dem Lebenslauf. |

## Example for `{{stations.details.N}}`

```
04/2024 – heute
Business Unit Director Sport, Fashion & Daily Underwear
- Leitung der Teams für Produktmanagement, Design und Marketing
- Verantwortlich für Lieferkette, Beschaffung, Produktion und Logistik
- Umsatz- und Ertragssteigerung

02/2021 – 04/2024
Leitung Marketing Sport / Fashion / Underwear
- Globale Verantwortung für Marketingstrategie in 60+ Ländern
- Aufbau und Führung eines Teams aus Category Managern, Social Media, Trade Marketing
- Neue Markenpartnerschaften (Rowbots, Tatonka, Moncler, Aspen X, True Motion)
```
