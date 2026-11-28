import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * A till wiped, reinstalled or replaced while a shift was open (owner 2026-11-28): the till added in its place shows the
 * shift left open and continues it with a manager's username and PIN, or opens a new one instead.
 */

const salt = randomBytes(16)
const grant = {
  v: 1, id: 'g1', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-esi', user_name: 'Esi Cashier', username: 'esi',
  issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
  pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync('482613', salt, 1000, 32, 'sha256').toString('base64') },
  sig: 'server-signature',
}
const catalog = {
  version: 'v1', generated_at: new Date().toISOString(),
  products: [{
    id: 'p-milo', sku: 'MILO-400', name: 'Milo 400g tin', track_stock: true, on_hand: '10.000', tax: [{ code: 'VAT', name: 'VAT', rate_bp: 1500 }],
    units: [{ id: 'pu-milo', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: ['200000900'] }],
    prices: [{ price_list_id: 'pl-retail', product_uom_id: 'pu-milo', min_qty: '1.000', amount_minor: 5000, starts_at: null, ends_at: null }],
  }],
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Esi Cashier', opening_float_minor: 0,
  expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = {
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null },
  till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' }, device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' },
  user: { id: 'u-esi', name: 'Esi Cashier' },
  price_lists: [{ id: 'pl-retail', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift: null, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(), momo_confirm: 'prompt_only',
  left_open: { id: 'shift-1', opened_by: 'Esi Cashier', opened_at: '2026-11-28T08:00:00Z', sales: 3, device: 'Front PC' },
}


test('continues a shift left open on this till by a till that was wiped, with a manager\'s PIN', async ({ page }) => {
  let taken: Record<string, unknown> | null = null
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 'staff-token', expires_at: null, user: { id: 'u-esi', name: 'Esi Cashier' }, offline_grant: grant })
      case 'GET pos/bootstrap': return json(route, 200, taken ? { ...bootstrap, shift: { ...shift, opened_by_id: 'u-esi' }, left_open: null } : bootstrap)
      case 'GET pos/catalog': return json(route, 200, catalog)
      case 'GET pos/customers': return json(route, 200, { version: 'c1', customers: [] })
      case 'POST pos/shifts/take-over': {
        const b = req.postDataJSON()
        if (b.approval.pin !== '7391') return json(route, 422, { error: 'approval_failed', message: 'Supervisor username or PIN is wrong.' })
        taken = b
        return json(route, 200, { shift })
      }
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })

  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('esi')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()

  const left = page.getByRole('form', { name: 'Shift left open on this till' })
  await expect(left).toContainText('Esi Cashier')
  await expect(left).toContainText('with 3 sales')
  await left.getByLabel("Manager's username").fill('kofi')
  await left.getByLabel("Manager's PIN").fill('0000')
  await left.getByRole('button', { name: 'Continue this shift' }).click()
  await expect(left.getByRole('alert')).toContainText('PIN is wrong')
  await left.getByLabel("Manager's PIN").fill('7391')
  await left.getByRole('button', { name: 'Continue this shift' }).click()
  await expect(page.getByLabel('Scan or search')).toBeVisible()
  expect(taken).toMatchObject({ shift_id: 'shift-1', reason: 'The till was reinstalled', approval: { username: 'kofi', pin: '7391' } })
})

test('can open a new shift instead of continuing the one left open', async ({ page }) => {
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 'staff-token', expires_at: null, user: { id: 'u-esi', name: 'Esi Cashier' }, offline_grant: grant })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, catalog)
      case 'GET pos/customers': return json(route, 200, { version: 'c1', customers: [] })
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('esi')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  await page.getByRole('button', { name: 'Open a new shift instead' }).click()
  await expect(page.getByRole('heading', { name: 'Open shift' })).toBeVisible()
})
