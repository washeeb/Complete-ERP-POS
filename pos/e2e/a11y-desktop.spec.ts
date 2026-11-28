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
  categories: [{ id: 'c-drinks', name: 'Drinks' }, { id: 'c-snacks', name: 'Snacks' }], favourites: ['plantain'],
  products: [{ id: 'plantain', sku: 'CHIPS', name: 'Plantain chips', category_id: 'c-snacks', track_stock: false, on_hand: null, tax: [],
    units: [{ id: 'u-chips', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }],
    prices: [{ price_list_id: 'pl', product_uom_id: 'u-chips', min_qty: '1', amount_minor: 1200, starts_at: null, ends_at: null }] },
  { id: 'milo', sku: 'MILO', name: 'Milo 400g', category_id: 'c-drinks', track_stock: true, on_hand: '40.000', tax: [],
    units: [{ id: 'u-milo', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }],
    prices: [{ price_list_id: 'pl', product_uom_id: 'u-milo', min_qty: '1', amount_minor: 5800, starts_at: null, ends_at: null }] }] }

async function serve(page: Page, opts: { practice?: boolean; problems?: unknown[]; brand?: boolean } = {}) {
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  // The company logo (Company → Branding), as the server serves it.
  await page.route('**/brand/demo/logo*', (r) => r.fulfill({ status: 200, contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><rect width="300" height="100" fill="#fff"/><rect x="10" y="10" width="80" height="80" fill="#B45309"/><rect x="110" y="40" width="180" height="20" fill="#111"/></svg>' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 't-efua', expires_at: null, user: { id: 'u-efua', name: 'Efua' }, offline_grant: grant('482613') })
      case 'GET pos/bootstrap': {
        const brand = opts.brand ? { brand: { color: '#B45309', on_color: '#FFFFFF', logo_url: '/brand/demo/logo?v=1', logo_dark_url: '/brand/demo/logo?v=1', icon_url: '/brand/demo/logo?v=1',
          receipt_logo: true, powered_by: 'ZionEX · Powered by SES Labs', product: 'ZionEX' } } : {}
        return json(route, 200, opts.practice ? { ...bootstrap, ...brand, company: { ...bootstrap.company, name: 'Demo Ltd — PRACTICE', practice: true } } : { ...bootstrap, ...brand })
      }
      case 'POST pos/problems': opts.problems?.push(req.postDataJSON()); return json(route, 201, { number: 'SUP-HQ-000001', sent: true })
      case 'GET pos/catalog': return json(route, 200, catalogBody)
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
      case 'POST pos/sales': {
        const b = req.postDataJSON()
        return json(route, 201, { replayed: false, sale: { id: b.id, receipt_no: 'HQ-T01A-000001', device_seq: b.device_seq, status: 'posted', occurred_at: b.occurred_at,
          cashier: 'Efua', currency: 'GHS', prices_include_tax: true, ...b.totals, paid_minor: b.totals.total_minor, tax_breakdown: [],
          change_minor: Math.max(0, (b.payments ?? []).reduce((t: number, p: { tendered_minor?: number; amount_minor: number }) => t + (p.tendered_minor ?? p.amount_minor), 0) - b.totals.total_minor),
          lines: [{ line_no: 1, sku: 'MILO', name: 'Milo 400g', uom: 'PC', qty: '1.000', unit_price_minor: 5800, line_total_minor: 5800 }],
          payments: [{ method: 'cash', amount_minor: 5800, tendered_minor: 5800, reference: null }], kind: 'sale', discount_minor: 0, licence_keys: [],
          fiscal: { status: 'submitted', code: 'GRA-77', qr: 'https://evat.example/77', mode: 'realtime' } } })
      }
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
}

async function axe(page: Page, what: string) {
  // Fluent UI's focus helpers (Tabster "dummy" elements around arrow-key groups such as the category tabs) are hidden,
  // move focus on at once and hold no content: not something a cashier can land on or read, so axe leaves them out.
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).exclude('[data-tabster-dummy]').analyze()
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

// Every main screen in dark too, with the company's brand on (owner report 2026-11-27: text hard to read in dark), and
// the screens a cashier opens through the day: the drawer and the till settings.
for (const scheme of ['dark', 'light'] as const) {
  test(`the till's daily screens read in ${scheme} with the company's brand`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme })
    await serve(page, { brand: true })
    await signInAndSell(page)
    await axe(page, `Receipt (${scheme})`)
    await page.getByRole('button', { name: 'New sale' }).click()
    await page.getByRole('button', { name: 'Drawer', exact: true }).click()
    await expect(page.getByRole('dialog', { name: 'Drawer' })).toBeVisible()
    await axe(page, `Drawer (${scheme})`)
    await page.keyboard.press('Escape')
    await page.getByRole('button', { name: 'Till settings' }).click()
    await expect(page.getByRole('heading', { name: 'Till settings' })).toBeVisible()
    await axe(page, `Till settings (${scheme})`)
  })
}

test('sells from the product tiles: a category, then a tile; typing shows the search list', async ({ page }) => {
  await serve(page)
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('efua')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  const tabs = page.getByRole('tablist', { name: 'Product categories' })
  // The branch's favourites first (Sales → Till favourites), then the categories of what this till sells, in name order.
  await expect(tabs.getByRole('tab')).toHaveCount(3)
  await expect(tabs.getByRole('tab').nth(0)).toHaveAccessibleName('Favourites')
  await expect(tabs.getByRole('tab').nth(0)).toHaveAttribute('aria-selected', 'true')
  await expect(page.getByRole('group', { name: 'Products' }).getByRole('button')).toHaveCount(1)
  await expect(page.getByRole('group', { name: 'Products' }).getByRole('button')).toHaveAccessibleName('Plantain chips, GH₵ 12.00')
  await expect(tabs.getByRole('tab').nth(1)).toHaveAccessibleName('Drinks')
  await expect(tabs.getByRole('tab').nth(2)).toHaveAccessibleName('Snacks')
  await tabs.getByRole('tab', { name: 'Snacks' }).click()
  await page.getByRole('group', { name: 'Products' }).getByRole('button', { name: 'Plantain chips, GH₵ 12.00' }).click()
  const cart = page.getByRole('region', { name: 'Cart' })
  await expect(cart.getByText('Plantain chips')).toBeVisible()
  await expect(cart.getByText('GH₵ 12.00').last()).toBeVisible()
  // Typing replaces the tiles with the search list, as before.
  await page.getByLabel('Scan or search').fill('mil')
  await expect(page.getByRole('group', { name: 'Products' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: /Milo 400g/ })).toBeVisible()
})

test('after the sale, the receipt screen says the change to give, large, with New sale first', async ({ page }) => {
  await serve(page)
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('efua')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  await page.getByLabel('Scan or search').fill('milo')
  await page.getByRole('button', { name: /Milo 400g/ }).click()
  await page.keyboard.press('F12')
  const pay = page.getByRole('dialog', { name: 'Payment' })
  await expect(pay.getByRole('tablist', { name: 'Ways to pay' }).getByRole('tab', { name: 'Cash' })).toHaveAttribute('aria-selected', 'true')
  await pay.getByRole('group', { name: 'Cash received: quick amounts' }).getByRole('button', { name: 'GH₵ 60.00' }).click()
  const done = page.getByRole('region', { name: 'Sale complete' })
  await expect(done.getByText('Change to give')).toBeVisible()
  await expect(done.getByText('GH₵ 2.00')).toBeVisible()
  await expect(done.getByRole('button', { name: 'New sale' })).toBeFocused() // Enter starts the next customer
})

test('a till set to the search list (Till settings → Selling screen) shows no tiles', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('till-prefs', JSON.stringify({ layout: 'list' })))
  await serve(page)
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('efua')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByLabel('Scan or search')).toBeVisible()
  await expect(page.getByRole('tablist', { name: 'Product categories' })).toHaveCount(0)
  await expect(page.getByRole('group', { name: 'Products' })).toHaveCount(0)
})

test('the desktop till prints the receipt by itself, silently, on the receipt printer and opens the drawer after cash', async ({ page }) => {
  await page.addInitScript(() => {
    const calls: { cmd: string; args?: Record<string, unknown> }[] = []
    ;(window as unknown as { __calls: typeof calls }).__calls = calls
    ;(window as unknown as { __TAURI__: unknown }).__TAURI__ = { core: { invoke: async (cmd: string, args?: Record<string, unknown>) => {
      calls.push({ cmd, args })
      if (cmd === 'get_config') return { printer: 'EPSON TM-T20III', drawer: true, scale_port: null }
      return null
    } } }
  })
  await serve(page, { brand: true })
  await signInAndSell(page)
  await expect(page.getByRole('img', { name: 'Demo Ltd' })).toBeVisible() // the company logo on the receipt
  await expect(page.getByText('ZionEX · Powered by SES Labs')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Printer and scale set-up' })).toBeVisible()
  // It prints by itself, without the cashier pressing Print receipt (setting on by default).
  await expect.poll(async () => page.evaluate(() => (window as unknown as { __calls: { cmd: string }[] }).__calls.map((c) => c.cmd))).toContain('print_raw')
  const call = await page.evaluate(() => (window as unknown as { __calls: { cmd: string; args: { data: string; openDrawer: boolean } }[] }).__calls.find((c) => c.cmd === 'print_raw')!.args)
  const text = Buffer.from(call.data, 'base64').toString('latin1')
  expect(call.openDrawer).toBe(true)
  expect(text).toContain('Receipt HQ-T01A-000001')
  expect(text).toContain('GRA E-VAT GRA-77')
  expect(text).toContain('ZionEX \u00fa Powered by SES Labs') // the middle dot is 0xFA in the printer's code page (PC858), read here as Latin-1
  const bytes = Buffer.from(call.data, 'base64')
  const at = bytes.indexOf(Buffer.from([0x1d, 0x76, 0x30, 0x00])) // the logo as a raster image, before the company name
  expect(at).toBeGreaterThan(0)
  expect(at).toBeLessThan(text.indexOf('Demo Ltd'))
  expect(bytes.readUInt16LE(at + 6)).toBeGreaterThan(20) // its height in dots

  // Show print preview: the print window, not the printer.
  await page.evaluate(() => { const w = window as unknown as { __previews: number; print: () => void }; w.__previews = 0; w.print = () => { w.__previews++ } })
  await page.getByRole('button', { name: 'Show print preview' }).click()
  expect(await page.evaluate(() => (window as unknown as { __previews: number }).__previews)).toBe(1)
  expect(await page.evaluate(() => (window as unknown as { __calls: { cmd: string }[] }).__calls.filter((c) => c.cmd === 'print_raw').length)).toBe(1)
})

test('a practice till says PRACTICE, and a cashier can report a problem with the screen and the recent errors', async ({ page }) => {
  const problems: { message: string; screen: string; context: { screen: string; online: boolean; errors: unknown[] } }[] = []
  await serve(page, { practice: true, problems, brand: true }) // branded: the accessibility checks run with the logo and accent
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
