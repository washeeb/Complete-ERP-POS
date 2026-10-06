import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * A credit sale on the till: pick the customer (their wholesale list applies), pay "On account", the server asks for a
 * supervisor (over the limit), the supervisor approves with their PIN, and the sale goes through with the approval.
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
    prices: [
      { price_list_id: 'pl-retail', product_uom_id: 'pu-milo', min_qty: '1.000', amount_minor: 5800, starts_at: null, ends_at: null },
      { price_list_id: 'pl-wholesale', product_uom_id: 'pu-milo', min_qty: '1.000', amount_minor: 5200, starts_at: null, ends_at: null },
    ],
  }],
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Esi Cashier', opening_float_minor: 0,
  expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = {
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null },
  till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' }, device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' },
  user: { id: 'u-esi', name: 'Esi Cashier' },
  price_lists: [{ id: 'pl-retail', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true },
    { id: 'pl-wholesale', code: 'WHOLESALE', name: 'Wholesale', prices_include_tax: true, is_default: false }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(),
}
const customers = { version: 'c1', customers: [{ id: 'cus-1', code: 'CUS-HQ-000001', name: 'Kofi Provisions', phone: '+233245551234', price_list_id: 'pl-wholesale',
  credit_limit_minor: 10000, credit_hold: false, balance_minor: 8000, available_minor: 2000, overdue_minor: 0 }] }

test('sells on account to a customer, with a supervisor approving the part over the limit', async ({ page }) => {
  const posted: Record<string, unknown>[] = []
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
      case 'GET pos/customers': return json(route, 200, customers)
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        posted.push(b)
        if (!b.credit_approval) return json(route, 422, { error: 'credit_needs_approval', detail: 'A supervisor must approve this credit sale: over the limit.' })
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: 'HQ-T01A-000002', device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Esi Cashier', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [], lines: [],
          payments: [{ method: 'account', provider: null, amount_minor: b.totals.total_minor, tendered_minor: b.totals.total_minor, reference: null, status: 'on_account' }] } })
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

  await page.getByRole('button', { name: 'Customer (F4)' }).click()
  await page.getByLabel('Find customer').fill('0245551')
  await page.getByRole('button', { name: /Kofi Provisions/ }).click()
  await expect(page.getByLabel('Price list')).toHaveValue('pl-wholesale') // the customer's own list
  const scan = page.getByLabel('Scan or search')
  await scan.fill('200000900')
  await scan.press('Enter')
  await scan.fill('200000900')
  await scan.press('Enter')
  await page.keyboard.press('F12')
  await page.getByRole('tab', { name: 'On account' }).click()
  await page.getByRole('button', { name: 'Add to account' }).click()

  await expect(page.getByRole('dialog', { name: 'Supervisor approval' })).toBeVisible()
  await page.getByLabel('Supervisor username').fill('kojo')
  await page.getByLabel('Supervisor PIN').fill('771204')
  await page.getByRole('button', { name: 'Approve credit sale' }).click()

  await expect(page.getByText('Receipt HQ-T01A-000002')).toBeVisible()
  expect(posted).toHaveLength(2)
  expect(posted[0]).toMatchObject({ customer_id: 'cus-1', price_list_id: 'pl-wholesale', payments: [{ method: 'account', amount_minor: 10400 }] })
  expect(posted[1].credit_approval).toEqual({ username: 'kojo', pin: '771204' })
  expect(posted[1].id).not.toBe(posted[0].id)
})
