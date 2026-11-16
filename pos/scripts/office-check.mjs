// The new back office (/office) on the dev server (port 8010), in both looks at two screen sizes: the menu shows, an area opens
// its pages, a page of the current back office opens inside without its own menus, search finds pages, and nothing runs
// off the side. Screenshots go to .local-secrets/office-*.png (git-ignored) for a look. Run: npm run check:office
// (build it first: npm run build:office). Signs in as the dev owner (.local-secrets/dev-owner.txt), like check:layout.
import { createHmac } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

const here = dirname(fileURLToPath(import.meta.url))
const base = process.env.LAYOUT_BASE ?? 'http://127.0.0.1:8010'
const out = (name) => resolve(here, `../../.local-secrets/office-${name}.png`)
const secrets = Object.fromEntries(readFileSync(resolve(here, '../../.local-secrets/dev-owner.txt'), 'utf8').split(/\r?\n/)
  .map((l) => l.match(/^([a-z_]+):\s*(.+)$/)).filter(Boolean).map((m) => [m[1], m[2].trim()]))

function totp(secret) {
  const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''
  for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += a.indexOf(c).toString(2).padStart(5, '0')
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2))); const ctr = Buffer.alloc(8)
  ctr.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000))); const h = createHmac('sha1', key).update(ctr).digest(); const o = h[h.length - 1] & 15
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).padStart(6, '0')
}

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1920, height: 1000 } })
const problems = []
try {
  // Opening /office signed out goes to the sign-in page, and back to /office afterwards.
  await page.goto(`${base}/office/`)
  await page.waitForURL(/\/admin\/login/)
  await page.locator('input[autocomplete=username]').fill(secrets.email)
  await page.locator('input[type=password]').fill(secrets.password)
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  const code = page.locator('input[autocomplete="one-time-code"], input[inputmode="numeric"]').first()
  await code.waitFor({ timeout: 15000 }); await code.fill(totp(secrets.totp_secret)); await code.press('Enter')
  await page.waitForURL(/\/office\//, { timeout: 20000 }).catch(() => problems.push(`after sign-in the browser is at ${page.url()}, not /office`))
  if (!/\/office\//.test(page.url())) await page.goto(`${base}/office/`)

  const frameReady = async () => {
    const f = page.frameLocator('iframe[title="Page"]')
    await f.locator('html.zx-embedded').waitFor({ timeout: 20000 })
    return f
  }

  for (const look of ['top', 'side']) {
    // Switch the look from the person's menu.
    await page.getByRole('button', { name: /your menu/ }).click()
    await page.getByRole('menuitemradio', { name: look === 'top' ? 'Across the top' : 'At the side' }).click()
    await page.keyboard.press('Escape')
    await page.waitForTimeout(500)
    for (const [w, h] of [[1920, 1000], [1366, 720]]) {
      await page.setViewportSize({ width: w, height: h })
      await page.goto(`${base}/office/`)
      // Home: every panel of the template is there.
      await page.getByRole('heading', { name: 'Home', level: 1 }).waitFor({ timeout: 20000 })
      for (const panel of ['Reminders', 'Tiles', 'Key performance indicators', 'KPI meter', 'Top 15 items by quantity sold', 'Monthly trend', 'Shortcuts']) {
        if (!(await page.getByRole('heading', { name: panel, level: 2 }).isVisible().catch(() => false))) problems.push(`${look} ${w}: Home has no "${panel}" panel`)
      }
      await page.waitForTimeout(700) // the panels' grow-in finishes
      await page.screenshot({ path: out(`home-${look}-${w}`), fullPage: true })
      // Till sales, rebuilt: from the menu it opens the new list (not the framed one); a filter narrows it; a row opens the sale.
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
      await page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: 'Till sales', exact: true }).first().click()
      await page.waitForURL(/\/office\/sales$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Till sales did not open the new screen (${page.url()})`))
      await page.getByRole('heading', { name: 'Till sales', level: 1 }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: no Till sales heading`))
      await page.getByRole('button', { name: 'All', exact: true }).click()
      const firstRow = page.getByRole('table', { name: 'Till sales' }).getByRole('row').nth(1)
      await Promise.race([firstRow.waitFor({ timeout: 20000 }), page.getByText('No sales match').waitFor({ timeout: 20000 })]).catch(() => problems.push(`${look} ${w}: Till sales showed neither sales nor "No sales match"`))
      await page.screenshot({ path: out(`sales-${look}-${w}`) })
      if (await firstRow.isVisible().catch(() => false)) {
        await firstRow.click()
        await page.waitForURL(/\/office\/sales\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a sale did not open`))
        await page.getByRole('heading', { name: /^Sale / }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: no sale heading`))
        await page.screenshot({ path: out(`sale-${look}-${w}`), fullPage: true })
      } else problems.push(`${look} ${w}: the dev data has no till sales to open`)

      // To-do: from the menu, the list of what waits (or "All clear").
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /^To-do/ }).first().click()
      await page.getByRole('heading', { name: 'To-do', level: 1 }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: To-do did not open`))
      if (!/\/office\/todo/.test(page.url())) problems.push(`${look} ${w}: To-do is not at /office/todo (${page.url()})`)
      await page.waitForTimeout(500)
      await page.screenshot({ path: out(`todo-${look}-${w}`), fullPage: true })
      // A till request waiting: Approve asks to confirm it is you (or approves at once if confirmed in the last minutes).
      const approve = page.getByRole('button', { name: 'Approve', exact: true }).first()
      if (look === 'side' && w === 1366 && await approve.isVisible().catch(() => false)) {
        await approve.click()
        const confirm = page.getByRole('dialog', { name: "Confirm it's you" })
        await Promise.race([confirm.waitFor({ timeout: 10000 }), page.getByText(/^Approved:/).waitFor({ timeout: 10000 })])
          .catch(() => problems.push('To-do: Approve neither asked to confirm nor approved'))
        if (await confirm.isVisible().catch(() => false)) {
          await page.frameLocator('iframe[title="Confirm it\'s you"]').locator('html.zx-embedded').waitFor({ timeout: 15000 })
            .catch(() => problems.push('To-do: the confirm page did not open inside the dialog'))
          await page.screenshot({ path: out('todo-confirm') })
          await confirm.getByRole('button', { name: 'Cancel' }).click()
        }
      }
      await page.goto(`${base}/office/`)
      await page.getByRole('heading', { name: 'Home', level: 1 }).waitFor({ timeout: 20000 })
      const sideways = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)
      if (sideways > 1) problems.push(`${look} ${w}: the page runs ${sideways}px off the side`)

      // An area opens its pages; picking one opens it inside, and the address follows.
      const area = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first()
      await area.click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: 'Products', exact: true }).first().click()
      await page.waitForURL(/\/office\/a\/products/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Products did not open at /office/a/products (${page.url()})`))
      await page.waitForFunction(() => {
        const f = document.querySelector('iframe[title="Page"]')
        return f?.contentWindow?.location.pathname === '/admin/products' && f.contentDocument?.querySelector('h1')?.textContent?.includes('Products')
      }, null, { timeout: 20000 }).catch(() => problems.push(`${look} ${w}: the Products page did not open inside`))
      const f = await frameReady()
      await f.locator('.fi-ta-table, .fi-ta-empty-state').first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: the Products list did not show inside`))
      const chrome = await f.locator('.fi-sidebar, .fi-topbar').evaluateAll((els) => els.filter((e) => getComputedStyle(e).display !== 'none').length)
      if (chrome) problems.push(`${look} ${w}: the embedded page still shows its own menu`)
      await page.waitForTimeout(600) // the page's own loader fades out
      await page.screenshot({ path: out(`${look}-${w}`) })

      // Recent lists the page just opened; the star adds it to favourites and takes it off again.
      const nav = page.getByRole('navigation', { name: 'Menu' })
      await (look === 'top' ? nav.getByRole('button', { name: 'Recent pages' }) : nav.getByRole('button', { name: 'Recent' })).click()
      await page.getByRole('dialog', { name: 'Recent pages' }).getByRole('link', { name: /Products/ }).first().waitFor({ timeout: 5000 })
        .catch(() => problems.push(`${look} ${w}: Recent does not list Products`))
      await page.keyboard.press('Escape')
      const star = look === 'top' ? nav.getByRole('button', { name: 'Favourites' }) : nav.getByRole('button', { name: 'Favourites' })
      await star.click()
      const fav = page.getByRole('dialog', { name: 'Favourite pages' })
      await fav.getByRole('button', { name: /^Add “/ }).click().catch(() => problems.push(`${look} ${w}: Favourites has no "Add this page"`))
      await fav.getByRole('link', { name: /Products/ }).first().waitFor({ timeout: 5000 }).catch(() => problems.push(`${look} ${w}: Products did not become a favourite`))
      await page.screenshot({ path: out(`favourites-${look}-${w}`) })
      await fav.getByRole('button', { name: /^Take “/ }).last().click().catch(() => undefined) // leave the dev account as it was
      await fav.getByRole('button', { name: /^Add “/ }).waitFor({ timeout: 5000 }).catch(() => problems.push(`${look} ${w}: Products could not be taken off favourites`))
      await page.keyboard.press('Escape')

      // Search: Ctrl+K, type, a page of the menu is offered.
      await page.keyboard.press('Control+k')
      await page.keyboard.type('till req')
      await page.getByRole('option', { name: /Till requests/ }).first().waitFor({ timeout: 5000 })
        .catch(() => problems.push(`${look} ${w}: search did not offer Till requests`))
      await page.keyboard.press('Escape')
    }
  }
  // Back to the default look for the next person using this dev account.
  await page.getByRole('button', { name: /your menu/ }).click()
  await page.getByRole('menuitemradio', { name: 'Across the top' }).click()
} catch (e) {
  problems.push(`stopped: ${e.message.split('\n')[0]}`)
  await page.screenshot({ path: out('error') }).catch(() => undefined)
} finally {
  await browser.close()
}

if (problems.length) {
  console.error(`New back office check failed:\n- ${problems.join('\n- ')}`)
  process.exit(1)
}
console.log('New back office check passed: 2 looks × 2 sizes (screenshots in .local-secrets/office-*.png)')
