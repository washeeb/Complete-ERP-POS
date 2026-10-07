import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** Restaurant at the till: open a table, order with choices, send to the kitchen, pay with service charge and a tip; the kitchen screen. */

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
const price = (unit: string, amount: number) => [{ price_list_id: 'pl', product_uom_id: unit, min_qty: '1', amount_minor: amount, starts_at: null, ends_at: null }]
const catalogBody = {
  version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], grocery: null, pharmacy: null,
  restaurant: {
    modifier_groups: [
      { id: 'g-spice', name: 'Spice', min: 1, max: 1, options: [{ id: 'o-mild', name: 'Mild', product_id: null }, { id: 'o-hot', name: 'Hot', product_id: null }] },
      { id: 'g-extra', name: 'Extras', min: 0, max: 2, options: [{ id: 'o-chs', name: 'Extra cheese', product_id: 'p-chs' }] },
    ],
    service_charge: { product_id: 'p-svc', bp: 1000 }, tips: true,
  },
  products: [
    { id: 'p-jol', sku: 'JOL', name: 'Jollof with chicken', category_id: null, track_stock: false, on_hand: null, tax: [], units: pc('u-jol'), prices: price('u-jol', 6000),
      station: 'KITCHEN', modifier_group_ids: ['g-spice', 'g-extra'] },
    { id: 'p-chs', sku: 'CHS', name: 'Extra cheese', category_id: null, track_stock: true, on_hand: '10.000', tax: [], units: pc('u-chs'), prices: price('u-chs', 500) },
    { id: 'p-svc', sku: 'SERVICE', name: 'Service charge', category_id: null, track_stock: false, on_hand: null, tax: [], units: pc('u-svc'), prices: [] },
  ],
}

test('opens a table, sends a dish with choices to the kitchen and pays the bill with service charge and a tip', async ({ page }) => {
  const s = { sales: [] as Record<string, unknown>[], sent: [] as Record<string, unknown>[] }
  let order: Record<string, unknown> | null = null
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    const floor = () => ({ tables: [{ id: 'tb-1', code: 'T1', area: 'Inside', seats: 4, pos_x: 0, pos_y: 0 }, { id: 'tb-2', code: 'T2', area: 'Inside', seats: 2, pos_x: 1, pos_y: 0 }],
      stations: [{ code: 'KITCHEN', name: 'Kitchen', auto_print: false }], orders: order ? [order] : [], server_time: new Date().toISOString() })
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 't-ama', expires_at: null, user: { id: 'u-ama', name: 'Ama' }, offline_grant: grant('482613') })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, catalogBody)
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
      case 'GET pos/restaurant/floor': return json(route, 200, floor())
      case 'POST pos/restaurant/orders': {
        const b = req.postDataJSON()
        order = { id: b.id, number: 'ORD-HQ-000001', kind: 'table', label: 'Table T1', dining_table_id: b.dining_table_id, tab_name: null, covers: b.covers, customer_id: null,
          waiter: 'Ama', status: 'open', note: null, opened_at: new Date().toISOString(), version: 1, items: [] }
        return json(route, 201, { order })
      }
      case `POST pos/restaurant/orders/${order?.id}/items`: {
        const b = req.postDataJSON()
        s.sent.push(b)
        order = { ...order!, items: b.items.map((i: Record<string, unknown>) => ({ id: i.id, product_id: i.product_id, product_uom_id: i.product_uom_id, name: 'Jollof with chicken',
          qty: '1.000', modifiers: [{ group: 'Spice', option_id: 'o-hot', name: 'Hot', product_id: null }, { group: 'Extras', option_id: 'o-chs', name: 'Extra cheese', product_id: 'p-chs' }],
          note: i.note, course: i.course, seat: i.seat, station: 'KITCHEN', status: 'fired', paid: false, added_by: 'Ama', fired_at: new Date().toISOString(), ready_at: null, void_reason: null })) }
        return json(route, 200, { order })
      }
      case `GET pos/restaurant/orders/${order?.id}`: return json(route, 200, { order })
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        s.sales.push(b)
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: 'HQ-T01A-000001', device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Ama', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor + b.tip_minor, change_minor: 0, tax_breakdown: [], lines: [],
          payments: [], kind: 'sale', discount_minor: 0, tip_minor: b.tip_minor, order_number: 'ORD-HQ-000001' } })
      }
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('ama')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()

  await page.getByRole('button', { name: 'Tables (F6)' }).click()
  await page.getByRole('button', { name: 'Table T1, free' }).click()
  await page.getByRole('button', { name: 'Open bill' }).click()
  const bill = page.getByRole('dialog', { name: 'Bill Table T1' })
  await bill.getByLabel('Find a dish or drink').fill('jollof')
  await bill.getByRole('button', { name: /Jollof with chicken/ }).click()
  const choices = page.getByRole('dialog', { name: 'Choices for Jollof with chicken' })
  await choices.getByRole('button', { name: 'Add' }).click()
  await expect(choices.getByRole('alert')).toHaveText('Choose 1 for Spice.')
  await choices.getByRole('button', { name: 'Hot' }).click()
  await choices.getByRole('button', { name: 'Extra cheese +' }).click()
  await choices.getByLabel('Seat').fill('1')
  await choices.getByRole('button', { name: 'Add' }).click()
  await bill.getByRole('button', { name: 'Send to kitchen' }).click()
  await expect(bill.getByText('In kitchen')).toBeVisible()
  expect(s.sent[0]).toMatchObject({ items: [{ product_id: 'p-jol', modifier_option_ids: ['o-hot', 'o-chs'], seat: 1, course: 0, hold: false }] })

  await bill.getByRole('button', { name: 'Pay bill' }).click()
  // 60.00 + 5.00 cheese + 10 % service 6.50 = 71.50; tip 10 % of the bill = 7.15.
  const pay = page.getByRole('dialog', { name: 'Payment' })
  await pay.getByRole('button', { name: '10 %' }).click()
  await expect(pay.getByText('GH₵ 78.65').first()).toBeVisible()
  await pay.getByRole('button', { name: 'Add cash' }).click()
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
  await expect(page.getByText('Order 001')).toBeVisible()
  expect(s.sales[0]).toMatchObject({ dining_order_id: order!.id, tip_minor: 715, totals: { total_minor: 7150 }, lines: [
    { product_id: 'p-jol', note: 'Hot, Extra cheese' }, { product_id: 'p-chs', note: 'with Jollof with chicken' }, { product_id: 'p-svc', service_charge: true, unit_price_minor: 650 },
  ] })
})

test('the kitchen screen shows tickets and marks dishes ready without anyone signing in', async ({ page }) => {
  const bumps: Record<string, unknown>[] = []
  let status = 'fired'
  const json = (route: Route, code: number, body: unknown) => route.fulfill({ status: code, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    if (path === 'devices/enroll') return json(route, 201, { device_id: 'kds-1', token: 'kds-token', kind: 'kds' })
    if (path === 'kds/tickets') {
      expect(req.headers().authorization).toBe('Bearer kds-token')
      return json(route, 200, { stations: [{ code: 'KITCHEN', name: 'Kitchen', auto_print: false }], server_time: new Date().toISOString(), tickets: [{ order_id: 'o1',
        number: 'ORD-HQ-000001', label: 'Table T1', kind: 'table', waiter: 'Ama', paid: false, items: [{ id: 'i1', product_id: 'p', product_uom_id: 'u', name: 'Jollof', qty: '2.000',
          modifiers: [{ group: 'Spice', option_id: 'o', name: 'Hot', product_id: null }], note: 'no onions', course: 0, seat: null, station: 'KITCHEN', status, paid: false,
          added_by: 'Ama', fired_at: new Date(Date.now() - 12 * 60000).toISOString(), ready_at: null, void_reason: null }] }] })
    }
    if (path === 'kds/bump') {
      bumps.push(req.postDataJSON())
      status = req.postDataJSON().status
      return json(route, 200, { changed: 1 })
    }
    return json(route, 404, { error: 'not_found' })
  })
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  const ticket = page.getByRole('article', { name: 'Ticket Table T1' })
  await expect(ticket.getByText('Hot, no onions')).toBeVisible()
  await ticket.getByRole('button', { name: /Jollof tap when ready/ }).click()
  await expect(ticket.getByRole('button', { name: /ready — tap to recall/ })).toBeVisible()
  await ticket.getByRole('button', { name: 'Collected' }).click()
  await expect.poll(() => bumps).toEqual([{ item_ids: ['i1'], status: 'ready' }, { item_ids: ['i1'], status: 'served' }])
})
