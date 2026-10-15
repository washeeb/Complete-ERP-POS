import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** "Show stock left on the till": each line shows what is left, counting the sale itself; off shows nothing. */

const grant = (pin: string) => {
  const salt = randomBytes(16)
  return { v: 1, id: 'g-esi', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-esi', user_name: 'Esi', username: 'esi',
    issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
    pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync(pin, salt, 1000, 32, 'sha256').toString('base64') }, sig: 's' }
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Esi', opening_float_minor: 0, expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = (show: boolean) => ({
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null }, till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' },
  device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' }, user: { id: 'u-esi', name: 'Esi' },
  price_lists: [{ id: 'pl-retail', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', show_stock: show, shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(),
})
const units = [
  { id: 'u-tab', uom_code: 'TAB', uom_name: 'Tablet', decimals: 0, factor: '1.000000', is_base: true, barcodes: ['7001'] },
  { id: 'u-strip', uom_code: 'STRIP', uom_name: 'Strip', decimals: 0, factor: '10.000000', is_base: false, barcodes: ['7010'] },
]
const prices = [{ price_list_id: 'pl-retail', product_uom_id: 'u-tab', min_qty: '1', amount_minor: 50, starts_at: null, ends_at: null },
  { price_list_id: 'pl-retail', product_uom_id: 'u-strip', min_qty: '1', amount_minor: 450, starts_at: null, ends_at: null }]
const catalogBody = { version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], grocery: null, pharmacy: { interactions: [] },
  products: [
    { id: 'p-pcm', sku: 'PCM', name: 'Paracetamol 500mg', category_id: null, track_stock: true, on_hand: '25.000', tax: [], units, prices },
    { id: 'p-vit', sku: 'VIT', name: 'Vitamin C', category_id: null, track_stock: true, on_hand: '1.000', tax: [],
      units: [{ id: 'u-vit', uom_code: 'BTL', uom_name: 'Bottle', decimals: 0, factor: '1.000000', is_base: true, barcodes: ['7020'] }],
      prices: [{ price_list_id: 'pl-retail', product_uom_id: 'u-vit', min_qty: '1', amount_minor: 1500, starts_at: null, ends_at: null }] },
  ] }

async function openTill(page: import('@playwright/test').Page, show: boolean) {
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 't-esi', expires_at: null, user: { id: 'u-esi', name: 'Esi' }, offline_grant: grant('482613') })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap(show))
      case 'GET pos/catalog': return json(route, 200, catalogBody)
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('esi')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  return page.getByLabel('Scan or search')
}

test('shows what is left as items go into the sale, in the base unit', async ({ page }) => {
  const scan = await openTill(page, true)
  await scan.fill('7010'); await scan.press('Enter') // a strip of 10
  const cart = page.getByRole('region', { name: 'Cart' })
  await expect(cart.getByText('15 TAB left')).toBeVisible()
  await scan.fill('7001'); await scan.press('Enter') // one tablet more
  await expect(cart.getByText('14 TAB left').first()).toBeVisible()
  await scan.fill('7020'); await scan.press('Enter')
  await scan.fill('7020'); await scan.press('Enter') // two bottles, one in stock
  await expect(cart.getByText('None left (-1 BTL)')).toBeVisible()
  await scan.fill('Para')
  await expect(page.getByRole('button', { name: /Paracetamol 500mg/ }).getByText('14 TAB left')).toBeVisible()
  await page.screenshot({ path: 'test-results/stock-left.png' })
})

test('shows no stock figures when the setting is off', async ({ page }) => {
  const scan = await openTill(page, false)
  await scan.fill('7001'); await scan.press('Enter')
  await expect(page.getByRole('region', { name: 'Cart' }).getByText('Paracetamol 500mg')).toBeVisible()
  await expect(page.getByText(/left$/)).toHaveCount(0)
  await scan.fill('Para')
  await expect(page.getByText(/in stock|left/)).toHaveCount(0)
})
