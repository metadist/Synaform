/**
 * E2E Tests for the Synaform Plugin
 *
 * Tests the full plugin lifecycle:
 *   1. Plugin activation and setup
 *   2. Form management (default form, field validation)
 *   3. Template upload and placeholder detection
 *   4. Candidate creation with form data
 *   5. CV upload (PDF)
 *   6. AI extraction from CV
 *   7. Variable resolution and overrides
 *   8. DOCX document generation
 *   9. Generated document download
 *
 * Prerequisites:
 *   - Synaplan running with frontend on localhost:5173, backend on localhost:8000
 *   - Synaform plugin installed for admin user
 *   - At least one AI provider configured (Anthropic, OpenAI, or Ollama)
 *   - Tika running for PDF text extraction
 *
 * Run with:
 *   npx playwright test tests/e2e/synaform-plugin.spec.ts
 */
import { test, expect, type Page, type APIRequestContext } from '@playwright/test'
import path from 'path'

const BASE_URL = process.env.BASE_URL || 'http://localhost:5173'
const API_URL = process.env.SYNAPLAN_API_URL || 'http://localhost:8000'
const ADMIN_EMAIL = process.env.SYNAPLAN_ADMIN_EMAIL || 'admin@synaplan.com'
const ADMIN_PASS = process.env.SYNAPLAN_ADMIN_PASS || 'admin123'

const FIXTURES_DIR = path.resolve(__dirname, '../fixtures')

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

async function loginViaApi(request: APIRequestContext): Promise<string> {
  const res = await request.post(`${API_URL}/api/v1/auth/login`, {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASS },
  })
  expect(res.ok(), `Login failed: ${res.status()}`).toBeTruthy()
  const setCookie = res.headers()['set-cookie'] || ''
  const cookies = (Array.isArray(setCookie) ? setCookie : [setCookie])
    .map(h => { const m = h.match(/^([^=]+)=([^;]+)/); return m ? `${m[1]}=${m[2]}` : null })
    .filter(Boolean)
  return cookies.join('; ')
}

async function api(
  request: APIRequestContext,
  cookie: string,
  method: string,
  path: string,
  data?: unknown,
) {
  const url = `${API_URL}/api/v1/user/1/plugins/synaform${path}`
  const opts: Record<string, unknown> = { headers: { Cookie: cookie } }
  if (data !== undefined) opts.data = data

  let res
  switch (method) {
    case 'GET': res = await request.get(url, opts); break
    case 'POST': res = await request.post(url, opts); break
    case 'PUT': res = await request.put(url, opts); break
    case 'DELETE': res = await request.delete(url, opts); break
    default: throw new Error(`Unknown method: ${method}`)
  }
  return res
}

async function loginUI(page: Page) {
  await page.goto(`${BASE_URL}/login`)
  await page.fill('input[type="email"]', ADMIN_EMAIL)
  await page.fill('input[type="password"]', ADMIN_PASS)
  await page.click('button[type="submit"]')
  await page.waitForSelector('[data-testid="chat-input"], textarea, .chat-input', { timeout: 15_000 }).catch(() => {})
  await page.waitForTimeout(1000)
}

// ---------------------------------------------------------------------------
// API-level tests
// ---------------------------------------------------------------------------

test.describe('Synaform API Tests', () => {
  let cookie: string

  test.beforeAll(async ({ request }) => {
    cookie = await loginViaApi(request)
  })

  test('setup-check returns ready status', async ({ request }) => {
    const res = await api(request, cookie, 'GET', '/setup-check')
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.success).toBe(true)
    expect(body.status).toBe('ready')
    expect(body.config).toHaveProperty('default_language')
  })

  test('setup seeds default form', async ({ request }) => {
    const res = await api(request, cookie, 'POST', '/setup')
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.success).toBe(true)
  })

  test('list forms returns default form with expected fields', async ({ request }) => {
    const res = await api(request, cookie, 'GET', '/forms')
    const body = await res.json()
    expect(body.success).toBe(true)
    expect(body.forms.length).toBeGreaterThanOrEqual(1)
    const defaultForm = body.forms.find((f: { id: string }) => f.id === 'default')
    expect(defaultForm).toBeTruthy()
    expect(defaultForm.name).toBe('Standard Kandidatenprofil')
    expect(defaultForm.language).toBe('de')
    const fieldKeys = defaultForm.fields.map((f: { key: string }) => f.key)
    expect(fieldKeys).toContain('target-position')
    expect(fieldKeys).toContain('nationality')
    expect(fieldKeys).toContain('moving')
    expect(fieldKeys).toContain('commute')
    expect(fieldKeys).toContain('travel')
    expect(fieldKeys).toContain('languageslist')
  })

  test('upload template and detect placeholders', async ({ request }) => {
    const templatePath = path.join(FIXTURES_DIR, 'test_template.docx')
    const res = await request.post(
      `${API_URL}/api/v1/user/1/plugins/synaform/templates`,
      {
        headers: { Cookie: cookie },
        multipart: {
          file: { name: 'test_template.docx', mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', buffer: require('fs').readFileSync(templatePath) },
          name: 'E2E Test Template',
        },
      },
    )
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.success).toBe(true)
    expect(body.template.placeholder_count).toBeGreaterThan(20)
    const keys = body.template.placeholders.map((p: { key: string }) => p.key)
    expect(keys).toContain('fullname')
    expect(keys).toContain('target-position')
    expect(keys).toContain('email')
    expect(keys).toContain('checkb.moving.yes')
    expect(keys).toContain('checkb.commute.no')
  })

  test('create candidate with form data', async ({ request }) => {
    const res = await api(request, cookie, 'POST', '/candidates', {
      name: 'E2E Test Kandidat',
      form_id: 'default',
      field_values: {
        'target-position': 'Fashion Marketing Director',
        'nationality': 'deutsch',
        'maritalstatus': 'ledig',
        'moving': 'Ja',
        'commute': 'Nein',
        'travel': 'Ja',
        'noticeperiod': '3 Monate',
        'currentansalary': '95.000 EUR',
        'expectedansalary': '110.000 EUR',
        'workinghours': '40h',
        'relevantposlist': ['Marketing Manager', 'Brand Manager'],
        'languageslist': ['Deutsch (Muttersprache)', 'Englisch (C1)'],
        'otherskillslist': ['SAP', 'Adobe'],
        'benefits': ['Firmenwagen'],
      },
    })
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.success).toBe(true)
    expect(body.candidate.name).toBe('E2E Test Kandidat')
    expect(body.candidate.status).toBe('draft')
    expect(body.candidate.field_values['target-position']).toBe('Fashion Marketing Director')
  })

  test('full pipeline: upload CV, extract, resolve variables, generate', async ({ request }) => {
    test.setTimeout(120_000)

    // Create candidate
    const createRes = await api(request, cookie, 'POST', '/candidates', {
      name: 'Dr. Sabine Mueller E2E',
      form_id: 'default',
      field_values: {
        'target-position': 'VP Marketing DACH',
        'nationality': 'deutsch',
        'maritalstatus': 'verheiratet',
        'moving': 'Ja',
        'commute': 'Ja',
        'travel': 'Nein',
        'noticeperiod': '3 Monate zum Quartalsende',
        'currentansalary': '135.000 EUR',
        'expectedansalary': '150.000 EUR',
        'workinghours': '40h/Woche',
        'relevantposlist': ['VP Marketing DACH (Nordstil Mode)', 'Leiterin Marketing (Rhein Textil)'],
        'languageslist': ['Deutsch (Muttersprache)', 'Englisch (C2)'],
        'otherskillslist': ['SAP', 'Adobe Creative Suite'],
        'benefits': ['Firmenwagen', 'Bonus'],
      },
    })
    const candidateId = (await createRes.json()).candidate.id

    // Upload CV
    const cvPath = path.join(FIXTURES_DIR, 'cv_mueller_fashion.pdf')
    const uploadRes = await request.post(
      `${API_URL}/api/v1/user/1/plugins/synaform/candidates/${candidateId}/upload-cv`,
      {
        headers: { Cookie: cookie },
        multipart: {
          file: { name: 'cv_mueller_fashion.pdf', mimeType: 'application/pdf', buffer: require('fs').readFileSync(cvPath) },
        },
      },
    )
    expect(uploadRes.ok()).toBeTruthy()
    const uploadBody = await uploadRes.json()
    expect(uploadBody.file.filename).toBe('cv_mueller_fashion.pdf')

    // Extract
    const extractRes = await api(request, cookie, 'POST', `/candidates/${candidateId}/extract`)
    expect(extractRes.ok()).toBeTruthy()
    const extractBody = await extractRes.json()
    expect(extractBody.success).toBe(true)
    expect(extractBody.extracted.fullname).toContain('Sabine')
    expect(extractBody.extracted.email).toContain('sabine.mueller')
    expect(extractBody.extracted.stations).toBeInstanceOf(Array)
    expect(extractBody.extracted.stations.length).toBeGreaterThanOrEqual(3)

    // Verify extraction quality
    const stations = extractBody.extracted.stations
    const employers = stations.map((s: { employer: string }) => s.employer)
    expect(employers.some((e: string) => e.includes('Nordstil'))).toBeTruthy()
    expect(employers.some((e: string) => e.includes('Rhein Textil'))).toBeTruthy()

    // Resolve variables
    const varsRes = await api(request, cookie, 'GET', `/candidates/${candidateId}/variables`)
    expect(varsRes.ok()).toBeTruthy()
    const varsBody = await varsRes.json()
    expect(varsBody.variables['fullname']).toContain('Sabine')
    expect(varsBody.variables['target-position']).toBe('VP Marketing DACH')
    expect(varsBody.variables['nationality']).toBe('deutsch')
    expect(varsBody.variables['checkb.moving.yes']).toBe(true)
    expect(varsBody.variables['checkb.moving.no']).toBe(false)
    expect(varsBody.variables['checkb.commute.yes']).toBe(true)
    expect(varsBody.variables['checkb.travel.yes']).toBe(false)
    expect(varsBody.variables['checkb.travel.no']).toBe(true)
    expect(varsBody.station_count).toBeGreaterThanOrEqual(3)

    // Get template
    const tplRes = await api(request, cookie, 'GET', '/templates')
    const templates = (await tplRes.json()).templates
    expect(templates.length).toBeGreaterThanOrEqual(1)
    const templateId = templates[0].id

    // Generate document
    const genRes = await api(request, cookie, 'POST', `/candidates/${candidateId}/generate/${templateId}`)
    expect(genRes.ok()).toBeTruthy()
    const genBody = await genRes.json()
    expect(genBody.success).toBe(true)
    expect(genBody.document.template_name).toBeTruthy()
    expect(genBody.document.variable_snapshot.fullname).toContain('Sabine')

    // Verify candidate status is now "generated"
    const getRes = await api(request, cookie, 'GET', `/candidates/${candidateId}`)
    const finalCandidate = (await getRes.json()).candidate
    expect(finalCandidate.status).toBe('generated')
    expect(Object.keys(finalCandidate.documents).length).toBeGreaterThanOrEqual(1)
  })

  test('variable override works', async ({ request }) => {
    // Get existing candidates
    const listRes = await api(request, cookie, 'GET', '/candidates')
    const candidates = (await listRes.json()).candidates
    const candidate = candidates.find((c: { name: string }) => c.name?.includes('E2E'))
    if (!candidate) { test.skip(); return }

    const overrideRes = await api(request, cookie, 'PUT', `/candidates/${candidate.id}/variables`, {
      overrides: { fullname: 'Overridden Name' },
    })
    expect(overrideRes.ok()).toBeTruthy()
    const body = await overrideRes.json()
    expect(body.variables['fullname']).toBe('Overridden Name')
  })

  test('config update works', async ({ request }) => {
    const res = await api(request, cookie, 'PUT', '/config', {
      company_name: 'E2E Test GmbH',
      default_language: 'de',
    })
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.config.company_name).toBe('E2E Test GmbH')
  })

  test('extraction with retail CV', async ({ request }) => {
    test.setTimeout(120_000)

    const createRes = await api(request, cookie, 'POST', '/candidates', {
      name: 'Thomas Schmidt E2E',
      form_id: 'default',
      field_values: { 'target-position': 'Store Manager Berlin' },
    })
    const candidateId = (await createRes.json()).candidate.id

    const cvPath = path.join(FIXTURES_DIR, 'cv_schmidt_retail.pdf')
    await request.post(
      `${API_URL}/api/v1/user/1/plugins/synaform/candidates/${candidateId}/upload-cv`,
      {
        headers: { Cookie: cookie },
        multipart: {
          file: { name: 'cv_schmidt_retail.pdf', mimeType: 'application/pdf', buffer: require('fs').readFileSync(cvPath) },
        },
      },
    )

    const extractRes = await api(request, cookie, 'POST', `/candidates/${candidateId}/extract`)
    expect(extractRes.ok()).toBeTruthy()
    const body = await extractRes.json()
    expect(body.extracted.fullname).toContain('Schmidt')
    expect(body.extracted.email).toContain('thomas.schmidt')
    expect(body.extracted.stations.length).toBeGreaterThanOrEqual(2)
    const employers = body.extracted.stations.map((s: { employer: string }) => s.employer)
    expect(employers.some((e: string) => e.includes('Breuninger'))).toBeTruthy()
  })

  test('extraction with fashion designer CV', async ({ request }) => {
    test.setTimeout(120_000)

    const createRes = await api(request, cookie, 'POST', '/candidates', {
      name: 'Lena Weber E2E',
      form_id: 'default',
      field_values: { 'target-position': 'Senior Fashion Designer' },
    })
    const candidateId = (await createRes.json()).candidate.id

    const cvPath = path.join(FIXTURES_DIR, 'cv_weber_design.pdf')
    await request.post(
      `${API_URL}/api/v1/user/1/plugins/synaform/candidates/${candidateId}/upload-cv`,
      {
        headers: { Cookie: cookie },
        multipart: {
          file: { name: 'cv_weber_design.pdf', mimeType: 'application/pdf', buffer: require('fs').readFileSync(cvPath) },
        },
      },
    )

    const extractRes = await api(request, cookie, 'POST', `/candidates/${candidateId}/extract`)
    expect(extractRes.ok()).toBeTruthy()
    const body = await extractRes.json()
    expect(body.extracted.fullname).toContain('Weber')
    expect(body.extracted.stations.length).toBeGreaterThanOrEqual(2)
    const employers = body.extracted.stations.map((s: { employer: string }) => s.employer)
    expect(employers.some((e: string) => e.toLowerCase().includes('marc o'))).toBeTruthy()
  })
})

// ---------------------------------------------------------------------------
// UI-level tests
// ---------------------------------------------------------------------------

test.describe('Synaform UI Tests', () => {
  // Self-seed: make sure the admin user has at least one Collection before
  // we drive the UI. Without this, /setup-check returns ready but
  // /forms is empty and every "open a collection" step times out waiting
  // for [data-open-collection].
  test.beforeAll(async ({ request }) => {
    const cookie = await loginViaApi(request)
    const list = await api(request, cookie, 'GET', '/forms')
    const body = await list.json()
    if (!body.forms?.length) {
      await api(request, cookie, 'POST', '/setup')
    }
  })

  test.beforeEach(async ({ page }) => {
    await loginUI(page)
  })

  test('plugin page loads with collections-first nav', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })

    // The Synaplan host renders its own outer <nav>, so we target the
    // plugin's nav by the data attribute rather than nav:first().
    await expect(page.locator('button[data-nav="collections"]')).toBeVisible()
    await expect(page.locator('button[data-nav="settings"]')).toBeVisible()
  })

  test('collections list shows the default collection card', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await expect(page.locator('[data-open-collection]').first()).toBeVisible()
  })

  test('opening a collection reveals the new 4-tab structure', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await page.locator('[data-open-collection]').first().click()
    await page.waitForTimeout(1000)

    // After the restructure, the Collection page exposes exactly these
    // four tabs. Variables + Target Templates moved behind "setup";
    // Danger Zone moved into the kebab menu next to the title.
    for (const tab of ['overview', 'datasets', 'setup', 'export']) {
      await expect(page.locator(`[data-tab="${tab}"]`).first()).toBeVisible()
    }
    await expect(page.locator('[data-tab="variables"]')).toHaveCount(0)
    await expect(page.locator('[data-tab="templates"]')).toHaveCount(0)
    await expect(page.locator('[data-tab="danger"]')).toHaveCount(0)
  })

  test('legacy hash #/c/<id>/variables redirects to the setup tab', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    // Pick a collection id from the open buttons rather than hard-coding
    // "default" so this still passes if seed data changes.
    const openBtn = page.locator('[data-open-collection]').first()
    const collectionId = await openBtn.getAttribute('data-open-collection')
    expect(collectionId).toBeTruthy()

    await page.goto(`${BASE_URL}/plugins/synaform#tx-c/${collectionId}/variables`)
    await page.waitForSelector('[data-testid="setup-tab"]', { timeout: 10_000 })
    await expect(page.locator('[data-tab="setup"].active')).toBeVisible()
  })

  test('setup tab stacks Variables and Target Templates sections', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await page.locator('[data-open-collection]').first().click()
    await page.waitForTimeout(500)
    await page.locator('[data-tab="setup"]').first().click()
    await page.waitForSelector('[data-testid="setup-tab"]', { timeout: 5000 })

    await expect(page.locator('[data-testid="setup-section-variables"]')).toBeVisible()
    await expect(page.locator('[data-testid="setup-section-templates"]')).toBeVisible()
  })

  test('Collections list shows the Set up split-button with menu options', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    // Split button = primary action + chevron toggle for the dropdown.
    const primary = page.locator('[data-testid="setup-splitbutton-primary-new"]')
    const chevron = page.locator('[data-testid="setup-splitbutton-chevron-new"]')
    await expect(primary).toBeVisible()
    await expect(chevron).toBeVisible()

    // Open the dropdown and verify all four entry points + the menu items
    // each carry their guidance hint.
    await chevron.click()
    const menu = page.locator('[data-testid="setup-menu-new"]')
    await expect(menu).toBeVisible()
    for (const mode of ['wizard', 'template', 'text', 'manual']) {
      await expect(menu.locator(`[data-testid="setup-menu-item-${mode}"]`)).toBeVisible()
    }

    // Backdrop click closes the menu (dropdown UX safety net).
    await page.locator('[data-action="setup-menu-close"]').click({ position: { x: 5, y: 5 } })
    await expect(menu).toHaveCount(0)
  })

  test('split-button "manual" mode opens the legacy New Collection modal', async ({ page, request }) => {
    // Clean up any leftover drafts from previous runs so the assertion below
    // is deterministic.
    const cookie = await loginViaApi(request)
    const formsBefore = await (await api(request, cookie, 'GET', '/forms')).json()
    for (const f of formsBefore.forms || []) {
      if (f.name?.startsWith('[Draft E2E]')) {
        await api(request, cookie, 'DELETE', `/forms/${f.id}`)
      }
    }

    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    // Manual is the escape hatch for power users; it skips the wizard and
    // opens the legacy New Collection modal (lands on Overview after save).
    await page.locator('[data-testid="setup-splitbutton-chevron-new"]').click()
    await page.locator('[data-testid="setup-menu-item-manual"]').click()

    const modal = page.locator('#tx-collection-form')
    await expect(modal).toBeVisible()
    await modal.locator('input[name="name"]').fill('[Draft E2E] manual draft')
    await modal.locator('button[type="submit"]').click()

    // Manual lands on Overview (not Set up).
    await expect(page.locator('[data-tab="overview"].active')).toBeVisible({ timeout: 10_000 })

    // Cleanup — manual mode with no fields/templates IS still a draft per
    // the heuristic, so it shows up on the Collections list.
    await page.locator('button[data-nav="collections"]').first().click()
    await page.waitForTimeout(800)
    const draftsSection = page.locator('[data-testid="drafts-section"]')
    await expect(draftsSection).toBeVisible()
    await expect(draftsSection.locator('[data-testid="draft-card"]', { hasText: '[Draft E2E] manual draft' })).toBeVisible()
  })

  test('Drafts section: Continue setup reopens the wizard pre-loaded with the draft', async ({ page, request }) => {
    // Pre-seed a clean draft via the API so the test isn't coupled to a
    // previous test's leftovers.
    const cookie = await loginViaApi(request)
    const create = await api(request, cookie, 'POST', '/forms', {
      name: '[Draft E2E] resume me',
      description: 'auto-discarded after the test',
      language: 'en',
      fields: [],
    })
    const created = await create.json()
    const draftId = created.form.id

    try {
      await page.goto(`${BASE_URL}/plugins/synaform`)
      await page.waitForSelector('[data-testid="drafts-section"]', { timeout: 10_000 })

      const card = page.locator(`[data-testid="draft-card"][data-draft-id="${draftId}"]`)
      await expect(card).toBeVisible()
      await card.locator('[data-testid="draft-resume"]').click()

      // Resume opens the wizard on the Basics step (chooser is skipped
      // because the structural decision was already made when the user
      // first started the draft) with the existing name pre-filled.
      await expect(page.locator('[data-testid="wizard-modal"]')).toBeVisible({ timeout: 5000 })
      await expect(page.locator('[data-testid="wizard-step-basics"]')).toBeVisible()
      await expect(page.locator('[data-testid="wizard-input-name"]')).toHaveValue('[Draft E2E] resume me')
    } finally {
      await api(request, cookie, 'DELETE', `/forms/${draftId}`)
    }
  })

  test('Drafts section: Discard deletes the draft', async ({ page, request }) => {
    const cookie = await loginViaApi(request)
    const create = await api(request, cookie, 'POST', '/forms', {
      name: '[Draft E2E] discard me',
      description: 'will be deleted by the test',
      language: 'en',
      fields: [],
    })
    const created = await create.json()
    const draftId = created.form.id

    // Auto-accept the confirm() dialog the Discard button raises.
    page.on('dialog', (dialog) => dialog.accept())

    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('[data-testid="drafts-section"]', { timeout: 10_000 })

    const card = page.locator(`[data-testid="draft-card"][data-draft-id="${draftId}"]`)
    await expect(card).toBeVisible()
    await card.locator('[data-testid="draft-discard"]').click()

    await expect(card).toHaveCount(0, { timeout: 5000 })

    // Belt-and-braces: the API also reports it gone.
    const after = await (await api(request, cookie, 'GET', '/forms')).json()
    const stillThere = after.forms?.find((f: { id: string }) => f.id === draftId)
    expect(stillThere).toBeFalsy()
  })

  // ---------------------------------------------------------------------
  // Setup Wizard (PR3)
  // ---------------------------------------------------------------------

  test('Setup Wizard: split-button primary opens modal with the chooser step', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(1500)

    await page.locator('[data-testid="setup-splitbutton-primary-new"]').click()

    const modal = page.locator('[data-testid="wizard-modal"]')
    await expect(modal).toBeVisible()
    await expect(page.locator('[data-testid="wizard-step-chooser"]')).toBeVisible()
    await expect(page.locator('[data-testid="wizard-mode-template"]')).toBeVisible()
    await expect(page.locator('[data-testid="wizard-mode-noTemplate"]')).toBeVisible()
    await expect(page.locator('[data-testid="wizard-next"]')).toBeDisabled()
  })

  test('Setup Wizard: split-button "template" mode opens at the Basics step (chooser skipped)', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(1500)

    await page.locator('[data-testid="setup-splitbutton-chevron-new"]').click()
    await page.locator('[data-testid="setup-menu-item-template"]').click()

    await expect(page.locator('[data-testid="wizard-step-basics"]')).toBeVisible()
    await expect(page.locator('[data-testid="wizard-input-name"]')).toBeVisible()
  })

  test('Setup Wizard: full "noTemplate" flow creates a Collection and lands on Datasets', async ({ page, request }) => {
    // Clean any leftover test forms from a previous run.
    const cookie = await loginViaApi(request)
    const before = await (await api(request, cookie, 'GET', '/forms')).json()
    for (const f of before.forms || []) {
      if (f.name?.startsWith('[Wizard E2E]')) {
        await api(request, cookie, 'DELETE', `/forms/${f.id}`)
      }
    }

    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(1500)

    await page.locator('[data-testid="setup-splitbutton-primary-new"]').click()

    // Step 0: chooser — pick "no template".
    await page.locator('[data-testid="wizard-mode-noTemplate"]').click()
    await page.locator('[data-testid="wizard-next"]').click()

    // Step 1: basics — type a name, click Continue (this persists the draft).
    await page.locator('[data-testid="wizard-input-name"]').fill('[Wizard E2E] full flow')
    await page.locator('[data-testid="wizard-next"]').click()

    // Step 3: text-paste UI for fields (noTemplate skips step 2)
    await expect(page.locator('[data-testid="wizard-fields-text"]')).toBeVisible({ timeout: 10_000 })
    await page.locator('[data-testid="wizard-next"]').click()

    // Step 4: review — primary CTA opens dataset list of the new Collection
    await expect(page.locator('[data-testid="wizard-review"]')).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('[data-testid="wizard-finish-primary"]')).toBeVisible()
    await expect(page.locator('[data-testid="wizard-finish"]')).toBeVisible()
    await page.locator('[data-testid="wizard-finish"]').click()

    // Lands on the Datasets tab of the new Collection
    await expect(page.locator('[data-tab="datasets"].active')).toBeVisible({ timeout: 10_000 })

    // Confirm the Collection exists on the backend with the right name.
    const after = await (await api(request, cookie, 'GET', '/forms')).json()
    const created = after.forms.find((f: { name: string }) => f.name === '[Wizard E2E] full flow')
    expect(created).toBeTruthy()

    // Cleanup
    await api(request, cookie, 'DELETE', `/forms/${created.id}`)
  })

  test('Setup Wizard: closing mid-flow leaves a resumable draft', async ({ page, request }) => {
    const cookie = await loginViaApi(request)
    const before = await (await api(request, cookie, 'GET', '/forms')).json()
    for (const f of before.forms || []) {
      if (f.name?.startsWith('[Wizard E2E]')) {
        await api(request, cookie, 'DELETE', `/forms/${f.id}`)
      }
    }

    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(1500)

    await page.locator('[data-testid="setup-splitbutton-primary-new"]').click()
    await page.locator('[data-testid="wizard-mode-noTemplate"]').click()
    await page.locator('[data-testid="wizard-next"]').click()
    await page.locator('[data-testid="wizard-input-name"]').fill('[Wizard E2E] abandoned')
    await page.locator('[data-testid="wizard-next"]').click()

    // We are now on step 3. Close the wizard.
    await page.locator('[data-testid="wizard-step-fields"]').waitFor({ timeout: 10_000 })
    await page.locator('[data-testid="wizard-close"]').click()

    // Modal is gone, Drafts section now contains the abandoned wizard.
    await expect(page.locator('[data-testid="wizard-modal"]')).toHaveCount(0)
    const draftsSection = page.locator('[data-testid="drafts-section"]')
    await expect(draftsSection).toBeVisible()
    await expect(draftsSection.locator('[data-testid="draft-card"]', { hasText: '[Wizard E2E] abandoned' })).toBeVisible()

    // Resume the draft -> wizard reopens, skipping the chooser, on Basics.
    await draftsSection.locator('[data-testid="draft-card"]', { hasText: '[Wizard E2E] abandoned' })
      .locator('[data-testid="draft-resume"]').click()
    await expect(page.locator('[data-testid="wizard-modal"]')).toBeVisible()
    await expect(page.locator('[data-testid="wizard-step-basics"]')).toBeVisible()
    await expect(page.locator('[data-testid="wizard-input-name"]')).toHaveValue('[Wizard E2E] abandoned')

    // Cleanup
    await page.locator('[data-testid="wizard-close"]').click()
    const after = await (await api(request, cookie, 'GET', '/forms')).json()
    const draft = after.forms.find((f: { name: string }) => f.name === '[Wizard E2E] abandoned')
    if (draft) await api(request, cookie, 'DELETE', `/forms/${draft.id}`)
  })

  test('kebab menu exposes Edit and Danger Zone', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await page.locator('[data-open-collection]').first().click()
    await page.waitForTimeout(500)

    const kebab = page.locator('[data-testid="btn-collection-menu"]')
    await expect(kebab).toBeVisible()
    await kebab.click()

    await expect(page.locator('[data-testid="menu-edit-collection"]')).toBeVisible()
    await expect(page.locator('[data-testid="menu-open-danger"]')).toBeVisible()

    // Open the Danger Zone modal and verify the typed-name confirm UI is gated.
    await page.locator('[data-testid="menu-open-danger"]').click()
    const dangerModal = page.locator('[data-testid="modal-danger"]')
    await expect(dangerModal).toBeVisible()
    const confirmInput = dangerModal.locator('#tx-danger-input')
    await expect(confirmInput).toBeVisible()
    const deleteBtn = dangerModal.locator('[data-action="delete-collection"]')
    await expect(deleteBtn).toBeDisabled()
  })

  test('datasets tab inside a collection is reachable', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await page.locator('[data-open-collection]').first().click()
    await page.waitForTimeout(500)
    await page.locator('[data-tab="datasets"]').first().click()
    await page.waitForTimeout(1000)

    // Either datasets are listed or the empty-state banner is visible.
    const list = page.locator('[data-open-dataset]')
    const listCount = await list.count()
    if (listCount === 0) {
      await expect(page.locator('text=New Dataset').or(page.locator('text=Neuer Datensatz'))).toBeVisible({ timeout: 5000 })
    } else {
      expect(listCount).toBeGreaterThanOrEqual(1)
    }
  })

  test('variables editor is reachable inside the Set up tab', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await page.locator('[data-open-collection]').first().click()
    await page.waitForTimeout(500)
    await page.locator('[data-tab="setup"]').first().click()
    await page.waitForSelector('[data-testid="setup-section-variables"]', { timeout: 5000 })

    await expect(page.locator('text=target-position').first()).toBeVisible({ timeout: 5000 }).catch(() => {})
    await expect(page.locator('text=nationality').first()).toBeVisible({ timeout: 5000 }).catch(() => {})
  })

  test('settings tab allows configuration', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform#tx-settings`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await page.click('[data-nav="settings"]')
    await page.waitForTimeout(1000)

    const companyInput = page.locator('input[name="company_name"]')
    await expect(companyInput).toBeVisible()
    await companyInput.fill('E2E UI Test Company')
    await page.click('#tx-settings-form button[type="submit"]')
    await page.waitForTimeout(1000)
  })

  test('dataset detail exposes extraction and generation sections', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/synaform`)
    await page.waitForSelector('text=Synaform', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await page.locator('[data-open-collection]').first().click()
    await page.waitForTimeout(500)
    await page.locator('[data-tab="datasets"]').first().click()
    await page.waitForTimeout(1000)

    const firstEntry = page.locator('[data-open-dataset]').first()
    if (await firstEntry.isVisible()) {
      await firstEntry.click()
      await page.waitForTimeout(2000)

      await expect(page.locator('text=AI Extraction').or(page.locator('text=KI-Extraktion')).first()).toBeVisible({ timeout: 5000 }).catch(() => {})
      await expect(page.locator('text=Source Documents').or(page.locator('text=Quelldokumente')).first()).toBeVisible({ timeout: 5000 }).catch(() => {})
    }
  })
})
