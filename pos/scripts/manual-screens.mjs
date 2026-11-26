// Screenshots for the installation guide (docs/manual/img/*.png), taken from the LOCAL development server.
// Run from the pos folder with the dev server on port 8010: npm run manual:screens
// The back-office shots sign in with the development owner from the git-ignored .local-secrets/dev-owner.txt
// (local test credentials only; nothing is printed). The till shots use made-up data (the API is answered here).
// Since part 43 the back office is the new one only: each picture's page is found by the same map the server uses
// (server/resources/office-routes.json), from the page it showed before.
import { createHmac } from 'node:crypto'
import { readFileSync, mkdirSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

const here = dirname(fileURLToPath(import.meta.url))
const out = resolve(here, '../../docs/manual/img')
mkdirSync(out, { recursive: true })
const base = process.env.MANUAL_BASE ?? 'http://localhost:8010'
const secrets = Object.fromEntries(readFileSync(resolve(here, '../../.local-secrets/dev-owner.txt'), 'utf8').split(/\r?\n/)
  .map((l) => l.match(/^([a-z_]+):\s*(.+)$/)).filter(Boolean).map((m) => [m[1], m[2].trim()]))
const ROUTES = Object.fromEntries(JSON.parse(readFileSync(resolve(here, '../../server/resources/office-routes.json'), 'utf8')).routes.map((r) => [r.admin, r.office]))

/** The new back office's page for a page of the old one (its query kept). */
function office(adminPath) {
  const [path, query] = adminPath.split('?')
  const to = ROUTES[path]
  if (!to) throw new Error(`No page in the new back office for ${path}: run npm run office:routes`)
  return to + (query ? `?${query}` : '')
}

/** RFC 6238 code from a base32 secret (what an authenticator app shows). */
function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0')
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)))
  const h = createHmac('sha1', key).update(counter).digest()
  const o = h[h.length - 1] & 15
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).padStart(6, '0')
}

const browser = await chromium.launch()
const shot = (page, name) => page.screenshot({ path: resolve(out, `${name}.png`) })

// --- Till: enrolment screen (real server, fresh device) -----------------------------------------------------------------
{
  const page = await browser.newPage({ viewport: { width: 1280, height: 720 } })
  await page.goto(`${base}/pos/`)
  await page.getByRole('button', { name: 'Enrol this device' }).waitFor()
  await shot(page, 'till-enrol')
  await page.close()
}

// --- Back office -----------------------------------------------------------------------------------------------------
{
  const page = await browser.newPage({ viewport: { width: 1280, height: 760 }, colorScheme: 'light' })
  // The guide's pictures are in the light look, whatever this account chose (only this browser's copy; nothing is saved).
  await page.route('**/office-api/me', async (route) => {
    const res = await route.fetch()
    const me = await res.json()
    if (me?.prefs) me.prefs.theme = 'light'
    await route.fulfill({ response: res, json: me })
  })
  await page.goto(`${base}/office/sign-in`)
  await page.locator('input[autocomplete=username]').waitFor()
  await page.waitForTimeout(400)
  await shot(page, 'sign-in')
  await page.locator('input[autocomplete=username]').fill(secrets.email)
  await page.locator('input[type=password]').fill(secrets.password)
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  const code = page.locator('input[autocomplete="one-time-code"], input[inputmode="numeric"]').first()
  await code.waitFor({ timeout: 15000 })
  await code.fill(totp(secrets.totp_secret))
  await code.press('Enter')
  await page.waitForURL(/\/office\/(?!sign-in)/, { timeout: 20000 })
  const chooseLook = async (look) => page.evaluate(async (l) => { // the person's menu: across the top or at the side
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '')
    await fetch('/office-api/prefs', { method: 'POST', credentials: 'same-origin', body: JSON.stringify({ office_look: l }),
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } })
  }, look)
  await chooseLook('top') // a run stopped half-way may have left this account on the side menu

  for (const [path, name, action] of [
    ['/admin/devices', 'devices', null],
    ['/admin/devices/create', 'device-new', null],
    ['/admin/manage-modules', 'modules', null],
    ['/admin/manage-settings', 'settings', null],
    ['/admin/licence', 'licence', null],
    ['/admin/import-data?type=products', 'import-data', null],
    ['/admin/stock-adjustments/create', 'stock-adjustment-new', null],
    ['/admin/products', 'export-menu', 'Export'],
    ['/admin/insights?tab=forecast', 'insights-forecast', null],
    ['/admin/alerts', 'insights-alerts', null],
    ['/admin/copilot', 'copilot', null],
    ['/admin/messaging', 'messaging-setup', null],
    ['/admin/report-builder', 'report-builder', null],
    ['/admin/backups', 'backups', null],
    ['/admin/health', 'health', null],
    ['/admin/go-live', 'go-live', null],
    ['/admin/branding', 'branding', null],
    ['/admin/import-data?type=opening_balances&source=quickbooks', 'import-migration', null],
    ['/admin/cash-setup', 'cash-setup', null],
    ['/admin/sales-channels', 'sales-channels', null],
    ['/admin/gra-evat', 'gra-evat', null],
    ['/admin/stock-counts', 'stock-counts', null],
    ['/admin/reorder-suggestions', 'reorder-suggestions', null],
    ['/admin/suppliers/create', 'supplier-new', null],
    ['/admin/landed-costs', 'landed-costs', null],
    ['/admin/rfqs/create', 'rfq-new', null],
    ['/admin/supplier-invoices', 'supplier-invoices', null],
    ['/admin/purchase-requests/create', 'purchase-request-new', null],
    ['/admin/budgets', 'budgets', null],
    ['/admin/purchase-agreements/create', 'agreement-new', null],
    ['/admin/spend-analytics', 'spend-analytics', null],
    ['/admin/purchase-orders/create', 'purchase-order-new', null],
    ['/admin/expiry-returns', 'expiry-returns', null],
    ['/admin/pricing-rules', 'pricing-rules', null],
    ['/admin/crm-pipeline', 'crm-pipeline', null],
    ['/admin/crm-leads', 'crm-leads', null],
    ['/admin/crm-campaigns/create', 'crm-campaign-new', null],
    ['/admin/projects', 'projects', null],
    ['/admin/my-time', 'my-time', null],
    ['/admin/tickets', 'tickets', null],
    ['/admin/equipment', 'equipment', null],
    ['/admin/sensors', 'sensors', null],
    ['/admin/temperature-log', 'temperature-log', null],
  ]) {
    await page.goto(base + office(path))
    await page.waitForLoadState('networkidle')
    await page.waitForTimeout(600) // the page's own data
    if (action) {
      await page.getByRole('button', { name: action }).first().click()
      await page.getByRole('menuitem', { name: /Export to Excel/ }).first().waitFor()
      await page.waitForTimeout(400)
    }
    if (name === 'licence') { // show a placeholder instead of this machine's own installation code
      const hidden = await page.evaluate(() => {
        let n = 0
        for (const el of document.querySelectorAll('textarea, input, code, pre, span, div')) {
          if (el.children.length) continue
          if ((el.value ?? '').startsWith('SYNREQ-')) { el.value = 'SYNREQ-…  (your own installation code appears here)'; n++ }
          else if (!('value' in el) && el.textContent.startsWith('SYNREQ-')) { el.textContent = 'SYNREQ-…  (your own installation code appears here)'; n++ }
        }
        return n
      })
      if (!hidden) throw new Error('Licence page: installation code not found to hide; not taking the screenshot')
    }
    await shot(page, name)
    if (action) await page.keyboard.press('Escape')
  }

  // My profile: the look, then the preferences.
  await page.goto(`${base}/office/profile`)
  await page.waitForLoadState('networkidle')
  await page.locator('#profile-look').scrollIntoViewIfNeeded()
  await page.waitForTimeout(300)
  await shot(page, 'profile-look')
  await page.locator('#profile-prefs').scrollIntoViewIfNeeded()
  await page.waitForTimeout(300)
  await shot(page, 'profile-prefs')
  // Home: its panels load just after the page; then the same Home being arranged.
  await page.goto(`${base}/office/`)
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(1500)
  await shot(page, 'dashboard')
  const arrange = page.getByRole('button', { name: /Personali[sz]e|Arrange|Layout/ }).first()
  if (await arrange.count()) {
    await arrange.click()
    await page.waitForTimeout(800)
    await shot(page, 'dashboard-arrange')
    await page.keyboard.press('Escape')
  }
  // The looks: the menu across the top (an area open), the search, then the side menu.
  await page.goto(base + office('/admin/purchase-orders'))
  await page.waitForLoadState('networkidle')
  await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /^Purchasing/ }).first().click()
  await page.waitForTimeout(400)
  await shot(page, 'look-tabs')
  await page.keyboard.press('Escape')
  await page.keyboard.press('Control+k')
  await page.keyboard.type('order')
  await page.waitForTimeout(400)
  await shot(page, 'look-finder')
  await page.keyboard.press('Escape')
  await chooseLook('side')
  await page.goto(base + office('/admin/purchase-orders'))
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(600)
  await shot(page, 'look-side')
  await chooseLook('top')
  await page.close()
}
await browser.close()
console.log(`Screenshots written to ${out}`)
