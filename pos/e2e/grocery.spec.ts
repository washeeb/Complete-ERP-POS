import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** Grocery at the till: scale labels with the weight in the barcode, and near-expiry markdown stickers. */

const grant = (pin: string) => {
  const salt = randomBytes(16)
  return { v: 1, id: 'g-esi', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-esi', user_name: 'Esi', username: 'esi',
    issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
    pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync(pin, salt, 1000, 32, 'sha256').toString('base64') }, sig: 's' }
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Esi', opening_float_minor: 0, expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = {
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null }, till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' },
  device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' }, user: { id: 'u-esi', name: 'Esi' },
  price_lists: [{ id: 'pl-retail', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(),
}

const catalogBody = {
  version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [],
  grocery: { scale_prefixes: ['21'], markdown_prefix: '29', markdowns: [{ id: 'md-1', code: '2912345678903', product_id: 'p-yog', product_uom_id: 'u-yog', price_minor: 700, percent_bp: 3000, valid_until: '2099-01-01' }] },
  products: [
    { id: 'p-tom', sku: 'TOM', name: 'Tomatoes', plu: '00123', category_id: null, track_stock: true, on_hand: '50.000', tax: [],
      units: [{ id: 'u-kg', uom_code: 'KG', uom_name: 'Kilogram', decimals: 3, factor: '1.000000', is_base: true, barcodes: [] }],
      prices: [{ price_list_id: 'pl-retail', product_uom_id: 'u-kg', min_qty: '0.001', amount_minor: 1200, starts_at: null, ends_at: null }] },
    { id: 'p-yog', sku: 'YOG', name: 'Fan Yogo', category_id: null, track_stock: true, on_hand: '20.000', tax: [],
      units: [{ id: 'u-yog', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: ['6001'] }],
      prices: [{ price_list_id: 'pl-retail', product_uom_id: 'u-yog', min_qty: '1', amount_minor: 1000, starts_at: null, ends_at: null }] },
  ],
}

test('sells a weighed pack from its scale label and a marked-down pack from its sticker', async ({ page }) => {
  const s = { sales: [] as Record<string, unknown>[] }
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 't-esi', expires_at: null, user: { id: 'u-esi', name: 'Esi' }, offline_grant: grant('482613') })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, catalogBody)
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
      case 'POST pos/sales': { const b = req.postDataJSON(); s.sales.push(b); return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: 'HQ-T01A-000001',
        device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at, cashier: 'Esi', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor,
        change_minor: 0, tax_breakdown: [], lines: [], payments: [], kind: 'sale', discount_minor: 0 } }) }
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
  await scan.fill('2100123012503'); await scan.press('Enter') // 1.250 kg tomatoes at 12.00/kg
  await scan.fill('2912345678903'); await scan.press('Enter') // sticker: Yogo at 7.00
  await expect(page.getByText('Marked-down sticker')).toBeVisible()
  await page.keyboard.press('F12')
  await page.getByRole('button', { name: /GH₵ 22\.00/ }).first().click()
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
  expect(s.sales[0]).toMatchObject({ totals: { total_minor: 2200 }, lines: [{ product_id: 'p-tom', qty: '1.25', unit_price_minor: 1200 },
    { product_id: 'p-yog', markdown_id: 'md-1', unit_price_minor: 700 }] })
})
