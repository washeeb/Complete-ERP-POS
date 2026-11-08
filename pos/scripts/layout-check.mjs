// Layout check (owner 2026-11-08, after the side-menu look broke unnoticed): opens real back-office pages in every look
// (tabs across the top, side menu, classic) at desktop and laptop sizes, scrolls, and fails if a layout rule is broken:
//   - a list's scrolling box is squashed (shorter than a third of the window while it has rows to show),
//   - the slim title bar pins anywhere but just under the bars at the top,
//   - --zx-stick is out of range, or the page scrolls sideways,
//   - a menu panel opens outside the window.
// Run from the pos folder with the dev server on 8010: npm run check:layout. Signs in with the development owner from
// the git-ignored .local-secrets/dev-owner.txt (nothing printed). Run it after any change to the look, menus or lists.
import { createHmac } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

const here = dirname(fileURLToPath(import.meta.url))
const base = process.env.LAYOUT_BASE ?? 'http://127.0.0.1:8010'
const secrets = Object.fromEntries(readFileSync(resolve(here, '../../.local-secrets/dev-owner.txt'), 'utf8').split(/\r?\n/)
  .map((l) => l.match(/^([a-z_]+):\s*(.+)$/)).filter(Boolean).map((m) => [m[1], m[2].trim()]))
const PAGES = ['/admin/products', '/admin/audit-events', '/admin/profile', '/admin/stock-movements', '/admin/sales']
const LOOKS = ['top', 'side', 'classic']
const SIZES = [[1920, 1000], [1366, 720]]

function totp(secret) {
  const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''
  for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += a.indexOf(c).toString(2).padStart(5, '0')
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2))); const ctr = Buffer.alloc(8)
  ctr.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000))); const h = createHmac('sha1', key).update(ctr).digest(); const o = h[h.length - 1] & 15
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).padStart(6, '0')
}

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1920, height: 1000 } })
await page.goto(`${base}/admin/login`)
await page.locator('input[autocomplete=username]').fill(secrets.email)
await page.locator('input[type=password]').fill(secrets.password)
await page.getByRole('button', { name: 'Sign in', exact: true }).click()
const code = page.locator('input[autocomplete="one-time-code"], input[inputmode="numeric"]').first()
await code.waitFor({ timeout: 15000 }); await code.fill(totp(secrets.totp_secret)); await code.press('Enter')
await page.waitForURL(/\/admin(?!\/login)/, { timeout: 20000 }).catch(async (e) => {
  await page.screenshot({ path: resolve(here, '../../.local-secrets/layout-check-signin.png') }) // git-ignored folder: never committed
  throw new Error('Sign-in did not finish; see .local-secrets/layout-check-signin.png. ' + e.message)
})

const chooseLook = async (value) => {
  await page.goto(`${base}/admin/profile`); await page.waitForLoadState('networkidle')
  await page.locator(`input[type=radio][value="${value}"]`).check()
  await page.getByRole('button', { name: 'Save changes' }).click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(600)
}

const problems = []
try {
  for (const look of LOOKS) {
    await chooseLook(look)
    for (const [w, h] of SIZES) {
      await page.setViewportSize({ width: w, height: h })
      for (const path of PAGES) {
        await page.goto(base + path); await page.waitForLoadState('networkidle')
        await page.mouse.wheel(0, 900); await page.waitForTimeout(400)
        const r = await page.evaluate(() => {
          const px = (v) => parseFloat(v) * (v.trim().endsWith('rem') ? parseFloat(getComputedStyle(document.documentElement).fontSize) : 1)
          const box = document.querySelector('.fi-ta-content-ctn')
          const rows = document.querySelectorAll('.fi-ta-table tbody tr').length
          const header = document.querySelector('.fi-header')
          return {
            classes: document.documentElement.className,
            stick: px(getComputedStyle(document.documentElement).getPropertyValue('--zx-stick') || '0'),
            box: box ? box.getBoundingClientRect().height : null, rows,
            slim: document.documentElement.classList.contains('zx-slim'),
            headerTop: header ? header.getBoundingClientRect().top : null,
            sideways: document.documentElement.scrollWidth - innerWidth, innerHeight,
          }
        })
        const where = `${look} ${w}×${h} ${path}`
        if (r.classes.includes('zx-new') && (r.stick < 30 || r.stick > 160)) problems.push(`${where}: --zx-stick is ${r.stick}px`)
        if (r.box !== null && r.rows > 5 && r.box < r.innerHeight / 3) problems.push(`${where}: list squashed to ${Math.round(r.box)}px with ${r.rows} rows`)
        if (r.slim && (r.headerTop < 0 || r.headerTop > r.stick + 8)) problems.push(`${where}: slim title bar at ${Math.round(r.headerTop)}px, menu bars end at ${Math.round(r.stick)}px`)
        if (r.sideways > 1) problems.push(`${where}: the page scrolls sideways by ${r.sideways}px`)
        if (look === 'top' && path === PAGES[0]) {
          for (const tab of await page.locator('.zx-tab').all()) {
            if (!(await tab.isVisible())) continue
            await tab.click(); await page.waitForTimeout(250)
            const panel = await page.locator('.zx-mega:visible').first().boundingBox().catch(() => null)
            if (panel && (panel.x < 0 || panel.x + panel.width > w)) problems.push(`${where}: menu "${(await tab.textContent()).trim()}" opens outside the window`)
            await page.keyboard.press('Escape')
          }
        }
      }
    }
  }
} finally {
  await chooseLook('') // back to the company's choice
  await browser.close()
}
if (problems.length) {
  console.error(`Layout check: ${problems.length} problem(s)\n - ` + problems.join('\n - '))
  process.exit(1)
}
console.log(`Layout check passed: ${LOOKS.length} looks × ${SIZES.length} sizes × ${PAGES.length} pages`)
