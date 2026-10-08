import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** Services at the till: check out an appointment (paid, done by the booked stylist), and use a prepaid session (nothing to pay). */

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
const service = (id: string, name: string, price: number, minutes: number) => ({ id, sku: id.toUpperCase(), name, category_id: null, track_stock: false, on_hand: null, tax: [],
  service_minutes: minutes, units: [{ id: `u-${id}`, uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }],
  prices: [{ price_list_id: 'pl', product_uom_id: `u-${id}`, min_qty: '1', amount_minor: price, starts_at: null, ends_at: null }] })
const staff = [{ id: 'e-abla', name: 'Abla', job_title: 'Stylist' }, { id: 'e-yaw', name: 'Yaw', job_title: 'Therapist' }]
const catalogBody = { version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], grocery: null, pharmacy: null, restaurant: null, electronics: false, fashion: null,
  services: { staff, slot_minutes: 15, day_start: '08:00', day_end: '20:00' },
  products: [service('cut', 'Haircut', 8000, 45), service('massage', 'Massage', 20000, 60)] }
const customers = { version: 'c1', customers: [{ id: 'cus-1', code: 'CUS-HQ-000001', name: 'Ama Owusu', phone: '+233241112222', price_list_id: null,
  credit_limit_minor: null, credit_hold: false, balance_minor: 0, available_minor: null, overdue_minor: 0 }] }
const inAnHour = new Date(Date.now() + 3600_000)
const appointment = { id: 'apt-1', number: 'APT-HQ-000001', customer_id: 'cus-1', customer_name: 'Ama Owusu', phone: null, employee_id: 'e-abla', employee_name: 'Abla',
  product_id: 'cut', service: 'Haircut', starts_at: inAnHour.toISOString(), ends_at: new Date(inAnHour.getTime() + 45 * 60_000).toISOString(),
  status: 'arrived', status_label: 'Arrived', note: null }
const pass = { id: 'pass-1', number: 'PASS-HQ-000001', name: '5 massages', expires_on: null,
  items: [{ label: 'Massage', product_ids: ['massage'], sessions: 5, remaining: 3 }] }

test('checks out an appointment with its stylist, then uses a prepaid session with nothing to pay', async ({ page }) => {
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
      case 'GET pos/customers': return json(route, 200, customers)
      case 'GET pos/services/appointments': return json(route, 200, { date: new Date().toLocaleDateString('en-CA'), staff, appointments: [appointment] })
      case 'GET pos/services/passes': return json(route, 200, { passes: [pass] })
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        sales.push(b)
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: `HQ-T01A-00000${sales.length}`, device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Efua', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [], lines: [], payments: [],
          kind: 'sale', discount_minor: 0, licence_keys: [] } })
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

  // Appointment book (F10): Abla's arrived customer is checked out — the haircut goes on the till with her, and the customer is picked.
  await page.getByRole('button', { name: 'Appointments (F10)' }).click()
  const book = page.getByRole('dialog', { name: 'Appointments' })
  await expect(book.getByText('Ama Owusu · Haircut')).toBeVisible()
  await book.getByRole('button', { name: 'Check out' }).click()
  await expect(page.getByText('with Abla')).toBeVisible()
  await expect(page.getByLabel('Customer', { exact: true })).toContainText('Ama Owusu')
  await page.keyboard.press('F12')
  await page.getByRole('dialog', { name: 'Payment' }).getByRole('button', { name: 'Add cash' }).click()
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
  expect(sales[0]).toMatchObject({ customer_id: 'cus-1', totals: { total_minor: 8000 }, lines: [{ product_id: 'cut', staff_id: 'e-abla', appointment_id: 'apt-1' }] })

  // A session from Ama's prepaid pack, done by Yaw: a line at no charge, so the sale goes straight through without payment.
  await page.getByRole('button', { name: 'New sale' }).click()
  await page.getByRole('button', { name: 'Customer (F4)' }).click()
  await page.getByLabel('Find customer').fill('Ama')
  await page.getByRole('button', { name: /Ama Owusu/ }).click()
  await page.getByRole('button', { name: 'Passes' }).click()
  await page.getByRole('dialog', { name: 'Prepaid passes' }).getByRole('button', { name: 'Use for Massage' }).click()
  await page.getByRole('dialog', { name: 'Who did Massage?' }).getByRole('button', { name: 'Yaw' }).click()
  await expect(page.getByText('Prepaid session · PASS-HQ-000001')).toBeVisible()
  await page.keyboard.press('F12')
  await expect.poll(() => sales.length).toBe(2)
  expect(sales[1]).toMatchObject({ payments: [], totals: { total_minor: 0 },
    lines: [{ product_id: 'massage', unit_price_minor: 0, service_pass_id: 'pass-1', pass_item: 0, staff_id: 'e-yaw' }] })
})
