import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * Cash control: the teller opens the till on the vault the cashier issued (its float, confirmed as counted), and closes
 * it with a blind count by notes and coins; the result stays blind (the cashier counts the tray next).
 */

const grant = (pin: string) => {
  const salt = randomBytes(16)
  return { v: 1, id: 'g-esi', tenant_id: 't1', device_id: 'dev-1', till_id: 'till-1', user_id: 'u-esi', user_name: 'Esi', username: 'esi',
    issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
    pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync(pin, salt, 1000, 32, 'sha256').toString('base64') }, sig: 's' }
}
const denominations = [
  { key: 'n200', label: 'GH₵200 note', value: 20000 }, { key: 'n100', label: 'GH₵100 note', value: 10000 }, { key: 'n50', label: 'GH₵50 note', value: 5000 },
  { key: 'n20', label: 'GH₵20 note', value: 2000 }, { key: 'c1', label: 'GH₵1 coin', value: 100 },
]
const bootstrap = (shift: unknown) => ({
  company: { name: 'Demo Ltd', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra', legal_name: null, tin: null, vat_no: null },
  branch: { id: 'b1', code: 'HQ', name: 'Head Office', address: null, phone: null }, till: { id: 'till-1', code: 'T01', name: 'Till 1', warehouse: 'Main' },
  device: { id: 'dev-1', name: 'Front PC', receipt_series: 'A' }, user: { id: 'u-esi', name: 'Esi' },
  price_lists: [{ id: 'pl-retail', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }],
  receipt_footer: 'Thank you', shift, last_device_seq: 0, catalog_version: 'v1', server_time: new Date().toISOString(),
  cash: { vaults: true, denominations, sessions: shift ? [] : [{ id: 'vs-1', vault: 'T01 · Morning', float_minor: 20000, status: 'issued', issued_by: 'Kofi', business_date: '2026-10-12' }] },
})

async function axe(page: Page, what: string) {
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()
  expect(r.violations.map((v) => `${what}: ${v.id} ${v.help}`)).toEqual([])
}

test('opens on the issued vault and closes with a blind count by notes and coins', async ({ page }) => {
  const s: { shift: unknown; opened: Record<string, unknown> | null; closed: Record<string, unknown> | null } = { shift: null, opened: null, closed: null }
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-1', token: 'device-token', kind: 'pos' })
      case 'POST pos/login': return json(route, 200, { token: 't-esi', expires_at: null, user: { id: 'u-esi', name: 'Esi' }, offline_grant: grant('482613') })
      case 'POST pos/logout': return json(route, 200, { ok: true })
      case 'GET pos/bootstrap': return json(route, 200, bootstrap(s.shift))
      case 'GET pos/catalog': return json(route, 200, { version: 'v1', generated_at: new Date().toISOString(), utc_offset_minutes: 0, promotions: [], products: [] })
      case 'GET pos/customers': return json(route, 200, { version: 'c', customers: [] })
      case 'POST pos/shifts/open': {
        s.opened = req.postDataJSON()
        s.shift = { id: (s.opened as { id: string }).id, status: 'open', opened_at: new Date().toISOString(), opened_by: 'Esi', opening_float_minor: 20000,
          expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null }
        return json(route, 201, { shift: s.shift })
      }
      case 'POST pos/shifts/close': {
        s.closed = req.postDataJSON()
        return json(route, 200, { shift: { ...(s.shift as object), status: 'closed', counted_cash_minor: (s.closed as { counted_cash_minor: number }).counted_cash_minor,
          expected_cash_minor: null, over_short_minor: null } })
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

  // Open on the vault: the float is the cashier's, and the teller confirms counting it.
  await expect(page.getByText('T01 · Morning')).toBeVisible()
  await axe(page, 'Open shift on a vault')
  await page.getByLabel(/I have counted the float in the tray/).check()
  await page.getByRole('button', { name: 'Open shift' }).click()
  await expect.poll(() => s.opened).not.toBeNull()
  expect(s.opened).toMatchObject({ vault_session_id: 'vs-1', opening_float_minor: 20000 })

  // Close: count note by note; the running total is the teller's own count; nothing about what is expected.
  await page.getByRole('button', { name: 'Close shift' }).click()
  await page.getByLabel('Number of GH₵200 notes').fill('1')
  await page.getByLabel('Number of GH₵20 notes').fill('3')
  await page.getByLabel('Number of GH₵1 coins').fill('5')
  await expect(page.getByText('Total counted')).toContainText('265.00')
  await axe(page, 'Close shift by notes')
  await page.getByRole('button', { name: 'Close shift' }).click()
  await expect(page.getByText(/hand it to the cashier/)).toBeVisible()
  await expect(page.getByText('Expected')).toHaveCount(0)
  expect(s.closed).toMatchObject({ counted_cash_minor: 26500, denoms: { n200: 1, n20: 3, c1: 5 } })
})
