# Synaform — AI-Powered Document Merge Plugin for Synaplan

Synaform is a [Synaplan](https://synaplan.com) plugin that turns any repeatable data-collection workflow into a **Collection** — a bundle of the questions you need answered, the Word templates you want filled, and every real-world dataset you process. It uses AI to extract structured information from uploaded files and fills DOCX templates with the results — no manual copy-paste required.

Originally built for an HR customer turning candidate CVs and interview notes into standardised profile documents, Synaform is flexible enough for any use case where multiple inputs need to be combined into a single templated output: customer onboarding, incident reports, intake forms, case files, and more.

## Core Concept: Collections

A **Collection** contains three things:

- **Variables** — the fields you want filled (`firstname`, `start_date`, …)
- **Target Templates** — Word documents with `{{placeholders}}` that get filled automatically
- **Datasets** — every real case you process (a candidate, a customer, a ticket, …)

Create one Collection per workflow, keep them focused, and export the datasets as CSV when you need them elsewhere.

## How It Works

```
Define a Collection ──► Add Target Template ──► Create Datasets ──► Export CSV
  (variables +            (upload .docx            (upload sources,     (flat table,
   purpose)                with placeholders)        fill forms,          any tool
                                                     let AI do the rest)  can read)
```

1. **Create a Collection** — give it a purpose, language, and a short description
2. **Define Variables** — list the fields you need (or let AI import them from a pasted variable definition)
3. **Upload Target Templates** — .docx files using `{{variable}}` placeholders
4. **Create Datasets** — upload source documents, fill what you know, let AI extract the rest
5. **Generate & Export** — produce filled .docx per dataset, and bulk-export datasets as CSV

## Features

- **Collection-first UX** — every workflow is one self-contained Collection (variables + templates + datasets + export)
- **AI-powered extraction** from PDFs and documents using Synaplan's multi-model AI (Ollama, OpenAI, Anthropic, Groq, Gemini)
- **DOCX template engine** with `{{placeholder}}` syntax — upload your own Word templates
- **Automatic placeholder detection** — scans templates and maps them to variables, one click to add the missing ones
- **LLM-as-judge validation** — optional second-pass AI review of extracted data for accuracy
- **Flat CSV export** — download every dataset in a Collection with status and date-range filters
- **Danger-zone deletion** — typed-name confirmation prevents accidental data loss with cascaded datasets
- **Multi-language UI** — English, German, Spanish, and Turkish out of the box
- **Non-invasive plugin architecture** — no changes to Synaplan core, uses the generic `plugin_data` table

## Installation

Requires a running [Synaplan](https://github.com/metadist/synaplan) instance.

```bash
# Copy the plugin into Synaplan's plugin directory
cp -r synaform-plugin/ /path/to/synaplan/plugins/synaform/

# Clear the Symfony cache
cd /path/to/synaplan && php bin/console cache:clear

# Install for a user
php bin/console app:plugin:install <userId> synaform
```

The plugin will be available at `/plugins/synaform` in the Synaplan UI.

## Plugin Structure

```
synaform-plugin/
├── manifest.json                 # Plugin metadata, routes, config schema
├── backend/
│   └── Controller/
│       └── SynaformController.php   # All API endpoints
├── frontend/
│   ├── index.js                  # Vanilla JS single-page application
│   └── i18n/
│       ├── en.json               # English
│       ├── de.json               # German
│       ├── es.json               # Spanish
│       └── tr.json               # Turkish
└── migrations/
    └── 001_setup.sql             # Per-user config setup
```

## API Endpoints

All routes are namespaced under `/api/v1/user/{userId}/plugins/synaform/`. The backend retains the original resource names (`forms`, `candidates`) — the Collection-centric UX is a thin layer on top:

| Area | Endpoints | Description |
|------|-----------|-------------|
| **Config** | `GET/PUT /config` | Plugin settings (company name, AI model, language) |
| **Forms** (= Collections) | `GET/POST/PUT/DELETE /forms` | A form record carries `name`, `description`, `language`, `fields` (= variables), and `template_ids` (attached target templates). |
| **Candidates** (= Datasets) | `GET/POST/PUT/DELETE /candidates` | Dataset records (one per real case). `form_id` links back to the Collection. |
| **Extraction** | `POST /candidates/{id}/extract` | Run AI extraction on uploaded documents |
| **Variables** | `GET/PUT /candidates/{id}/variables` | View and override extracted variables |
| **Templates** | `GET/POST/DELETE /templates` | Upload and manage DOCX templates (attached to Collections via `template_ids`) |
| **Generation** | `POST /candidates/{id}/generate/{templateId}` | Generate filled document |
| **Downloads** | `GET /candidates/{id}/documents/{docId}/download` | Download generated DOCX |

## Configuration

All settings are managed through the plugin UI. Key options:

| Setting | Description |
|---------|-------------|
| `company_name` | Branding for generated documents |
| `default_language` | UI language (`en`, `de`, `es`, `tr`) |
| `extraction_model` | AI model for document extraction (defaults to user's Synaplan chat model) |
| `validation_model` | AI model for extraction validation (LLM-as-judge) |
| `default_template_id` | Pre-selected DOCX template for generation |

## Development

Plugin source code lives in this repository. To develop locally:

```bash
# Sync plugin to your local Synaplan instance
cp -r synaform-plugin/ /path/to/synaplan/plugins/synaform/

# Watch for changes (optional)
fswatch -o synaform-plugin/ | xargs -n1 -I{} cp -r synaform-plugin/ /path/to/synaplan/plugins/synaform/
```

CI runs PHP (PSR-12) and JavaScript (Prettier) formatting checks, plus i18n key consistency validation on every push.

## Related

- **[Synaplan](https://github.com/metadist/synaplan)** — The open-source AI knowledge management platform that Synaform plugs into
- **[synaplan.com](https://synaplan.com)** — Project homepage, documentation, and live demo

## License

Apache License 2.0 — see [LICENSE](LICENSE) for details.
