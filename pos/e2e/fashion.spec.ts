import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** Fashion at the till: pick a size and colour from the style grid; collect an alteration at its agreed charge. */

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
const variant = (colour: string, size: string, onHand: string) => ({ id: `v-${colour}-${size}`, sku: `KD-${colour.toUpperCase()}-${size}`, name: `Kente dress ${colour} ${size}`,
  category_id: null, track_stock: true, on_hand: onHand, tax: [], style_id: 'st', colour, size,
  units: [{ id: `u-${colour}-${size}`, uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }],
  prices: [{ price_list_id: 'pl', product_uom_id: `u-${colour}-${size}`, min_qty: '1', amount_minor: 45000, starts_at: null, ends_at: null }] })
const catalogBody = { version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], grocery: null, pharmacy: null, restaurant: null, electronics: false,
  fashion: { styles: [{ id: 'st', code: 'KD', name: 'Kente dress', sizes: ['S', 'M'], colours: ['Black', 'Gold'], season: 'SS26', collection: null }], alteration_product_id: 'alt' },
  products: [variant('Black', 'S', '2.000'), variant('Black', 'M', '0.000'), variant('Gold', 'S', '4.000'), variant('Gold', 'M', '1.000'),
    { id: 'alt', sku: 'ALTERATION', name: 'Alteration', category_id: null, track_stock: false, on_hand: null, tax: [], prices: [],
      units: [{ id: 'u-alt', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }] }] }
const alteration = { id: 'a1', number: 'ALT-HQ-000001', customer_id: null, customer_name: 'Ama', phone: null, garment: 'Trousers', work: 'Hem 2 cm', charge_minor: 5000,
  status: 'ready', status_label: 'Ready to collect', due_on: null, tailor_name: 'Abla', received_at: new Date().toISOString() }

test('picks a size and colour from the style grid, and collects an alteration at its charge', async ({ page }) => {
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
      case 'GET pos/alterations': return json(route, 200, { alterations: [alteration], product_id: 'alt' })
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        sales.push(b)
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: 'HQ-T01A-000001', device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Efua', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [], lines: [], payments: [],
          kind: 'sale', discount_minor: 0 } })
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

  await page.getByLabel('Scan or search').fill('kente gold m')
  await page.getByRole('button', { name: /Kente dress Gold M/ }).click()
  const grid = page.getByRole('dialog', { name: 'Sizes and colours of Kente dress' })
  await expect(grid.getByRole('button', { name: 'Black M' })).toHaveText('0')
  await grid.getByRole('button', { name: 'Gold S' }).click()
  await expect(page.getByRole('cell', { name: 'Kente dress Gold S', exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'Alterations (F9)' }).click()
  await page.getByRole('dialog', { name: 'Alterations' }).getByRole('button', { name: 'Collect and pay' }).click()
  await expect(page.getByText('ALT-HQ-000001 · Trousers')).toBeVisible()
  await page.keyboard.press('F12')
  await page.getByRole('dialog', { name: 'Payment' }).getByRole('button', { name: 'Add cash' }).click()
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
  expect(sales[0]).toMatchObject({ totals: { total_minor: 50000 }, lines: [{ product_id: 'v-Gold-S', unit_price_minor: 45000 },
    { product_id: 'alt', alteration_id: 'a1', unit_price_minor: 5000 }] })
})
