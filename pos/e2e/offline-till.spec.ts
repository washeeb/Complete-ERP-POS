import { expect, test, type Page, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * The till end to end in a real browser (IndexedDB, WebCrypto, service code): enrol, sign in,
 * sell online, lose the server, sign in offline, sell offline, get the server back, and check the
 * queued work is sent in order with a device signature. The API is a mock that behaves like the
 * server contract in docs/api.md.
 */

const PIN = '482613'
const salt = randomBytes(16)
const grant = {
  v: 1, id: 'g1', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-esi', user_name: 'Esi Cashier', username: 'esi',
  issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
  pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync(PIN, salt, 1000, 32, 'sha256').toString('base64') },
  sig: 'server-signature',
}
const tax = [{ code: 'VAT', name: 'VAT', rate_bp: 1500 }, { code: 'NHIL', name: 'NHIL', rate_bp: 250 }, { code: 'GETFUND', name: 'GETFund levy', rate_bp: 250 }]
const catalog = {
  version: 'v1', generated_at: new Date().toISOString(),
  products: [{
    id: 'p-milo', sku: 'MILO-400', name: 'Milo 400g tin', track_stock: true, on_hand: '10.000', tax,
    units: [{ id: 'pu-milo', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: ['200000900'] }],
    prices: [{ price_list_id: 'pl-retail', product_uom_id: 'pu-milo', min_qty: '1.000', amount_minor: 5800, starts_at: null, ends_at: null }],
  }],
}
const bootstrap = (shift: unknown) => ({
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null },
  till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' },
  device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' },
  user: { id: 'u-esi', name: 'Esi Cashier' },
  price_lists: [{ id: 'pl-retail', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(),
})

type Server = { up: boolean; shift: unknown; syncBodies: { body: string; signature: string | null }[]; sales: unknown[] }

async function mockServer(page: Page, s: Server) {
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => (s.up ? r.fulfill({ status: 200, body: 'ok' }) : r.fulfill({ status: 502, body: 'Bad Gateway' })))
  await page.route('**/api/v1/**', async (route) => {
    if (!s.up) return route.fulfill({ status: 502, body: 'Bad Gateway' }) // what a proxy answers when the app server is down
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token' })
      case 'POST pos/login': return json(route, 200, { token: 'staff-token', expires_at: null, user: { id: 'u-esi', name: 'Esi Cashier' }, offline_grant: grant })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap(s.shift))
      case 'GET pos/catalog': return req.headers()['if-none-match'] === '"v1"' ? route.fulfill({ status: 304 }) : json(route, 200, catalog)
      case 'POST pos/shifts/open': {
        const b = req.postDataJSON()
        s.shift = { id: b.id, status: 'open', opened_at: new Date().toISOString(), opened_by: 'Esi Cashier', opening_float_minor: b.opening_float_minor,
          expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
        return json(route, 201, { shift: s.shift })
      }
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        s.sales.push(b)
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: `HQ-T01A-${String(b.device_seq).padStart(6, '0')}`, device_seq: b.device_seq,
          status: 'posted', occurred_at: b.occurred_at, cashier: 'Esi Cashier', currency: 'GHS', prices_include_tax: true, ...b.totals,
          paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [], lines: [], payments: [] } })
      }
      case 'POST pos/sync': {
        const body = req.postData() ?? ''
        s.syncBodies.push({ body, signature: req.headers()['x-device-signature'] ?? null })
        return json(route, 200, { results: JSON.parse(body).events.map((e: { id: string }) => ({ id: e.id, status: 'accepted' })) })
      }
      case 'POST pos/logout': return json(route, 200, { ok: true })
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
}

async function signIn(page: Page) {
  await page.getByLabel('Username').fill('esi')
  await page.getByLabel('PIN', { exact: true }).fill(PIN)
  await page.getByRole('button', { name: 'OK' }).click()
}

async function sellOneMilo(page: Page) {
  const scan = page.getByLabel('Scan or search')
  await scan.fill('200000900')
  await scan.press('Enter')
  await page.keyboard.press('F12')
  await page.getByRole('button', { name: /GH₵ 58\.00/ }).first().click() // exact cash
}

test('sells online, keeps selling with the server down, then sends the signed queue', async ({ page }) => {
  const s: Server = { up: true, shift: null, syncBodies: [], sales: [] }
  await mockServer(page, s)
  await page.goto('/')

  // Enrol and sign in online (this stores the offline grant).
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await signIn(page)
  await page.getByPlaceholder('0.00').fill('100')
  await page.getByRole('button', { name: 'Open shift' }).click()

  await sellOneMilo(page)
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
  expect(s.sales).toHaveLength(1)
  await page.getByRole('button', { name: 'New sale' }).click()

  // The server goes down.
  s.up = false
  await expect(page.getByText('Server not answering — working offline')).toBeVisible({ timeout: 20_000 })

  // Sign out and back in: the PIN unlocks the grant offline; a wrong PIN is refused.
  await page.getByRole('button', { name: 'Sign out' }).click()
  await page.getByLabel('Username').fill('esi')
  await page.getByLabel('PIN', { exact: true }).fill('111999')
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByRole('alert')).toHaveText('Wrong username or PIN.')
  await page.getByLabel('PIN', { exact: true }).fill(PIN)
  await page.getByRole('button', { name: 'OK' }).click()

  await sellOneMilo(page)
  await expect(page.getByText('Recorded on this till while offline')).toBeVisible()
  await expect(page.getByText('Receipt HQ-T01A-000002')).toBeVisible()
  await page.getByRole('button', { name: 'New sale' }).click()
  await expect(page.getByText(/1 item\(s\) saved on this till/)).toBeVisible()
  expect(s.syncBodies).toHaveLength(0)

  // The server comes back: the queue is sent, signed, and the banner clears.
  s.up = true
  await expect(page.getByText(/saved on this till/)).toBeHidden({ timeout: 30_000 })
  expect(s.syncBodies.length).toBeGreaterThan(0)
  const sent = JSON.parse(s.syncBodies[0].body)
  expect(sent.events.map((e: { type: string }) => e.type)).toEqual(['sale'])
  expect(sent.events[0].payload.device_seq).toBe(2)
  expect(sent.events[0].grant).toEqual(grant) // exactly as the server signed it
  expect(Buffer.from(s.syncBodies[0].signature ?? '', 'base64')).toHaveLength(64) // ECDSA P-256 raw r||s
})
