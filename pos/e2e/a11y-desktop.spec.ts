import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * Accessibility (WCAG 2.2 AA, automated part) of the till's main screens, and the desktop app's silent receipt
 * printing through its hardware bridge (mocked here; the real one is the Tauri app).
 */
const grant = (pin: string) => {
  const salt = randomBytes(16)
  return { v: 1, id: 'g-efua', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-efua', user_name: 'Efua', username: 'efua',
    issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
    pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync(pin, salt, 1000, 32, 'sha256').toString('base64') }, sig: 's' }
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Efua', opening_float_minor: 0, expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = {
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: 'C0001', vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null }, till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main store' },
  device: { id: 'dev-1', name: 'Till 1', receipt_series: 'A' }, user: { id: 'u-efua', name: 'Efua' },
  price_lists: [{ id: 'pl', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(), send_receipt: ['sms', 'email'],
}
const catalogBody = { version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], grocery: null, pharmacy: null, restaurant: null, electronics: false, fashion: null, services: null,
  products: [{ id: 'milo', sku: 'MILO', name: 'Milo 400g', category_id: null, track_stock: true, on_hand: '40.000', tax: [],
    units: [{ id: 'u-milo', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }],
    prices: [{ price_list_id: 'pl', product_uom_id: 'u-milo', min_qty: '1', amount_minor: 5800, starts_at: null, ends_at: null }] }] }

async function serve(page: Page, opts: { practice?: boolean; problems?: unknown[] } = {}) {
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 't-efua', expires_at: null, user: { id: 'u-efua', name: 'Efua' }, offline_grant: grant('482613') })
      case 'GET pos/bootstrap': return json(route, 200, opts.practice ? { ...bootstrap, company: { ...bootstrap.company, name: 'Demo Ltd — PRACTICE', practice: true } } : bootstrap)
      case 'POST pos/problems': opts.problems?.push(req.postDataJSON()); return json(route, 201, { number: 'SUP-HQ-000001', sent: true })
      case 'GET pos/catalog': return json(route, 200, catalogBody)
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: 'HQ-T01A-000001', device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Efua', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, change_minor: 0, tax_breakdown: [],
          lines: [{ line_no: 1, sku: 'MILO', name: 'Milo 400g', uom: 'PC', qty: '1.000', unit_price_minor: 5800, line_total_minor: 5800 }],
          payments: [{ method: 'cash', amount_minor: 5800, tendered_minor: 5800, reference: null }], kind: 'sale', discount_minor: 0, licence_keys: [],
          fiscal: { status: 'submitted', code: 'GRA-77', qr: 'https://evat.example/77', mode: 'realtime' } } })
      }
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
}

async function axe(page: Page, what: string) {
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()
  const bad = r.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
  expect(bad.map((v) => `${what}: ${v.id} — ${v.help} (${v.nodes.map((n) => `${n.html} ${n.failureSummary ?? ''}`).slice(0, 3).join('; ')})`)).toEqual([])
}

async function signInAndSell(page: Page) {
  await page.goto('/')
  await axe(page, 'Enrol')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await expect(page.getByLabel('Username')).toBeVisible()
  await axe(page, 'Sign in')
  await page.getByLabel('Username').fill('efua')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  await page.getByLabel('Scan or search').fill('milo')
  await page.getByRole('button', { name: /Milo 400g/ }).click()
  await axe(page, 'Sell')
  await page.keyboard.press('F12')
  await expect(page.getByRole('dialog', { name: 'Payment' })).toBeVisible()
  await axe(page, 'Payment')
  await page.getByRole('dialog', { name: 'Payment' }).getByRole('button', { name: 'Add cash' }).click()
  await expect(page.getByText('Receipt HQ-T01A-000001')).toBeVisible()
}

test('the main till screens pass the automated WCAG 2.2 AA checks, in light and dark', async ({ page }) => {
  await serve(page)
  await signInAndSell(page)
  await expect(page.getByText('GRA E-VAT GRA-77')).toBeVisible()
  await axe(page, 'Receipt')
  await page.emulateMedia({ colorScheme: 'dark' })
  await axe(page, 'Receipt (dark)')
})

test('the desktop till prints the receipt silently on the receipt printer and opens the drawer after cash', async ({ page }) => {
  await page.addInitScript(() => {
    const calls: { cmd: string; args?: Record<string, unknown> }[] = []
    ;(window as unknown as { __calls: typeof calls }).__calls = calls
    ;(window as unknown as { __TAURI__: unknown }).__TAURI__ = { core: { invoke: async (cmd: string, args?: Record<string, unknown>) => {
      calls.push({ cmd, args })
      if (cmd === 'get_config') return { printer: 'EPSON TM-T20III', drawer: true, scale_port: null }
      return null
    } } }
  })
  await serve(page)
  await signInAndSell(page)
  await expect(page.getByRole('button', { name: 'Printer and scale set-up' })).toBeVisible()
  await page.getByRole('button', { name: 'Print receipt' }).click()
  await expect.poll(async () => page.evaluate(() => (window as unknown as { __calls: { cmd: string }[] }).__calls.map((c) => c.cmd))).toContain('print_raw')
  const call = await page.evaluate(() => (window as unknown as { __calls: { cmd: string; args: { data: string; openDrawer: boolean } }[] }).__calls.find((c) => c.cmd === 'print_raw')!.args)
  const text = Buffer.from(call.data, 'base64').toString('latin1')
  expect(call.openDrawer).toBe(true)
  expect(text).toContain('Receipt HQ-T01A-000001')
  expect(text).toContain('GRA E-VAT GRA-77')
})

test('a practice till says PRACTICE, and a cashier can report a problem with the screen and the recent errors', async ({ page }) => {
  const problems: { message: string; screen: string; context: { screen: string; online: boolean; errors: unknown[] } }[] = []
  await serve(page, { practice: true, problems })
  await signInAndSell(page)
  await expect(page.getByText(/PRACTICE — nothing here is real/)).toBeVisible()
  await axe(page, 'Receipt (practice)')
  await page.getByRole('button', { name: 'Report a problem' }).click()
  const dialog = page.getByRole('dialog', { name: 'Report a problem' })
  await expect(dialog).toBeVisible()
  await axe(page, 'Report a problem')
  await expect(dialog.getByRole('button', { name: 'Send' })).toBeDisabled()
  await dialog.getByLabel('Describe it').fill('The receipt printer prints blank paper.')
  await dialog.getByRole('button', { name: 'Send' }).click()
  await expect(dialog.getByText('Thank you — problem SUP-HQ-000001 is recorded and support has been told.')).toBeVisible()
  expect(problems).toHaveLength(1)
  expect(problems[0]).toMatchObject({ message: 'The receipt printer prints blank paper.', screen: 'receipt', context: { screen: 'receipt', online: true } })
  await dialog.getByRole('button', { name: 'Close' }).click()
  await expect(dialog).toBeHidden()
})
