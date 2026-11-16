import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * Staff cards at the till (owner request 2026-11-15): the scanner types the card's code and Enter. By default the PIN is
 * then typed; where the company lets the card alone sign in, it signs in straight away, offline too.
 */

const CARD = 'SLB1-ABCDEFGHJKMNPQ'
const grant = (pin: string, cardOnly: boolean) => {
  const salt = randomBytes(16)
  return { v: 1, id: 'g-esi', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-esi', user_name: 'Esi', username: 'esi',
    issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(), card_only: cardOnly,
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

test('a scanned card asks for the PIN by default, and signs in alone where the company allows it, offline too', async ({ page }) => {
  const s = { up: true, cardOnly: false, logins: [] as { username: string; pin: string }[] }
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => (s.up ? r.fulfill({ status: 200, body: 'ok' }) : r.fulfill({ status: 502, body: 'down' })))
  await page.route('**/api/v1/**', async (route) => {
    if (!s.up) return route.fulfill({ status: 502, body: 'down' })
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': {
        const b = req.postDataJSON()
        s.logins.push(b)
        if (b.pin === '' && !s.cardOnly) return json(route, 422, { error: 'pin_required', detail: 'Type your PIN.' })
        return json(route, 200, { token: 't-esi', expires_at: null, user: { id: 'u-esi', name: 'Esi' }, offline_grant: b.pin ? grant('482613', s.cardOnly) : null })
      }
      case 'POST pos/logout': return json(route, 200, { ok: true })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, { version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], products: [] })
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })

  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()

  // Default: the card, then the PIN.
  const who = page.getByLabel('Username, or scan your staff card')
  await who.fill(CARD)
  await who.press('Enter')
  await expect(page.getByText('Staff card scanned. Type your PIN.')).toBeVisible()
  await expect(page.getByLabel('PIN', { exact: true })).toBeFocused()
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
  expect(s.logins.map((l) => l.pin)).toEqual(['', '482613'])
  await page.getByRole('button', { name: 'Sign out' }).click()

  // The company lets the card alone sign in: scanned, signed in.
  s.cardOnly = true
  await who.fill(CARD)
  await who.press('Enter')
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
  await page.getByRole('button', { name: 'Sign out' }).click()

  // A PIN sign-in while card-only is allowed saves a grant that lets the card alone in when the server is away.
  await who.fill(CARD)
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
  await page.getByRole('button', { name: 'Sign out' }).click()
  s.up = false
  await who.fill(CARD)
  await who.press('Enter')
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
})
