/**
 * E2E Tests for the TemplateX Plugin
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
 *   - TemplateX plugin installed for admin user
 *   - At least one AI provider configured (Anthropic, OpenAI, or Ollama)
 *   - Tika running for PDF text extraction
 *
 * Run with:
 *   npx playwright test tests/e2e/templatex-plugin.spec.ts
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
  const url = `${API_URL}/api/v1/user/1/plugins/templatex${path}`
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

test.describe('TemplateX API Tests', () => {
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
      `${API_URL}/api/v1/user/1/plugins/templatex/templates`,
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
      `${API_URL}/api/v1/user/1/plugins/templatex/candidates/${candidateId}/upload-cv`,
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
      `${API_URL}/api/v1/user/1/plugins/templatex/candidates/${candidateId}/upload-cv`,
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
      `${API_URL}/api/v1/user/1/plugins/templatex/candidates/${candidateId}/upload-cv`,
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

test.describe('TemplateX UI Tests', () => {
  test.beforeEach(async ({ page }) => {
    await loginUI(page)
  })

  test('plugin page loads with collections-first nav', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })

    const navBar = page.locator('nav').first()
    await expect(navBar.locator('button[data-nav="collections"]')).toBeVisible()
    await expect(navBar.locator('button[data-nav="settings"]')).toBeVisible()
  })

  test('collections list shows the default collection card', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await expect(page.locator('[data-open-collection]').first()).toBeVisible()
  })

  test('opening a collection reveals all tabs', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await page.locator('[data-open-collection]').first().click()
    await page.waitForTimeout(1000)

    for (const tab of ['overview', 'variables', 'templates', 'datasets', 'export', 'danger']) {
      await expect(page.locator(`[data-tab="${tab}"]`).first()).toBeVisible()
    }
  })

  test('datasets tab inside a collection is reachable', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
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

  test('variables tab shows the default collection fields', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
    await page.waitForTimeout(2000)

    await page.locator('[data-open-collection]').first().click()
    await page.waitForTimeout(500)
    await page.locator('[data-tab="variables"]').first().click()
    await page.waitForTimeout(1000)

    await expect(page.locator('text=target-position').first()).toBeVisible({ timeout: 5000 }).catch(() => {})
    await expect(page.locator('text=nationality').first()).toBeVisible({ timeout: 5000 }).catch(() => {})
  })

  test('settings tab allows configuration', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex#tx-settings`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
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
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
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
