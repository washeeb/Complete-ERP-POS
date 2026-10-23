import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * Mobile money by payment request: the cashier sends the request to the customer's phone, the till waits while the
 * customer approves, and the sale is posted with the paid request (no typed transaction ID when the owner chose
 * "Payment request only").
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
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(), momo_confirm: 'prompt_only',
}

test('takes mobile money by a payment request the customer approves on their phone', async ({ page }) => {
  const posted: Record<string, unknown>[] = []
  const requests: Record<string, unknown>[] = []
  let checks = 0
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    const charge = (status: string) => ({ id: requests[0]?.id, status, message: null, network: 'telecel', amount_minor: 5000, reference: status === 'success' ? '777' : 'TLX', phone: '233201234567' })
    if (req.method() === 'GET' && path.startsWith('pos/momo/')) return json(route, 200, { charge: charge(++checks < 2 ? 'pending' : 'success') })
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 'staff-token', expires_at: null, user: { id: 'u-esi', name: 'Esi Cashier' }, offline_grant: grant })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, catalog)
      case 'GET pos/customers': return json(route, 200, { version: 'c1', customers: [] })
      case 'POST pos/momo': requests.push(req.postDataJSON()); return json(route, 201, { charge: charge('pending') })
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        posted.push(b)
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: 'HQ-T01A-000001', device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Esi Cashier', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [], lines: [],
          payments: [{ method: 'momo', provider: 'telecel', amount_minor: 5000, tendered_minor: 5000, reference: '777', status: 'verified' }] } })
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

  const scan = page.getByLabel('Scan or search')
  await scan.fill('200000900')
  await scan.press('Enter')
  await page.keyboard.press('F12')
  await page.getByRole('tab', { name: 'Mobile money' }).click()
  await page.getByLabel('Telecel Cash').check()
  await expect(page.getByLabel('Transaction ID')).toHaveCount(0) // requests only, while online
  await page.getByLabel('Customer’s MoMo number').fill('020 123 4567')
  await page.getByRole('button', { name: /Send payment request/ }).click()
  await expect(page.getByText(/Waiting for the customer to approve/)).toBeVisible()

  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible({ timeout: 15_000 })
  expect(requests[0]).toMatchObject({ network: 'telecel', phone: '020 123 4567', amount_minor: 5000 })
  expect(posted[0]).toMatchObject({ payments: [{ method: 'momo', provider: 'telecel', amount_minor: 5000, reference: '777', momo_charge_id: requests[0].id }] })
})
