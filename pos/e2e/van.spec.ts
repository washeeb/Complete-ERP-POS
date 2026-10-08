import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** The rep's phone on a van: today's route, a sale with container deposits that records the visit, a no-order visit, an order for delivery, and empties paid out. */

const grant = (pin: string) => {
  const salt = randomBytes(16)
  return { v: 1, id: 'g-kojo', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-v', user_id: 'u-kojo', user_name: 'Kojo', username: 'kojo',
    issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
    pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync(pin, salt, 1000, 32, 'sha256').toString('base64') }, sig: 's' }
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Kojo', opening_float_minor: 0, expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = {
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null }, till: { id: 'till-v', code: 'V01', name: 'Van Kumasi 1', warehouse: 'Kumasi van 1' },
  device: { id: 'dev-1', name: 'Kojo phone', receipt_series: 'A' }, user: { id: 'u-kojo', name: 'Kojo' },
  price_lists: [{ id: 'pl', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(),
  van: { van: { id: 'v', code: 'V01', name: 'Kumasi van 1', vehicle_reg: 'GR 1234-24' }, routes: [{ id: 'r1', code: 'KN', name: 'Kumasi North' }], weekday: 1,
    stops: [{ route_id: 'r1', customer_id: 'c-ama', sequence: 1 }, { route_id: 'r1', customer_id: 'c-kofi', sequence: 2 }],
    month: { target_minor: 500000, sold_minor: 125000, visit_target: null } },
}
const unit = (id: string) => [{ id: `u-${id}`, uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }]
const price = (id: string, amount: number) => [{ price_list_id: 'pl', product_uom_id: `u-${id}`, min_qty: '1', amount_minor: amount, starts_at: null, ends_at: null }]
const catalogBody = { version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], grocery: null, pharmacy: null, restaurant: null, electronics: false, fashion: null, services: null,
  products: [
    { id: 'malt', sku: 'MALT-33', name: 'Malt 330 ml', category_id: null, track_stock: true, on_hand: '48.000', tax: [], units: unit('malt'), prices: price('malt', 1000), deposit_product_id: 'bottle', deposit_per_unit: '1.000' },
    { id: 'bottle', sku: 'BOTTLE', name: 'Malt bottle (empty)', category_id: null, track_stock: true, on_hand: '20.000', tax: [], units: unit('bottle'), prices: price('bottle', 200), is_container: true },
  ] }
const customer = (id: string, name: string) => ({ id, code: id, name, phone: '0241112222', price_list_id: null, credit_limit_minor: 50000, credit_hold: false, balance_minor: 0, available_minor: 50000, overdue_minor: 0 })

test('a van rep sells on the route with deposits, records visits, takes an order and pays out empties', async ({ page }) => {
  const sales: Record<string, unknown>[] = []
  const visits: Record<string, unknown>[] = []
  const orders: Record<string, unknown>[] = []
  page.on('dialog', (d) => d.accept(d.type() === 'prompt' ? 'Still has stock' : undefined))
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 't-kojo', expires_at: null, user: { id: 'u-kojo', name: 'Kojo' }, offline_grant: grant('482613') })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, catalogBody)
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [customer('c-ama', 'Ama Provisions'), customer('c-kofi', 'Kofi Stores')] })
      case 'POST pos/van/visits': visits.push(req.postDataJSON()); return json(route, 200, { status: 'accepted' })
      case 'POST pos/van/orders': orders.push(req.postDataJSON()); return json(route, 201, { status: 'accepted', order: { id: 'o1', number: 'SO-HQ-000007', status: 'confirmed', total_minor: 12000, hold_reason: null } })
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        sales.push(b)
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: `HQ-V01A-00000${sales.length}`, device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Kojo', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [], lines: [], payments: [],
          kind: 'sale', discount_minor: 0, licence_keys: [] } })
      }
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('kojo')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByLabel('Van')).toContainText('V01 · 25% of target')

  // Route → sell to Ama: two malts bring two bottle deposits; paying records the visit as "sold".
  await page.getByRole('button', { name: 'Route (F3)' }).click()
  await page.getByRole('dialog', { name: "Today's route" }).getByRole('listitem').filter({ hasText: 'Ama Provisions' }).getByRole('button', { name: 'Sell' }).click()
  await page.getByLabel('Scan or search').fill('malt')
  await page.getByRole('button', { name: /Malt 330 ml/ }).click()
  await page.getByLabel('Quantity of Malt 330 ml').fill('2')
  await page.getByLabel('Quantity of Malt 330 ml').press('Enter')
  await expect(page.getByText('Deposit for the containers')).toBeVisible()
  await page.keyboard.press('F12')
  await page.getByRole('dialog', { name: 'Payment' }).getByRole('button', { name: 'Add cash' }).click()
  await expect(page.getByText('Receipt HQ-V01A-000001')).toBeVisible()
  expect(sales[0]).toMatchObject({ customer_id: 'c-ama', totals: { total_minor: 2400 }, lines: [{ product_id: 'malt', qty: '2' }, { product_id: 'bottle', qty: '2' }] })
  await expect.poll(() => visits.length).toBe(1)
  expect(visits[0]).toMatchObject({ customer_id: 'c-ama', route_id: 'r1', outcome: 'sold', sale_id: (sales[0] as { id: string }).id })

  // Kofi has stock: "No order", with the reason.
  await page.getByRole('button', { name: 'New sale' }).click()
  await page.getByRole('button', { name: 'Route (F3)' }).click()
  const route = page.getByRole('dialog', { name: "Today's route" })
  await expect(route.getByText('Sold')).toBeVisible()
  await route.getByRole('listitem').filter({ hasText: 'Kofi Stores' }).getByRole('button', { name: 'No order' }).click()
  await expect.poll(() => visits.length).toBe(2)
  expect(visits[1]).toMatchObject({ customer_id: 'c-kofi', outcome: 'no_order', note: 'Still has stock' })

  // An order for delivery for Kofi: no deposit lines go into the order.
  await route.getByRole('listitem').filter({ hasText: 'Kofi Stores' }).getByRole('button', { name: 'Take order' }).click()
  await page.getByLabel('Scan or search').fill('malt')
  await page.getByRole('button', { name: /Malt 330 ml/ }).click()
  await page.getByRole('button', { name: 'Send order (F12)' }).click()
  await expect(page.getByText('Order SO-HQ-000007 sent for Kofi Stores.')).toBeVisible()
  expect(orders[0]).toMatchObject({ customer_id: 'c-kofi', lines: [{ product_uom_id: 'u-malt', qty: '1' }] })
  await expect.poll(() => visits.length).toBe(3)
  expect(visits[2]).toMatchObject({ outcome: 'order' })

  // Empties only: the deposit is paid out in cash.
  await page.getByRole('button', { name: 'Empties returned' }).click()
  await page.getByLabel('How many').fill('3')
  await page.getByRole('button', { name: 'Take back' }).click()
  await page.getByRole('button', { name: 'Pay out deposit (F12)' }).click()
  await expect.poll(() => sales.length).toBe(2)
  expect(sales[1]).toMatchObject({ totals: { total_minor: -600 }, payments: [{ method: 'cash', amount_minor: -600 }], lines: [{ product_id: 'bottle', qty: '-3' }] })
})
