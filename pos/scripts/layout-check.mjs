// Layout check (owner 2026-11-08, after the side-menu look broke unnoticed; moved to the new back office in part 43, when
// the classic one was switched off): opens real back-office pages in every look (menu across the top, side menu) at
// desktop and laptop sizes, scrolls, and fails if a layout rule is broken:
//   - the page scrolls sideways,
//   - a list is squashed (its table shorter than a third of the window while it has rows to show),
//   - the menu is out of reach after scrolling (the bar across the top, or the side menu),
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
const PAGES = ['/office/products', '/office/audit-log', '/office/profile', '/office/movements', '/office/sales']
const LOOKS = ['top', 'side']
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
await page.goto(`${base}/office/sign-in`)
await page.locator('input[autocomplete=username]').fill(secrets.email)
await page.locator('input[type=password]').fill(secrets.password)
await page.getByRole('button', { name: 'Sign in', exact: true }).click()
const code = page.locator('input[autocomplete="one-time-code"], input[inputmode="numeric"]').first()
await code.waitFor({ timeout: 15000 }); await code.fill(totp(secrets.totp_secret)); await code.press('Enter')
await page.waitForURL(/\/office\/(?!sign-in)/, { timeout: 20000 }).catch(async (e) => {
  await page.screenshot({ path: resolve(here, '../../.local-secrets/layout-check-signin.png') }) // git-ignored folder: never committed
  throw new Error('Sign-in did not finish; see .local-secrets/layout-check-signin.png. ' + e.message)
})

/** The look, as the person's menu sets it (saved for the account): menu across the top or at the side. */
const chooseLook = async (look) => {
  await page.evaluate(async (l) => {
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '')
    await fetch('/office-api/prefs', { method: 'POST', credentials: 'same-origin', body: JSON.stringify({ office_look: l }),
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } })
  }, look)
}

const problems = []
try {
  for (const look of LOOKS) {
    await chooseLook(look)
    for (const [w, h] of SIZES) {
      await page.setViewportSize({ width: w, height: h })
      for (const path of PAGES) {
        await page.goto(base + path); await page.waitForLoadState('networkidle')
        await page.getByRole('navigation', { name: 'Menu' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}×${h} ${path}: no menu`))
        await page.waitForTimeout(400)
        await page.mouse.move(w / 2, h / 2); await page.mouse.wheel(0, 900); await page.waitForTimeout(400)
        const r = await page.evaluate(() => {
          const table = document.querySelector('main table, [role=main] table, table')
          const rows = table ? table.querySelectorAll('tbody tr').length : 0
          const nav = document.querySelector('nav[aria-label="Menu"]')
          const n = nav ? nav.getBoundingClientRect() : null
          return { sideways: document.documentElement.scrollWidth - innerWidth, innerHeight, innerWidth, rows,
            table: table ? table.getBoundingClientRect().height : null, nav: n ? { top: n.top, bottom: n.bottom, left: n.left, right: n.right } : null }
        })
        const where = `${look} ${w}×${h} ${path}`
        if (r.sideways > 1) problems.push(`${where}: the page scrolls sideways by ${r.sideways}px`)
        if (r.table !== null && r.rows > 5 && r.table < r.innerHeight / 3) problems.push(`${where}: list squashed to ${Math.round(r.table)}px with ${r.rows} rows`)
        if (look === 'side' && r.nav && (r.nav.bottom < 100 || r.nav.left < 0)) problems.push(`${where}: the side menu is out of reach after scrolling`)
        if (look === 'top' && r.nav && (r.nav.bottom < 0 || r.nav.top > r.innerHeight)) problems.push(`${where}: the menu bar is off the screen`)
        if (path === PAGES[0]) {
          // Each area of the menu opens its panel inside the window.
          const tabs = page.getByRole('navigation', { name: 'Menu' }).getByRole('button')
          for (let i = 0; i < await tabs.count(); i++) {
            const tab = tabs.nth(i)
            const name = ((await tab.getAttribute('aria-label')) ?? (await tab.textContent()) ?? '').trim()
            if (!(await tab.isVisible()) || /^(Home|To-do|Recent|Favourites|Create)\b/.test(name)) continue
            await tab.click(); await page.waitForTimeout(300)
            const panel = await page.locator('[role=dialog]:visible, [role=navigation][aria-label]:not([aria-label="Menu"]):visible').last().boundingBox().catch(() => null)
            if (panel && (panel.x < 0 || panel.x + panel.width > w + 1 || panel.y < 0)) problems.push(`${where}: menu "${name}" opens outside the window`)
            await page.keyboard.press('Escape'); await page.waitForTimeout(150)
          }
        }
      }
    }
  }
} finally {
  await chooseLook('top').catch(() => undefined) // back to the default look for the next person using this dev account
  await browser.close()
}
if (problems.length) {
  console.error(`Layout check: ${problems.length} problem(s)\n - ` + problems.join('\n - '))
  process.exit(1)
}
console.log(`Layout check passed: ${LOOKS.length} looks × ${SIZES.length} sizes × ${PAGES.length} pages`)
