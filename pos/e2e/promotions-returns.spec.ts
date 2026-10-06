import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * Promotions and coupons computed on the till (the same engine as the server), a manual discount approved offline by a
 * supervisor's PIN against their offline sign-in, and a return against a receipt refunded in cash.
 */

const grantFor = (userId: string, name: string, username: string, pin: string) => {
  const salt = randomBytes(16)
  return { v: 1, id: `g-${username}`, tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: userId, user_name: name, username,
    issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
    pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync(pin, salt, 1000, 32, 'sha256').toString('base64') }, sig: 's' }
}
const catalog = {
  version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0,
  promotions: [
    { id: 'p1', name: 'Bread 3 for 2', type: 'buy_x_get_y', product_ids: ['p-bread'], category_ids: [], value_bp: 10000, value_minor: 0, buy_qty: 2, get_qty: 1, bundle_qty: 0,
      min_spend_minor: 0, coupon_code: null, starts_at: null, ends_at: null, days: [], time_from: null, time_to: null, branch_ids: [], price_list_ids: [] },
    { id: 'p2', name: 'SAVE5', type: 'coupon', product_ids: [], category_ids: [], value_bp: 0, value_minor: 500, buy_qty: 0, get_qty: 0, bundle_qty: 0,
      min_spend_minor: 1000, coupon_code: 'SAVE5', starts_at: null, ends_at: null, days: [], time_from: null, time_to: null, branch_ids: [], price_list_ids: [] },
  ],
  products: [{
    id: 'p-bread', sku: 'BREAD', name: 'Bread', category_id: null, track_stock: true, on_hand: '20.000', tax: [],
    units: [{ id: 'pu-bread', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: ['2000002'] }],
    prices: [{ price_list_id: 'pl-retail', product_uom_id: 'pu-bread', min_qty: '1.000', amount_minor: 2500, starts_at: null, ends_at: null }],
  }],
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Esi', opening_float_minor: 0, expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = {
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null }, till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' },
  device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' }, user: { id: 'u-esi', name: 'Esi' },
  price_lists: [{ id: 'pl-retail', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(),
}
const saleResponse = (b: Record<string, unknown> & { totals: { total_minor: number } }, no: string) => ({ replayed: false, sale: { id: b.id, receipt_no: no, device_seq: b.device_seq,
  status: 'posted', occurred_at: b.occurred_at, cashier: 'Esi', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0,
  tax_breakdown: [], lines: [], payments: [], kind: 'sale', discount_minor: 0 } })

test('promotions, coupon, an offline-approved discount and a return', async ({ page }) => {
  const s = { up: true, sales: [] as Record<string, unknown>[], queued: [] as Record<string, unknown>[], returns: [] as Record<string, unknown>[] }
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => (s.up ? r.fulfill({ status: 200, body: 'ok' }) : r.fulfill({ status: 502, body: 'down' })))
  await page.route('**/api/v1/**', async (route) => {
    if (!s.up) return route.fulfill({ status: 502, body: 'down' })
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': {
        const who = req.postDataJSON().username
        return json(route, 200, { token: `t-${who}`, expires_at: null, user: { id: who === 'kojo' ? 'u-kojo' : 'u-esi', name: who }, offline_grant: who === 'kojo' ? grantFor('u-kojo', 'Kojo', 'kojo', '771204') : grantFor('u-esi', 'Esi', 'esi', '482613') })
      }
      case 'POST pos/logout': return json(route, 200, { ok: true })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, catalog)
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
      case 'POST pos/sales': { const b = req.postDataJSON(); s.sales.push(b); return json(route, 201, saleResponse(b, `HQ-T01A-00000${s.sales.length}`)) }
      case 'POST pos/sync': { const b = JSON.parse(req.postData() ?? '{}'); s.queued.push(...b.events); return json(route, 200, { results: b.events.map((e: { id: string }) => ({ id: e.id, status: 'accepted' })) }) }
      case 'GET pos/receipts': return json(route, 200, { customer_id: null, sale: { ...saleResponse(s.sales[0] as never, 'HQ-T01A-000001').sale },
        returnable: { within_period: true, lines: [{ id: 'l1', product_id: 'p-bread', product_uom_id: 'pu-bread', name: 'Bread', sku: 'BREAD', uom: 'PC', qty: '3.000', line_total_minor: 5000, returnable_qty: '3.000' }],
          tenders: [{ method: 'cash', paid_minor: 4500, refundable_minor: 4500, reference: null }] } })
      case 'POST pos/returns': { const b = req.postDataJSON(); s.returns.push(b); return json(route, 201, { store_credit_code: null, sale: { ...saleResponse({ ...b, totals: { total_minor: -1667 } }, 'HQ-T01A-000003').sale, kind: 'return' } }) }
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })

  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  // The supervisor signs in once on this till (so their PIN works offline), then the cashier.
  for (const [u, pin] of [['kojo', '771204'], ['esi', '482613']]) {
    await page.getByLabel('Username').fill(u)
    await page.getByLabel('PIN', { exact: true }).fill(pin)
    await page.getByRole('button', { name: 'OK' }).click()
    if (u === 'kojo') { await page.getByRole('button', { name: 'Sign out' }).click() }
  }

  // 3 breads: 3-for-2 promotion; coupon SAVE5 on top → 50.00 − 5.00 = 45.00
  const scan = page.getByLabel('Scan or search')
  for (let i = 0; i < 3; i++) { await scan.fill('2000002'); await scan.press('Enter') }
  await expect(page.getByText('Bread 3 for 2 −GH₵ 25.00')).toBeVisible()
  await page.getByLabel('Coupon code').fill('save5')
  await page.getByRole('button', { name: 'Apply coupon' }).click()
  await expect(page.getByText('Coupon SAVE5 applied')).toBeVisible()
  await page.keyboard.press('F12')
  await page.getByRole('button', { name: /GH₵ 45\.00/ }).first().click()
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
  expect(s.sales[0]).toMatchObject({ coupon_code: 'SAVE5', totals: { total_minor: 4500 } })
  await page.getByRole('button', { name: 'New sale' }).click()

  // Server down: a 10 % line discount needs a supervisor PIN, checked against their offline sign-in.
  s.up = false
  await scan.fill('2000002'); await scan.press('Enter')
  await page.getByRole('button', { name: 'Discount Bread' }).click()
  await page.getByPlaceholder('10').fill('10')
  await page.getByRole('button', { name: 'Apply', exact: true }).click()
  await page.keyboard.press('F12')
  await page.getByRole('button', { name: /GH₵ 22\.50/ }).first().click()
  await page.getByLabel('Supervisor username').fill('kojo')
  await page.getByLabel('Supervisor PIN').fill('771204')
  await page.getByRole('button', { name: 'Approve', exact: true }).click()
  await expect(page.getByText('Receipt HQ-T01A-000002')).toBeVisible()
  s.up = true
  await page.getByRole('button', { name: 'New sale' }).click()
  await expect.poll(() => s.queued.filter((e) => (e as { type: string }).type === 'sale').length, { timeout: 30_000 }).toBe(1)
  const queuedSale = (s.queued.find((e) => (e as { type: string }).type === 'sale') as { payload: Record<string, unknown> }).payload
  expect(queuedSale).toMatchObject({ discount_approved_offline_by: 'u-kojo', lines: [{ manual: { bp: 1000 }, line_total_minor: 2250 }] })

  // Return one bread from the first receipt: refunded in cash at the price actually paid (50.00 / 3).
  await page.getByRole('button', { name: 'Return (F8)' }).click()
  await page.getByLabel('Receipt number').fill('HQ-T01A-000001')
  await page.getByRole('button', { name: 'Find receipt' }).click()
  await page.getByLabel('Return Bread').fill('1')
  await page.getByLabel('Return reason').fill('Stale')
  await expect(page.getByText('Cash: GH₵ 16.67')).toBeVisible()
  await page.getByRole('button', { name: 'Refund' }).click()
  await expect(page.getByText('RETURN HQ-T01A-000003')).toBeVisible()
  expect(s.returns[0]).toMatchObject({ original_sale_id: s.sales[0].id, lines: [{ original_line_id: 'l1', qty: '1' }], refunds: [{ method: 'cash', amount_minor: 1667 }] })
})
