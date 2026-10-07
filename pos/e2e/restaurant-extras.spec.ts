import { expect, test, type Page, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** Restaurant extras at the till: a booking with a deposit, seating it, a delivery-app order paid by the app; waste on the kitchen screen. */

const grant = (pin: string) => {
  const salt = randomBytes(16)
  return { v: 1, id: 'g-ama', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-ama', user_name: 'Ama', username: 'ama',
    issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
    pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync(pin, salt, 1000, 32, 'sha256').toString('base64') }, sig: 's' }
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Ama', opening_float_minor: 0, expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = {
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null }, till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' },
  device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' }, user: { id: 'u-ama', name: 'Ama' },
  price_lists: [{ id: 'pl', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(),
}
const pc = (id: string) => [{ id, uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }]
const catalogBody = {
  version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], grocery: null, pharmacy: null,
  restaurant: { modifier_groups: [], service_charge: null, tips: true },
  products: [{ id: 'p-jol', sku: 'JOL', name: 'Jollof', category_id: null, track_stock: false, on_hand: null, tax: [], units: pc('u-jol'), station: 'KITCHEN', modifier_group_ids: [],
    prices: [{ price_list_id: 'pl', product_uom_id: 'u-jol', min_qty: '1', amount_minor: 6000, starts_at: null, ends_at: null }] }],
}
const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
const order = (over: Record<string, unknown>) => ({ number: 'ORD-HQ-000001', tab_name: null, covers: 0, customer_id: null, waiter: 'Ama', status: 'open', note: null,
  opened_at: new Date().toISOString(), version: 1, platform: null, external_ref: null, reservation: null,
  items: [{ id: 'i1', product_id: 'p-jol', product_uom_id: 'u-jol', name: 'Jollof', qty: '1.000', modifiers: [], note: null, course: 0, seat: null, station: 'KITCHEN',
    status: 'fired', paid: false, added_by: 'Ama', fired_at: new Date().toISOString(), ready_at: null, void_reason: null }], ...over })

async function signIn(page: Page) {
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('ama')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
}

test('books a table with a deposit, seats the guests and pays with the deposit; a delivery order paid by the app', async ({ page }) => {
  const seen = { booked: [] as unknown[], deposits: [] as unknown[], sales: [] as Record<string, unknown>[] }
  let booking: Record<string, unknown> | null = null
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    const key = `${req.method()} ${path}`
    if (key === 'POST devices/enroll') return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
    if (key === 'POST pos/login') return json(route, 200, { token: 't-ama', expires_at: null, user: { id: 'u-ama', name: 'Ama' }, offline_grant: grant('482613') })
    if (key === 'GET pos/bootstrap') return json(route, 200, bootstrap)
    if (key === 'GET pos/catalog') return json(route, 200, catalogBody)
    if (key === 'GET pos/customers') return json(route, 200, { version: 'c', customers: [] })
    if (key === 'GET pos/restaurant/floor') return json(route, 200, { tables: [{ id: 'tb-1', code: 'T1', area: null, seats: 4, pos_x: 0, pos_y: 0 }], stations: [], orders: [],
      reservations: booking ? [booking] : [], hold_minutes: 30, server_time: new Date().toISOString() })
    if (key.startsWith('GET pos/restaurant/reservations')) return json(route, 200, { reservations: booking ? [booking] : [] })
    if (key === 'POST pos/restaurant/reservations') {
      const b = req.postDataJSON()
      seen.booked.push(b)
      booking = { id: 'r1', number: 'RES-HQ-000001', duration_minutes: 90, customer_id: null, status: 'booked', deposit_minor: 0, deposit_left_minor: 0, deposit_status: 'none',
        dining_order_id: null, ...b }
      return json(route, 201, { reservation: booking })
    }
    if (key === 'POST pos/restaurant/reservations/r1/deposit') {
      seen.deposits.push(req.postDataJSON())
      booking = { ...booking!, deposit_minor: 5000, deposit_left_minor: 5000, deposit_status: 'held' }
      return json(route, 200, { reservation: booking })
    }
    if (key === 'POST pos/restaurant/reservations/r1/seat') {
      booking = { ...booking!, status: 'seated' }
      return json(route, 200, { order: order({ id: 'o-1', kind: 'table', label: 'Table T1', dining_table_id: 'tb-1', covers: 4, reservation: { number: 'RES-HQ-000001', deposit_left_minor: 5000 } }) })
    }
    if (key === 'GET pos/restaurant/orders/o-1') return json(route, 200, { order: order({ id: 'o-1', kind: 'table', label: 'Table T1', dining_table_id: 'tb-1', reservation: { number: 'RES-HQ-000001', deposit_left_minor: 5000 } }) })
    if (key === 'POST pos/restaurant/orders') {
      const b = req.postDataJSON()
      return json(route, 201, { order: order({ id: 'o-2', kind: 'delivery', label: 'Bolt Food #BF-77', dining_table_id: null, platform: b.platform, external_ref: b.external_ref.toUpperCase() }) })
    }
    if (key === 'GET pos/restaurant/orders/o-2') return json(route, 200, { order: order({ id: 'o-2', kind: 'delivery', label: 'Bolt Food #BF-77', dining_table_id: null, platform: 'bolt_food', external_ref: 'BF-77' }) })
    if (key === 'POST pos/sales') {
      const b = req.postDataJSON()
      seen.sales.push(b)
      return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: `HQ-T01A-00000${seen.sales.length}`, device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
        cashier: 'Ama', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [], lines: [], payments: [],
        kind: 'sale', discount_minor: 0, tip_minor: 0, order_number: null } })
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
  await signIn(page)

  // Booking with a deposit.
  await page.getByRole('button', { name: 'Tables (F6)' }).click()
  await page.getByRole('button', { name: 'Bookings' }).click()
  await page.getByRole('button', { name: 'New booking' }).click()
  const form = page.getByRole('dialog', { name: 'New booking' })
  await form.getByLabel('Name').fill('Mensah')
  await form.getByLabel('Phone').fill('0241112222')
  await form.getByLabel('Guests').fill('4')
  await form.getByLabel('Table').selectOption('tb-1')
  await form.getByRole('button', { name: 'Save booking' }).click()
  await expect.poll(() => seen.booked[0]).toMatchObject({ guest_name: 'Mensah', phone: '0241112222', party_size: 4, dining_table_id: 'tb-1' })
  const bookings = page.getByRole('dialog', { name: 'Bookings' })
  await bookings.getByRole('button', { name: 'Deposit' }).click()
  const dep = page.getByRole('dialog', { name: 'Take deposit' })
  await dep.getByLabel('Amount').fill('50')
  await dep.getByRole('button', { name: 'Take deposit' }).click()
  await expect.poll(() => seen.deposits[0]).toEqual({ method: 'cash', amount_minor: 5000 })
  await expect(bookings.getByText(/deposit GH₵ 50\.00 \(held\)/)).toBeVisible()

  // The guests arrive: seat them, and the deposit pays part of the bill.
  await bookings.getByRole('button', { name: 'Seat' }).click()
  const bill = page.getByRole('dialog', { name: 'Bill Table T1' })
  await bill.getByRole('button', { name: 'Pay bill' }).click()
  const pay = page.getByRole('dialog', { name: 'Payment' })
  await pay.getByRole('button', { name: /Use deposit RES-HQ-000001/ }).click()
  await pay.getByRole('button', { name: 'Add cash' }).click()
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
  expect(seen.sales[0]).toMatchObject({ dining_order_id: 'o-1', payments: [{ method: 'deposit', amount_minor: 5000, reference: 'RES-HQ-000001' }, { method: 'cash', amount_minor: 1000 }] })

  // A Bolt Food order keyed in: on hand-over, the app pays for it.
  await page.getByRole('button', { name: 'New sale' }).click()
  await page.getByRole('button', { name: 'Tables (F6)' }).click()
  await page.getByRole('button', { name: 'Delivery order' }).click()
  await page.getByRole('dialog', { name: 'Delivery order' }).getByLabel('Order number in the app').fill('bf-77')
  await page.getByRole('button', { name: 'Open order' }).click()
  await page.getByRole('dialog', { name: 'Bill Bolt Food #BF-77' }).getByRole('button', { name: 'Pay bill' }).click()
  await expect(pay.getByLabel('Tip')).toHaveCount(0) // no tips on delivery orders
  await pay.getByRole('button', { name: /Paid by Bolt Food/ }).click()
  await expect(page.getByText('Receipt HQ-T01A-000002')).toBeVisible()
  expect(seen.sales[1]).toMatchObject({ dining_order_id: 'o-2', payments: [{ method: 'aggregator', provider: 'bolt_food', reference: 'BF-77', amount_minor: 6000 }] })
})

test('the kitchen screen logs waste with the cook’s PIN and asks for a manager above the limit', async ({ page }) => {
  const waste: Record<string, unknown>[] = []
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    if (path === 'devices/enroll') return json(route, 201, { device_id: 'kds-1', token: 'kds-token', kind: 'kds' })
    if (path === 'kds/tickets') return json(route, 200, { stations: [], tickets: [], server_time: new Date().toISOString() })
    if (path === 'kds/products') return json(route, 200, { products: [{ id: 'p-chk', sku: 'CHK', name: 'Chicken portion', units: [{ id: 'u-chk', uom_code: 'PC', decimals: 0 }] }] })
    if (path === 'kds/waste') {
      const b = req.postDataJSON()
      waste.push(b)
      if (!b.approval) return json(route, 422, { error: 'waste_needs_approval', detail: 'Waste worth 60.00 needs a manager’s approval.' })
      return json(route, 201, { value_minor: 6000, currency: 'GHS', lines: 1, approved_by: 'Kojo' })
    }
    return json(route, 404, { error: 'not_found' })
  })
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByRole('button', { name: 'Log waste' }).click()
  const dlg = page.getByRole('dialog', { name: 'Kitchen waste' })
  await dlg.getByLabel('Find what was wasted').fill('chick')
  await dlg.getByRole('button', { name: /Chicken portion/ }).click()
  await dlg.getByLabel('Quantity of Chicken portion').fill('5')
  await dlg.getByLabel('What happened').fill('Fridge failed')
  await dlg.getByLabel('Your username').fill('yaw')
  await dlg.getByLabel('Your PIN').fill('246810')
  await dlg.getByRole('button', { name: 'Record waste' }).click()
  await expect(dlg.getByRole('alert')).toContainText('needs a manager')
  await dlg.getByLabel('Manager username').fill('kojo')
  await dlg.getByLabel('Manager PIN').fill('771204')
  await dlg.getByRole('button', { name: 'Record waste' }).click()
  await expect(page.getByText('approved by Kojo')).toBeVisible()
  expect(waste[1]).toMatchObject({ username: 'yaw', pin: '246810', reason: 'Fridge failed', items: [{ product_id: 'p-chk', product_uom_id: 'u-chk', qty: '5' }],
    approval: { username: 'kojo', pin: '771204' } })
})
