import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** Electronics at the till: a phone sold by its IMEI with a trade-in; a repair collected and paid. */

const grant = (pin: string) => {
  const salt = randomBytes(16)
  return { v: 1, id: 'g-efua', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-efua', user_name: 'Efua', username: 'efua',
    issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
    pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync(pin, salt, 1000, 32, 'sha256').toString('base64') }, sig: 's' }
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Efua', opening_float_minor: 0, expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = {
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null }, till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' },
  device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' }, user: { id: 'u-efua', name: 'Efua' },
  price_lists: [{ id: 'pl', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(),
}
const product = (id: string, sku: string, name: string, price: number, extra: Record<string, unknown> = {}) => ({ id, sku, name, category_id: null, track_stock: true,
  on_hand: '5.000', tax: [], units: [{ id: `u-${id}`, uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }],
  prices: [{ price_list_id: 'pl', product_uom_id: `u-${id}`, min_qty: '1', amount_minor: price, starts_at: null, ends_at: null }], ...extra })
const catalogBody = { version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], grocery: null, pharmacy: null, restaurant: null, electronics: true,
  products: [product('ph', 'TECNO', 'Tecno Spark 20', 150000, { track_serials: true, warranty_months: 12 }), product('used', 'USED', 'Used phone', 80000, { track_serials: true }),
    product('scr', 'SCR', 'Replacement screen', 20000), product('lab', 'LAB', 'Repair labour', 10000, { track_stock: false })] }
const job = { id: 'j1', number: 'JOB-HQ-000001', customer_id: null, customer_name: 'Esi', phone: null, device: 'Samsung A12', serial: null, fault: 'Will not charge', accessories: null,
  status: 'ready', status_label: 'Ready to collect', in_warranty: false, estimate_minor: null, promised_on: null, received_at: new Date().toISOString(), rma_reference: null, log: [],
  lines: [{ product_id: 'scr', product_uom_id: 'u-scr', name: 'Replacement screen', qty: '1.000', unit_price_minor: 20000 }, { product_id: 'lab', product_uom_id: 'u-lab', name: 'Repair labour', qty: '1.000', unit_price_minor: 10000 }] }

test('sells a phone by its IMEI with a used phone traded in, and collects a repair', async ({ page }) => {
  const sales: Record<string, unknown>[] = []
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 't-efua', expires_at: null, user: { id: 'u-efua', name: 'Efua' }, offline_grant: grant('482613') })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, catalogBody)
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
      case 'GET pos/repairs': return json(route, 200, { jobs: [job] })
      case 'GET pos/serials/356901010000001': return json(route, 200, { serials: [{ serial: '356901010000001', product_id: 'ph', product: 'Tecno Spark 20', status: 'sold', status_label: 'Sold',
        in_stock_here: false, sold_at: new Date().toISOString(), receipt_no: 'HQ-T01A-000001', customer: null, warranty_until: '2027-10-20', in_warranty: true }] })
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        sales.push(b)
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: `HQ-T01A-00000${sales.length}`, device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Efua', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [],
          lines: b.lines.map((l: Record<string, unknown>, i: number) => ({ line_no: i + 1, sku: 'X', name: 'Tecno Spark 20', uom: 'PC', qty: '1.000', unit_price_minor: l.unit_price_minor,
            line_total_minor: l.line_total_minor, serials: l.serials ?? [], warranty_until: l.serials ? '2027-10-20' : null })), payments: [], kind: 'sale', discount_minor: 0 } })
      }
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('efua')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()

  // Phone: scan its IMEI (the same one twice is refused).
  await page.getByLabel('Scan or search').fill('tecno')
  await page.getByLabel('Scan or search').press('Enter')
  const sn = page.getByRole('dialog', { name: 'Serial numbers for Tecno Spark 20' })
  await sn.getByLabel('Serial or IMEI number').fill('3569 0101-0000001')
  await sn.getByLabel('Serial or IMEI number').press('Enter')
  await sn.getByLabel('Serial or IMEI number').fill('356901010000001')
  await sn.getByLabel('Serial or IMEI number').press('Enter')
  await expect(sn.getByRole('alert')).toHaveText('356901010000001 is already on this sale.')
  await sn.getByRole('button', { name: 'Add 1 to sale' }).click()
  await expect(page.getByText('S/N 356901010000001')).toBeVisible()

  // Trade-in of a used phone worth 500.00 (supervisor approves), the rest in cash.
  await page.keyboard.press('F12')
  const pay = page.getByRole('dialog', { name: 'Payment' })
  await pay.getByRole('button', { name: 'Trade-in' }).click()
  const ti = page.getByRole('dialog', { name: 'Trade-in' })
  await ti.getByLabel('Find the item taken in').fill('used')
  await ti.getByRole('button', { name: 'Used phone' }).click()
  await ti.getByLabel('Serial / IMEI').fill('352000111222333')
  await ti.getByLabel('Condition').fill('Screen scratched')
  await ti.getByLabel('Value given').fill('500')
  await ti.getByLabel('Supervisor username').fill('kojo')
  await ti.getByLabel('Supervisor PIN').fill('771204')
  await ti.getByRole('button', { name: 'Add trade-in' }).click()
  await pay.getByRole('button', { name: 'Add cash' }).click()
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
  await expect(page.getByText(/S\/N 356901010000001 · warranty to 2027-10-20/)).toBeVisible()
  expect(sales[0]).toMatchObject({ lines: [{ product_id: 'ph', serials: ['356901010000001'] }],
    trade_ins: [{ product_id: 'used', serial: '352000111222333', condition: 'Screen scratched', value_minor: 50000 }],
    trade_in_approval: { username: 'kojo', pin: '771204' },
    payments: [{ method: 'trade_in', amount_minor: 50000 }, { method: 'cash', amount_minor: 100000 }] })

  // Repairs: warranty check, then collect a ready job and pay for its parts and labour.
  await page.getByRole('button', { name: 'New sale' }).click()
  await page.getByRole('button', { name: 'Repairs (F7)' }).click()
  const rep = page.getByRole('dialog', { name: 'Repairs' })
  await rep.getByLabel('Check warranty by serial').fill('356901010000001')
  await rep.getByRole('button', { name: 'Check' }).click()
  await expect(rep.getByText(/warranty until 2027-10-20/)).toBeVisible()
  await rep.getByRole('button', { name: /JOB-HQ-000001/ }).click()
  await expect(rep.getByText('GH₵ 300.00')).toBeVisible()
  await rep.getByRole('button', { name: 'Collect and pay' }).click()
  await page.getByRole('dialog', { name: 'Payment' }).getByRole('button', { name: 'Add cash' }).click()
  await expect(page.getByText('Receipt HQ-T01A-000002')).toBeVisible()
  expect(sales[1]).toMatchObject({ lines: [{ product_id: 'scr', repair_job_id: 'j1' }, { product_id: 'lab', repair_job_id: 'j1' }], totals: { total_minor: 30000 } })
})
