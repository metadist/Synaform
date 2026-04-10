# TemplateX — AI-Powered Document Merge Plugin for Synaplan

TemplateX is a [Synaplan](https://synaplan.com) plugin that merges data from questionnaires, CVs, forms, and other document sources into professionally templated Word documents. It uses AI to extract structured information from uploaded files and fills DOCX templates with the results — no manual copy-paste required.

Built for an HR customer who needed to turn candidate CVs and interview notes into standardised profile documents, TemplateX is flexible enough for any use case where multiple inputs need to be combined into a single, templated output.

## How It Works

```
Upload sources ──► AI extraction ──► Review variables ──► Generate DOCX
  (CV, forms,        (structured        (edit/override       (filled
   documents)         data out)          any field)           template)
```

1. **Define forms** — Create questionnaire forms with custom fields, or import them from existing documents using AI
2. **Upload sources** — Add CVs (PDF), supporting documents, or fill in form data manually
3. **AI extraction** — TemplateX uses your configured Synaplan AI model to extract structured variables from uploaded files
4. **Review & override** — All extracted variables are editable before generation
5. **Generate documents** — Select a DOCX template and generate the filled output, ready to download

## Features

- **AI-powered extraction** from PDFs and documents using Synaplan's multi-model AI (Ollama, OpenAI, Anthropic, Groq, Gemini)
- **DOCX template engine** with `{{placeholder}}` syntax — upload your own Word templates
- **Automatic placeholder detection** — scans templates and maps variables to form fields
- **LLM-as-judge validation** — optional second-pass AI review of extracted data for accuracy
- **Multi-language UI** — English, German, Spanish, and Turkish out of the box
- **Non-invasive plugin architecture** — no changes to Synaplan core, uses the generic `plugin_data` table

## Installation

Requires a running [Synaplan](https://github.com/metadist/synaplan) instance.

```bash
# Copy the plugin into Synaplan's plugin directory
cp -r templatex-plugin/ /path/to/synaplan/plugins/templatex/

# Clear the Symfony cache
cd /path/to/synaplan && php bin/console cache:clear

# Install for a user
php bin/console app:plugin:install <userId> templatex
```

The plugin will be available at `/plugins/templatex` in the Synaplan UI.

## Plugin Structure

```
templatex-plugin/
├── manifest.json                 # Plugin metadata, routes, config schema
├── backend/
│   └── Controller/
│       └── TemplateXController.php   # All API endpoints
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

All routes are namespaced under `/api/v1/user/{userId}/plugins/templatex/`.

| Area | Endpoints | Description |
|------|-----------|-------------|
| **Config** | `GET/PUT /config` | Plugin settings (company name, AI model, language) |
| **Forms** | `GET/POST/PUT/DELETE /forms` | Define questionnaire forms with custom fields |
| **Candidates** | `GET/POST/PUT/DELETE /candidates` | Manage data subjects (candidates, cases, etc.) |
| **Extraction** | `POST /candidates/{id}/extract` | Run AI extraction on uploaded documents |
| **Variables** | `GET/PUT /candidates/{id}/variables` | View and override extracted variables |
| **Templates** | `GET/POST/DELETE /templates` | Upload and manage DOCX templates |
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
cp -r templatex-plugin/ /path/to/synaplan/plugins/templatex/

# Watch for changes (optional)
fswatch -o templatex-plugin/ | xargs -n1 -I{} cp -r templatex-plugin/ /path/to/synaplan/plugins/templatex/
```

CI runs PHP (PSR-12) and JavaScript (Prettier) formatting checks, plus i18n key consistency validation on every push.

## Related

- **[Synaplan](https://github.com/metadist/synaplan)** — The open-source AI knowledge management platform that TemplateX plugs into
- **[synaplan.com](https://synaplan.com)** — Project homepage, documentation, and live demo

## License

Apache License 2.0 — see [LICENSE](LICENSE) for details.
