# Synaform Plugin - Live Installation Guide

Step-by-step instructions to deploy the Synaform plugin on the production Synaplan platform.

## Prerequisites

- Synaplan platform running (`ghcr.io/metadist/synaplan:latest`)
- SSH access to the production server(s)
- The `plugins/` directory mounted into the container (see `docker-compose.yml`)
- `phpoffice/phpword` is already bundled in the Synaplan Docker image

## Step 1: Copy the plugin files to the server

From your development machine, copy the `synaform-plugin/` directory to the production server's `plugins/` folder (the one mounted into the container at `/plugins`).

```bash
# From your local machine / CI runner
scp -r synaform-plugin/ user@web.synaplan.com:/path/to/synaplan-platform/plugins/synaform/
```

Or if you're already on the server:

```bash
# On the production server, inside the synaplan-platform directory
mkdir -p plugins/synaform
cp -r /path/to/synaplan-synaform/synaform-plugin/* plugins/synaform/
```

The final structure on the server should be:

```
synaplan-platform/
├── docker-compose.yml
├── plugins/
│   └── synaform/
│       ├── manifest.json
│       ├── backend/
│       │   └── Controller/
│       │       └── SynaformController.php
│       ├── frontend/
│       │   ├── index.js
│       │   └── i18n/
│       │       ├── de.json
│       │       ├── en.json
│       │       ├── es.json
│       │       └── tr.json
│       └── migrations/
│           └── 001_setup.sql
└── ...
```

## Step 2: Verify the Docker volume mount

Make sure `docker-compose.yml` already has the plugins volume mounted. It should contain:

```yaml
volumes:
  - ./plugins:/plugins:ro
```

This is already present in the standard `synaplan-platform` setup. If not, add it and proceed to Step 3.

## Step 3: Restart the container

The plugin system auto-discovers plugins on startup (routes, services, and autoloading are all dynamic). A restart is needed so the Symfony cache picks up the new plugin.

```bash
cd /path/to/synaplan-platform
docker compose restart backend
```

Or if you prefer a full recreate:

```bash
docker compose down && docker compose up -d
```

Wait for the health check to pass (~30-60 seconds):

```bash
docker compose ps   # should show "healthy"
```

## Step 4: Install the plugin for users

The plugin needs to be "installed" per user. This runs the migration SQL that creates the BCONFIG entries (enabled flag, default settings).

**For a single user:**

```bash
docker compose exec backend php bin/console app:plugin:install <USER_ID> synaform
```

Replace `<USER_ID>` with the numeric user ID (e.g., `1` for the admin).

**For all active verified users at once:**

```bash
docker compose exec backend php bin/console app:plugin:install-verified-users synaform
```

## Step 5: Initialize the default questionnaire

After installation, each user needs to trigger the initial setup (seeds the default form with standard fields like Vorname, Nachname, Vorgestellte Position, etc.).

**Option A** - The user opens Synaform in the UI for the first time and the setup runs automatically.

**Option B** - Trigger it manually via API:

```bash
docker compose exec backend php -r "
\$ch = curl_init('http://localhost/api/v1/user/<USER_ID>/plugins/synaform/setup');
curl_setopt(\$ch, CURLOPT_POST, true);
curl_setopt(\$ch, CURLOPT_HTTPHEADER, ['X-API-Key: <API_KEY>']);
curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
echo curl_exec(\$ch);
curl_close(\$ch);
"
```

## Step 6: Upload a Word template

The user can upload `.docx` templates through the UI (Zielvorlagen tab), or you can do it via API:

```bash
curl -X POST \
  -H "X-API-Key: <API_KEY>" \
  -F "file=@my-template.docx" \
  -F "name=My Template" \
  https://web.synaplan.com/api/v1/user/<USER_ID>/plugins/synaform/templates
```

## Verification

After installation, verify everything works:

```bash
# 1. Check plugin is recognized
docker compose exec backend php bin/console app:plugin:install <USER_ID> synaform
# Should output: "Plugin 'synaform' installed successfully" (or already installed)

# 2. Check the API responds
curl -s -H "X-API-Key: <API_KEY>" \
  https://web.synaplan.com/api/v1/user/<USER_ID>/plugins/synaform/setup-check | python3 -m json.tool
```

Expected response:

```json
{
  "success": true,
  "status": "ready",
  "checks": {
    "plugin_installed": true,
    "has_forms": true,
    "has_templates": false,
    "has_candidates": false
  }
}
```

## Updating the Plugin

To deploy a new version, simply overwrite the files and restart:

```bash
# On the production server
rm -rf plugins/synaform/*
cp -r /path/to/new-version/* plugins/synaform/

# Restart to pick up changes
docker compose restart backend
```

No database migration is needed for code-only updates. The plugin uses `plugin_data` (generic table) and `BCONFIG` -- both are already present in Synaplan.

## Multi-Server Setup

If running multiple web servers (web1, web2, web3 behind a load balancer):

1. Copy `plugins/synaform/` to **each** server's `plugins/` directory
2. Restart the container on **each** server
3. The `app:plugin:install` command only needs to run **once** (it writes to the shared database)
4. Uploaded files (templates, CVs, generated docs) are stored under `./up/` which should already be on shared NFS storage

## Troubleshooting

| Problem | Cause | Fix |
|---------|-------|-----|
| 404 on `/api/v1/user/.../plugins/synaform/...` | Container not restarted after adding plugin | `docker compose restart backend` |
| "Plugin not available" in UI | BCONFIG `enabled` not set | Re-run `app:plugin:install <userId> synaform` |
| "No forms" / empty questionnaire | Setup not triggered | Call `POST /setup` or open plugin in UI |
| AI extraction fails | No AI model configured for the user | Check user has a default CHAT model in Synaplan settings |
| Generated DOCX has empty placeholders | Template uses `{{firstname}}` but form field not filled | Fill Vorname/Nachname in the questionnaire first |
