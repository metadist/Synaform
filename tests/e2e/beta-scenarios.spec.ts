/**
 * Beta Scenarios E2E Tests
 *
 * Comprehensive tests covering the full TemplateX workflow:
 *   - Record CRUD (create, read, update, delete)
 *   - Document uploads (PDF CVs + JPG scans)
 *   - AI extraction and parse-documents
 *   - Variable resolution and overrides
 *   - Edge cases (bad files, missing data, non-existent records)
 *   - Pagination (30+ records)
 *   - UI: search, sort, navigation
 *
 * Prerequisites:
 *   - Synaplan running with backend on localhost:8000
 *   - TemplateX plugin installed for admin user (userId=1)
 *   - AI provider configured, Tika running
 *
 * Run with:
 *   npx playwright test tests/e2e/beta-scenarios.spec.ts
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import path from 'path'
import fs from 'fs'

const API_URL = process.env.SYNAPLAN_API_URL || 'http://localhost:8000'
const BASE_URL = process.env.BASE_URL || 'http://localhost:5173'
const ADMIN_EMAIL = process.env.SYNAPLAN_ADMIN_EMAIL || 'admin@synaplan.com'
const ADMIN_PASS = process.env.SYNAPLAN_ADMIN_PASS || 'admin123'
const FIXTURES_DIR = path.resolve(__dirname, '../fixtures')
const PLUGIN_BASE = `${API_URL}/api/v1/user/1/plugins/templatex`

async function login(request: APIRequestContext): Promise<string> {
  const res = await request.post(`${API_URL}/api/v1/auth/login`, {
    data: { email: ADMIN_EMAIL, password: ADMIN_PASS },
  })
  expect(res.ok(), `Login failed: ${res.status()}`).toBeTruthy()
  const setCookie = res.headers()['set-cookie'] || ''
  return (Array.isArray(setCookie) ? setCookie : [setCookie])
    .map(h => { const m = h.match(/^([^=]+)=([^;]+)/); return m ? `${m[1]}=${m[2]}` : null })
    .filter(Boolean)
    .join('; ')
}

async function api(
  request: APIRequestContext,
  cookie: string,
  method: 'GET' | 'POST' | 'PUT' | 'DELETE',
  endpoint: string,
  data?: unknown,
) {
  const url = `${PLUGIN_BASE}${endpoint}`
  const opts: Record<string, unknown> = { headers: { Cookie: cookie } }
  if (data !== undefined) opts.data = data
  switch (method) {
    case 'GET': return request.get(url, opts)
    case 'POST': return request.post(url, opts)
    case 'PUT': return request.put(url, opts)
    case 'DELETE': return request.delete(url, opts)
  }
}

async function uploadFile(
  request: APIRequestContext,
  cookie: string,
  endpoint: string,
  filePath: string,
  mimeType: string,
) {
  return request.post(`${PLUGIN_BASE}${endpoint}`, {
    headers: { Cookie: cookie },
    multipart: {
      file: {
        name: path.basename(filePath),
        mimeType,
        buffer: fs.readFileSync(filePath),
      },
    },
  })
}

// ---------------------------------------------------------------------------
// @api — Record CRUD
// ---------------------------------------------------------------------------

test.describe('@api Record CRUD', () => {
  let cookie: string
  const createdIds: string[] = []

  test.beforeAll(async ({ request }) => {
    cookie = await login(request)
  })

  test('create 6 named records', async ({ request }) => {
    const names = [
      'Maria Schmidt - Retail Manager',
      'Thomas Weber - Creative Director',
      'Lisa Mueller - Fashion Buyer',
      'Dr. Hans Berger - Medical Consultant',
      'Sophie Klein - Marketing Lead',
      'Andreas Hoffmann - IT Architect',
    ]
    for (const name of names) {
      const res = await api(request, cookie, 'POST', '/candidates', {
        form_id: 'default',
        field_values: {},
        name,
      })
      expect(res.ok()).toBeTruthy()
      const body = await res.json()
      expect(body.success).toBe(true)
      expect(body.candidate.name).toBe(name)
      expect(body.candidate.status).toBe('draft')
      createdIds.push(body.candidate.id)
    }
    expect(createdIds).toHaveLength(6)
  })

  test('list records returns all created entries', async ({ request }) => {
    const res = await api(request, cookie, 'GET', '/candidates')
    const body = await res.json()
    expect(body.success).toBe(true)
    expect(body.candidates.length).toBeGreaterThanOrEqual(6)
    for (const id of createdIds) {
      expect(body.candidates.some((c: { id: string }) => c.id === id)).toBeTruthy()
    }
  })

  test('update record form data merges fields', async ({ request }) => {
    const id = createdIds[0]
    const res = await api(request, cookie, 'PUT', `/candidates/${id}`, {
      field_values: {
        'target-position': 'VP of Retail Operations',
        'nationality': 'German',
        'maritalstatus': 'married',
      },
    })
    expect(res.ok()).toBeTruthy()

    const getRes = await api(request, cookie, 'GET', `/candidates/${id}`)
    const fv = (await getRes.json()).candidate.field_values
    expect(fv['target-position']).toBe('VP of Retail Operations')
    expect(fv['nationality']).toBe('German')
    expect(fv['maritalstatus']).toBe('married')
  })

  test('delete record decrements count', async ({ request }) => {
    const listBefore = await api(request, cookie, 'GET', '/candidates')
    const countBefore = (await listBefore.json()).candidates.length

    const lastId = createdIds.pop()!
    const delRes = await api(request, cookie, 'DELETE', `/candidates/${lastId}`)
    expect(delRes.ok()).toBeTruthy()

    const listAfter = await api(request, cookie, 'GET', '/candidates')
    const countAfter = (await listAfter.json()).candidates.length
    expect(countAfter).toBe(countBefore - 1)
  })
})

// ---------------------------------------------------------------------------
// @api — Document uploads
// ---------------------------------------------------------------------------

test.describe('@api Document uploads', () => {
  let cookie: string
  let recordId: string

  test.beforeAll(async ({ request }) => {
    cookie = await login(request)
    const res = await api(request, cookie, 'POST', '/candidates', {
      form_id: 'default',
      field_values: { 'target-position': 'Upload Test' },
      name: 'Upload Test Record',
    })
    recordId = (await res.json()).candidate.id
  })

  test('upload PDF as CV succeeds', async ({ request }) => {
    const cvPath = path.join(FIXTURES_DIR, 'cv_mueller_fashion.pdf')
    if (!fs.existsSync(cvPath)) { test.skip(); return }
    const res = await uploadFile(request, cookie, `/candidates/${recordId}/upload-cv`, cvPath, 'application/pdf')
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.file.filename).toContain('.pdf')
  })

  test('upload JPG as additional doc succeeds', async ({ request }) => {
    const imgPath = path.join(FIXTURES_DIR, 'test_scan_placeholder.jpg')
    if (!fs.existsSync(imgPath)) { test.skip(); return }
    const res = await uploadFile(request, cookie, `/candidates/${recordId}/upload-doc`, imgPath, 'image/jpeg')
    expect(res.ok()).toBeTruthy()
  })

  test('upload non-PDF as CV is rejected', async ({ request }) => {
    const tmpFile = path.join(FIXTURES_DIR, '__test_bad.txt')
    fs.writeFileSync(tmpFile, 'not a real pdf')
    try {
      const res = await uploadFile(request, cookie, `/candidates/${recordId}/upload-cv`, tmpFile, 'text/plain')
      const body = await res.json()
      expect(body.success).toBe(false)
      expect(body.error).toContain('PDF')
    } finally {
      fs.unlinkSync(tmpFile)
    }
  })
})

// ---------------------------------------------------------------------------
// @api — AI extraction + parse-documents
// ---------------------------------------------------------------------------

test.describe('@api AI extraction', () => {
  let cookie: string
  let recordId: string

  test.beforeAll(async ({ request }) => {
    cookie = await login(request)
    const res = await api(request, cookie, 'POST', '/candidates', {
      form_id: 'default',
      field_values: { 'target-position': 'AI Test Position' },
      name: 'AI Extraction Test',
    })
    recordId = (await res.json()).candidate.id

    const cvPath = path.join(FIXTURES_DIR, 'cv_schmidt_retail.pdf')
    if (fs.existsSync(cvPath)) {
      await uploadFile(request, cookie, `/candidates/${recordId}/upload-cv`, cvPath, 'application/pdf')
    }
  })

  test('extract without documents returns error', async ({ request }) => {
    const emptyRes = await api(request, cookie, 'POST', '/candidates', {
      form_id: 'default', field_values: {}, name: 'No Docs Record',
    })
    const emptyId = (await emptyRes.json()).candidate.id
    const res = await api(request, cookie, 'POST', `/candidates/${emptyId}/extract`)
    const body = await res.json()
    expect(body.success).toBe(false)
    expect(body.error).toBeTruthy()
  })

  test('extract returns structured data from CV', async ({ request }) => {
    test.setTimeout(120_000)
    const cvPath = path.join(FIXTURES_DIR, 'cv_schmidt_retail.pdf')
    if (!fs.existsSync(cvPath)) { test.skip(); return }

    const res = await api(request, cookie, 'POST', `/candidates/${recordId}/extract`)
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.success).toBe(true)
    expect(body.extracted).toBeTruthy()
    expect(body.extracted.fullname).toBeTruthy()
    expect(body.extracted.stations).toBeInstanceOf(Array)
    expect(body.extracted.stations.length).toBeGreaterThanOrEqual(1)
  })

  test('parse-documents returns suggestions', async ({ request }) => {
    test.setTimeout(120_000)
    const cvPath = path.join(FIXTURES_DIR, 'cv_schmidt_retail.pdf')
    if (!fs.existsSync(cvPath)) { test.skip(); return }

    const res = await api(request, cookie, 'POST', `/candidates/${recordId}/parse-documents`)
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.success).toBe(true)
    expect(body.documents_parsed).toBeGreaterThanOrEqual(1)
    expect(body.suggestions).toBeTruthy()
  })
})

// ---------------------------------------------------------------------------
// @api — Variables and overrides
// ---------------------------------------------------------------------------

test.describe('@api Variables', () => {
  let cookie: string
  let recordId: string

  test.beforeAll(async ({ request }) => {
    cookie = await login(request)
    const list = await api(request, cookie, 'GET', '/candidates')
    const candidates = (await list.json()).candidates
    const extracted = candidates.find((c: { status: string }) =>
      c.status === 'extracted' || c.status === 'reviewed' || c.status === 'generated'
    )
    if (extracted) {
      recordId = extracted.id
    }
  })

  test('resolve variables returns data with sources', async ({ request }) => {
    if (!recordId) { test.skip(); return }
    const res = await api(request, cookie, 'GET', `/candidates/${recordId}/variables`)
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.variables).toBeTruthy()
    expect(Object.keys(body.variables).length).toBeGreaterThan(10)
    expect(body.sources).toBeTruthy()
  })

  test('override variable takes priority', async ({ request }) => {
    if (!recordId) { test.skip(); return }
    const res = await api(request, cookie, 'PUT', `/candidates/${recordId}/variables`, {
      overrides: { fullname: 'Override Test Name' },
    })
    expect(res.ok()).toBeTruthy()
    const body = await res.json()
    expect(body.variables['fullname']).toBe('Override Test Name')

    await api(request, cookie, 'PUT', `/candidates/${recordId}/variables`, {
      overrides: { fullname: null },
    })
  })
})

// ---------------------------------------------------------------------------
// @api — Edge cases
// ---------------------------------------------------------------------------

test.describe('@api Edge cases', () => {
  let cookie: string

  test.beforeAll(async ({ request }) => {
    cookie = await login(request)
  })

  test('non-existent record returns 404', async ({ request }) => {
    const res = await api(request, cookie, 'GET', '/candidates/entry_does_not_exist')
    const body = await res.json()
    expect(body.success).toBe(false)
    expect(body.error).toContain('not found')
  })

  test('non-existent template returns 404 on generate', async ({ request }) => {
    const createRes = await api(request, cookie, 'POST', '/candidates', {
      form_id: 'default', field_values: {}, name: 'Edge Case Record',
    })
    const id = (await createRes.json()).candidate.id
    const res = await api(request, cookie, 'POST', `/candidates/${id}/generate/tpl_nonexistent`)
    const body = await res.json()
    expect(body.success).toBe(false)
  })

  test('template placeholder detection works', async ({ request }) => {
    const tplRes = await api(request, cookie, 'GET', '/templates')
    const templates = (await tplRes.json()).templates
    if (templates.length === 0) { test.skip(); return }
    const tplId = templates[0].id

    const phRes = await api(request, cookie, 'GET', `/templates/${tplId}/placeholders`)
    expect(phRes.ok()).toBeTruthy()
    const body = await phRes.json()
    expect(body.placeholders).toBeInstanceOf(Array)
    expect(body.placeholders.length).toBeGreaterThan(0)
    expect(body.placeholders[0]).toHaveProperty('key')
    expect(body.placeholders[0]).toHaveProperty('type')
  })
})

// ---------------------------------------------------------------------------
// @api — Pagination (create 30+ records)
// ---------------------------------------------------------------------------

test.describe('@api Pagination', () => {
  let cookie: string

  test.beforeAll(async ({ request }) => {
    cookie = await login(request)
  })

  test('create 35 test records for pagination', async ({ request }) => {
    test.setTimeout(60_000)
    for (let i = 1; i <= 35; i++) {
      const name = `Pagination Test ${String(i).padStart(2, '0')}`
      const res = await api(request, cookie, 'POST', '/candidates', {
        form_id: 'default', field_values: {}, name,
      })
      expect(res.ok()).toBeTruthy()
    }

    const list = await api(request, cookie, 'GET', '/candidates')
    const total = (await list.json()).candidates.length
    expect(total).toBeGreaterThanOrEqual(35)
  })
})

// ---------------------------------------------------------------------------
// @ui — Navigation, search, pagination
// ---------------------------------------------------------------------------

test.describe('@ui Records view', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${BASE_URL}/login`)
    await page.fill('input[type="email"]', ADMIN_EMAIL)
    await page.fill('input[type="password"]', ADMIN_PASS)
    await page.click('button[type="submit"]')
    await page.waitForTimeout(3000)
  })

  test('plugin loads with updated navigation tabs', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
    await page.waitForTimeout(3000)

    for (const tab of ['overview', 'records', 'questionnaires', 'documents', 'settings']) {
      await expect(page.locator(`button[data-nav="${tab}"]`)).toBeVisible()
    }
  })

  test('overview shows content cards with item counts', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
    await page.waitForTimeout(3000)

    await expect(page.locator('text=Questionnaires')).toBeVisible()
    await expect(page.locator('text=Document Templates')).toBeVisible()
    await expect(page.locator('text=Records')).toBeVisible()
    await expect(page.locator('text=How it works')).toBeVisible()
  })

  test('records view shows list and search works', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
    await page.waitForTimeout(3000)

    await page.click('button[data-nav="records"]')
    await page.waitForTimeout(2000)

    const searchInput = page.locator('#tx-entries-search')
    await expect(searchInput).toBeVisible()

    await searchInput.fill('Pagination')
    await page.waitForTimeout(500)

    await expect(page.locator('text=/\\d+ \\/ \\d+ records/')).toBeVisible()
  })

  test('records view shows pagination when enough entries', async ({ page }) => {
    await page.goto(`${BASE_URL}/plugins/templatex`)
    await page.waitForSelector('text=TemplateX', { timeout: 15_000 })
    await page.waitForTimeout(3000)

    await page.click('button[data-nav="records"]')
    await page.waitForTimeout(2000)

    const pageButtons = page.locator('[data-page]')
    const count = await pageButtons.count()
    expect(count).toBeGreaterThanOrEqual(2)
  })
})
