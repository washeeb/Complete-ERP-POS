import { expect, test, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/** Clock in at the till with username + PIN (online), and clock out while the server is down (queued with the offline sign-in). */

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

test('clocks in online and out offline from the sign-in screen', async ({ page }) => {
  const s = { up: true, clocks: [] as Record<string, unknown>[], queued: [] as Record<string, unknown>[] }
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => (s.up ? r.fulfill({ status: 200, body: 'ok' }) : r.fulfill({ status: 502, body: 'down' })))
  await page.route('**/api/v1/**', async (route) => {
    if (!s.up) return route.fulfill({ status: 502, body: 'down' })
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 't-esi', expires_at: null, user: { id: 'u-esi', name: 'Esi' }, offline_grant: grant('482613') })
      case 'POST pos/logout': return json(route, 200, { ok: true })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap)
      case 'GET pos/catalog': return json(route, 200, { version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], products: [] })
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
      case 'POST pos/clock': { const b = req.postDataJSON(); s.clocks.push(b); return json(route, 200, { status: 'accepted', name: 'Esi', kind: b.kind, at: new Date().toISOString() }) }
      case 'POST pos/sync': { const b = JSON.parse(req.postData() ?? '{}'); s.queued.push(...b.events); return json(route, 200, { results: b.events.map((e: { id: string }) => ({ id: e.id, status: 'accepted' })) }) }
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })

  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()

  // Clock in (online): no sign-in, just username + PIN.
  await page.getByLabel('Username').fill('esi')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'Clock in' }).click()
  await expect(page.getByText(/Esi clocked in at \d\d:\d\d\./)).toBeVisible()
  expect(s.clocks[0]).toMatchObject({ username: 'esi', pin: '482613', kind: 'in' })

  // Sign in once so this till holds Esi's offline sign-in, then sign out.
  await page.getByLabel('Username').fill('esi')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'OK' }).click()
  await page.getByRole('button', { name: 'Sign out' }).click()

  // Server down: clock out is checked against the offline sign-in and queued.
  s.up = false
  await page.getByLabel('Username').fill('esi')
  await page.getByLabel('PIN', { exact: true }).fill('000000')
  await page.getByRole('button', { name: 'Clock out' }).click()
  await expect(page.getByRole('alert')).toBeVisible()
  await page.getByLabel('Username').fill('esi')
  await page.getByLabel('PIN', { exact: true }).fill('482613')
  await page.getByRole('button', { name: 'Clock out' }).click()
  await expect(page.getByText(/Esi clocked out at .*saved on this till/)).toBeVisible()
  s.up = true
  await expect.poll(() => s.queued.filter((e) => (e as { type: string }).type === 'clock').length, { timeout: 30_000 }).toBe(1)
  expect((s.queued[0] as { payload: { kind: string } }).payload.kind).toBe('out')
})
