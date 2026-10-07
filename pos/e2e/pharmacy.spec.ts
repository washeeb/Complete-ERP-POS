import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** Pharmacy at the till: a prescription-only medicine needs the patient, the prescription and a pharmacist's approval. */

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
const pc = (id: string, barcode: string) => [{ id, uom_code: 'PC', uom_name: 'Pack', decimals: 0, factor: '1.000000', is_base: true, barcodes: [barcode] }]
const price = (unit: string, amount: number) => [{ price_list_id: 'pl-retail', product_uom_id: unit, min_qty: '1', amount_minor: amount, starts_at: null, ends_at: null }]
const catalogBody = {
  version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], grocery: null,
  pharmacy: { interactions: [{ ingredient_a: 'paracetamol', ingredient_b: 'warfarin', severity: 'moderate', note: 'Check INR' }] },
  products: [
    { id: 'p-amx', sku: 'AMX', name: 'Amoxil 500mg', category_id: null, track_stock: true, on_hand: '50.000', tax: [], units: pc('u-amx', '7001'), prices: price('u-amx', 2500),
      drug: { schedule: 'pom', generic_name: 'amoxicillin', strength: '500 mg', dosage_form: 'Capsule', active_ingredients: ['amoxicillin'] } },
    { id: 'p-amg', sku: 'AMG', name: 'Amoxicillin 500mg (generic)', category_id: null, track_stock: true, on_hand: '50.000', tax: [], units: pc('u-amg', '7002'), prices: price('u-amg', 1500),
      drug: { schedule: 'pom', generic_name: 'Amoxicillin', strength: '500mg', dosage_form: 'Capsule', active_ingredients: ['amoxicillin'] } },
    { id: 'p-pcm', sku: 'PCM', name: 'Paracetamol 500mg', category_id: null, track_stock: true, on_hand: '50.000', tax: [], units: pc('u-pcm', '7003'), prices: price('u-pcm', 500),
      drug: { schedule: 'otc', generic_name: 'paracetamol', strength: '500 mg', dosage_form: 'Tablet', active_ingredients: ['paracetamol'] } },
  ],
}
const ama = { id: 'c-ama', code: 'CUS-1', name: 'Ama Owusu', phone: '0241112222', price_list_id: null, credit_limit_minor: null, credit_hold: false, balance_minor: 0,
  available_minor: null, overdue_minor: 0, points: 0, allergies: ['penicillin', 'amoxicillin'], nhis_no: 'NHIS-1', nhis_expires_on: null }

test('asks for the prescription and the pharmacist, warns about allergies, and offers the generic', async ({ page }) => {
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
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [ama] })
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        s.sales.push(b)
        if (b.pharmacist_approval?.pin !== '771204') return json(route, 422, { error: 'approval_failed', message: 'Supervisor username or PIN is wrong.' })
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: 'HQ-T01A-000001', device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Esi', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [], lines: [], payments: [],
          kind: 'sale', discount_minor: 0 } })
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
  await scan.fill('7001'); await scan.press('Enter')
  await scan.fill('7003'); await scan.press('Enter')
  await page.keyboard.press('F12')

  const rx = page.getByRole('dialog', { name: 'Prescription' })
  await expect(rx).toBeVisible()
  await rx.getByRole('button', { name: 'Continue to payment' }).click()
  await expect(rx.getByText('Choose the patient.')).toBeVisible()
  await rx.getByRole('button', { name: 'Choose patient' }).click()
  await page.getByLabel('Find customer').fill('Ama')
  await page.getByRole('button', { name: /Ama Owusu/ }).click()
  await expect(rx.getByRole('alert')).toContainText('Amoxil 500mg: patient is allergic to amoxicillin.')

  // The generic swaps in (same ingredient, so the allergy warning stays).
  await rx.getByLabel('Generic for Amoxil 500mg').selectOption('p-amg')
  await expect(rx.getByText('Amoxicillin 500mg (generic)', { exact: true })).toBeVisible()
  await rx.getByLabel('Prescriber').fill('Dr. K. Mensah')
  await rx.getByLabel('Pharmacist username').fill('kwame')
  await rx.getByLabel('Pharmacist PIN').fill('000000')
  await rx.getByRole('button', { name: 'Dispense despite warnings' }).click()
  await page.getByRole('button', { name: /GH₵ 20\.00/ }).first().click()

  // Wrong PIN: back to the prescription with the server's message; the details are kept.
  await expect(rx.getByText('Supervisor username or PIN is wrong.')).toBeVisible()
  await expect(rx.getByLabel('Prescriber')).toHaveValue('Dr. K. Mensah')
  await rx.getByLabel('Pharmacist username').fill('kwame')
  await rx.getByLabel('Pharmacist PIN').fill('771204')
  await rx.getByRole('button', { name: 'Dispense despite warnings' }).click()
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
  expect(s.sales[1]).toMatchObject({ customer_id: 'c-ama', pharmacist_approval: { username: 'kwame', pin: '771204' },
    prescription: { prescriber_name: 'Dr. K. Mensah' }, lines: [{ product_id: 'p-pcm' }, { product_id: 'p-amg' }] })
})
