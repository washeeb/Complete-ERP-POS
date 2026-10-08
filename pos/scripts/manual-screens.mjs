// Screenshots for the installation guide (docs/manual/img/*.png), taken from the LOCAL development server.
// Run from the pos folder with the dev server on port 8010: npm run manual:screens
// The back-office shots sign in with the development owner from the git-ignored .local-secrets/dev-owner.txt
// (local test credentials only; nothing is printed). The till shots use made-up data (the API is answered here).
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
  const page = await browser.newPage({ viewport: { width: 1280, height: 760 } })
  await page.goto(`${base}/admin/login`)
  await page.locator('input[type=email]').waitFor()
  await shot(page, 'admin-login')
  await page.locator('input[type=email]').fill(secrets.email)
  await page.locator('input[type=password]').fill(secrets.password)
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  const code = page.locator('input[autocomplete="one-time-code"], input[inputmode="numeric"]').first()
  await code.waitFor({ timeout: 15000 })
  await code.fill(totp(secrets.totp_secret))
  await code.press('Enter')
  await page.waitForURL(/\/admin(?!\/login)/, { timeout: 20000 })

  for (const [path, name, action] of [
    ['/admin/devices', 'devices', null],
    ['/admin/devices/create', 'device-new', null],
    ['/admin/manage-modules', 'modules', null],
    ['/admin/manage-settings', 'settings', null],
    ['/admin/licence', 'licence', null],
    ['/admin/import-data?type=products', 'import-data', null],
    ['/admin/stock-adjustments/create', 'stock-adjustment-new', null],
    ['/admin/products', 'export-menu', 'Export'],
  ]) {
    await page.goto(base + path)
    await page.waitForLoadState('networkidle')
    if (action) {
      await page.getByRole('button', { name: action }).first().click()
      await page.getByText('Export to CSV (Excel)').first().waitFor()
      await page.waitForTimeout(500)
    }
    if (name === 'licence') { // show a placeholder instead of this machine's own installation code
      await page.locator('textarea').first().evaluate((el) => { el.value = 'SYNREQ-…  (your own installation code appears here)' })
    }
    await shot(page, name)
  }
  await page.close()
}
await browser.close()
console.log(`Screenshots written to ${out}`)
