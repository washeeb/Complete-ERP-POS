import { expect, test, type Page, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * Every till window fits the screen with its buttons in view, whatever the way to pay (owner 2026-11-28: on the payment
 * window only Cash fitted; the other ways to pay pushed Add and Back out of the window). Three screen sizes: a usual
 * till, a short laptop and a small till screen.
 */

const salt = randomBytes(16)
const grant = {
  v: 1, id: 'g1', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-esi', user_name: 'Esi Cashier', username: 'esi',
  issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
  pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync('482613', salt, 1000, 32, 'sha256').toString('base64') },
  sig: 'server-signature',
}
const catalog = {
  version: 'v1', generated_at: new Date().toISOString(),
  products: [{
    id: 'p-milo', sku: 'MILO-400', name: 'Milo 400g tin', track_stock: true, on_hand: '10.000', tax: [{ code: 'VAT', name: 'VAT', rate_bp: 1500 }],
    units: [{ id: 'pu-milo', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: ['200000900'] }],
    prices: [{ price_list_id: 'pl-retail', product_uom_id: 'pu-milo', min_qty: '1.000', amount_minor: 5000, starts_at: null, ends_at: null }],
  }],
}
const shift = { id: 'shift-1', status: 'open', opened_at: new Date().toISOString(), opened_by: 'Esi Cashier', opening_float_minor: 0,
  expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
const bootstrap = {
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null },
  till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' }, device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' },
  user: { id: 'u-esi', name: 'Esi Cashier' },
  price_lists: [{ id: 'pl-retail', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(), momo_confirm: 'both',
}


const fits = async (page: Page, what: string) => {
  const r = await check(page)
  expect(r.buttons.length, what).toBeGreaterThan(0)
  expect(r.bad, `${what}: buttons out of view or covered`).toEqual([])
}
/** The window on top: its bottom buttons, and those not fully on screen or covered by something. */
const check = (page: Page) => page.evaluate(() => {
  const dlg = [...document.querySelectorAll('[role=dialog]')].pop() as HTMLElement
  const card = dlg.querySelector('.card') as HTMLElement
  const btns = [...card.querySelectorAll(':scope > .row:last-child button, :scope > .pay-foot button')] as HTMLElement[]
  const bad = btns.filter((b) => { const r = b.getBoundingClientRect(); const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)
    return r.bottom > innerHeight || r.top < 0 || !(top && (b === top || b.contains(top))) }).map((b) => b.textContent)
  return { name: dlg.getAttribute('aria-label'), buttons: btns.map((b) => b.textContent), bad, cardBottom: Math.round(card.getBoundingClientRect().bottom), vh: innerHeight }
})
for (const [w, h] of [[1366, 768], [1280, 650], [1024, 600]]) test(`every till window keeps its buttons in view at ${w}×${h}`, async ({ page }) => {
  await page.setViewportSize({ width: w, height: h })
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request(); const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 'staff-token', expires_at: null, user: { id: 'u-esi', name: 'Esi Cashier' }, offline_grant: grant })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, catalog)
      case 'GET pos/customers': return json(route, 200, { version: 'c1', customers: [{ id: 'c1', name: 'Kofi Mensah', phone: '0241234567', points: 120, credit_limit_minor: 100000, balance_minor: 0 }] })
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
  await scan.fill('200000900'); await scan.press('Enter')
  const pay = page.getByRole('dialog', { name: 'Payment' })
  for (const round of ['no customer', 'customer']) {
    if (round === 'customer') {
      await page.keyboard.press('F4'); await page.waitForTimeout(300)
      await fits(page, 'customer picker')
      await page.getByRole('dialog').getByText('Kofi Mensah').first().click()
    }
    await page.keyboard.press('F12')
    if (round === 'customer') await expect(pay.getByRole('tab', { name: 'On account' })).toBeVisible() // all seven ways to pay
    for (const t of await pay.getByRole('tab').allInnerTexts()) {
      await pay.getByRole('tab', { name: t.trim(), exact: true }).click(); await page.waitForTimeout(120)
      await fits(page, `${round}: ${t.trim()}`)
    }
    await page.keyboard.press('Escape')
  }
  for (const [key, label] of [['F1', 'calc'], ['F8', 'return']]) {
    await page.keyboard.press(key); await page.waitForTimeout(300)
    await fits(page, label)
    await page.keyboard.press('Escape'); await page.waitForTimeout(200)
  }
  await page.getByRole('button', { name: 'Drawer' }).click(); await page.waitForTimeout(300)
  await fits(page, 'drawer')
})
