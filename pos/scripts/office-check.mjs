// The new back office (/office) on the dev server (port 8010), in both looks at two screen sizes: the menu shows, an area opens
// its pages, a page of the current back office opens inside without its own menus, search finds pages, and nothing runs
// off the side. Screenshots go to .local-secrets/office-*.png (git-ignored) for a look. Run: npm run check:office
// (build it first: npm run build:office). Signs in as the dev owner (.local-secrets/dev-owner.txt), like check:layout.
import { createHmac } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

/** A menu link by its name, with or without the count badge some items carry ("Invoices from suppliers 1", "Alerts 99+"). */
const menuName = (name) => new RegExp(`^${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*(\\d+\\+?)?$`)

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
let optionsAnswer = 'no answer yet' // the products screen's options, for a clearer problem report
page.on('response', async (r) => { if (r.url().includes('/office-api/products/options')) optionsAnswer = `options answered ${r.status()} ${(await r.text().catch(() => '')).slice(0, 200)}` })
let restoreScope = null
try {
  // Opening /office signed out goes to the new sign-in page (part 41), and back to /office afterwards: the password, then
  // the code from the app.
  await page.goto(`${base}/office/`)
  await page.waitForURL(/\/office\/sign-in/).catch(() => problems.push(`signed out, /office went to ${page.url()}, not /office/sign-in`))
  await page.getByRole('button', { name: 'Sign in with a passkey' }).waitFor({ timeout: 15000 }).catch(() => problems.push('the sign-in page offers no passkey'))
  await page.screenshot({ path: out('sign-in') })
  await page.locator('input[autocomplete=username]').fill(secrets.email)
  await page.locator('input[type=password]').fill(secrets.password)
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  const code = page.locator('input[autocomplete="one-time-code"], input[inputmode="numeric"]').first()
  await code.waitFor({ timeout: 15000 }); await code.fill(totp(secrets.totp_secret)); await code.press('Enter')
  await page.waitForURL(/\/office\/(?!sign-in)/, { timeout: 20000 }).catch(() => problems.push(`after sign-in the browser is at ${page.url()}, not /office`))
  if (!/\/office\/(?!sign-in)/.test(page.url())) await page.goto(`${base}/office/`)
  // This dev account is shared: look at everything for the check, and put the person's own place back at the end.
  const scopeCall = (body) => page.evaluate(async (b) => {
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '')
    const h = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf }
    if (!b) return (await (await fetch('/office-api/me', { headers: h })).json()).scope
    await fetch('/office-api/scope', { method: 'POST', headers: h, body: JSON.stringify(b) })
  }, body)
  const ownScope = await scopeCall(null)
  if (ownScope && (ownScope.industry || ownScope.branch)) { await scopeCall({ industry: '', branch: '' }); await page.reload() }
  restoreScope = ownScope && (ownScope.industry || ownScope.branch) ? () => scopeCall({ industry: ownScope.industry, branch: ownScope.branch }) : null

  /** Cancel on a form; when something was typed, leaving asks first (part 44): leave without saving. */
  const cancelForm = async () => {
    await page.getByRole('button', { name: 'Cancel', exact: true }).click()
    const leave = page.getByRole('dialog', { name: 'You have unsaved changes' })
    if (await leave.waitFor({ timeout: 1500 }).then(() => true).catch(() => false)) await leave.getByRole('button', { name: 'Leave without saving' }).click()
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
      await page.getByRole('heading', { name: 'Business Portal', level: 1 }).waitFor({ timeout: 20000 })
      for (const panel of ['Reminders', 'Action Centre', 'Key performance indicators', 'KPI meter', 'Top 15 items by quantity sold', 'Monthly trend', 'Shortcuts']) {
        if (!(await page.getByRole('heading', { name: panel, level: 2 }).isVisible().catch(() => false))) problems.push(`${look} ${w}: Home has no "${panel}" panel`)
      }
      await page.waitForTimeout(700) // the panels' grow-in finishes
      await page.screenshot({ path: out(`home-${look}-${w}`), fullPage: true })
      // Till sales, rebuilt: from the menu it opens the new list (not the framed one); a filter narrows it; a row opens the sale.
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
      await page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName('Till sales') }).first().click()
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

      // Till shifts and Till drawer, rebuilt: from the menu, rows (or "no … match"); a shift opens with the report's figures.
      for (const [link, url, heading, empty] of [['Till shifts', /\/office\/shifts$/, 'Till shifts', 'No shifts match'], ['Till drawer', /\/office\/drawer$/, 'Till drawer', 'Nothing went in or out']]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
        await page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName(link) }).first().click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await page.getByRole('button', { name: 'All', exact: true }).click().catch(() => undefined)
        const row = page.getByRole('table', { name: heading }).getByRole('row').nth(1)
        await Promise.race([row.waitFor({ timeout: 20000 }), page.getByText(empty).waitFor({ timeout: 20000 })]).catch(() => problems.push(`${look} ${w}: ${link} showed neither rows nor "${empty}"`))
        await page.screenshot({ path: out(`${link.replace(' ', '-').toLowerCase()}-${look}-${w}`) })
        if (link === 'Till shifts' && await row.isVisible().catch(() => false)) {
          await row.click()
          await page.waitForURL(/\/office\/shifts\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a shift did not open`))
          await page.getByText('Grand total sold').waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: the shift shows no report figures`))
          await page.screenshot({ path: out(`shift-${look}-${w}`), fullPage: true })
          // The vault count form, drawn: the dev data has no vault waiting, so the answer is changed in the browser only
          // to offer the count; the form is shown, looked at and cancelled (nothing is saved).
          if (look === 'top' && w === 1366) {
            await page.route('**/office-api/shifts/*', async (r) => {
              const res = await r.fetch()
              const j = await res.json()
              await r.fulfill({ response: res, json: { ...j, vault: 'Vault 1', actions: { ...j.actions, count_vault: true } } })
            })
            // Back to the list and open the row again in the app (not a reload: `php artisan serve` cannot serve a deep
            // /office/… address, it takes /office/index.html for a script; the installed web servers can).
            await page.goBack()
            await row.click()
            await page.getByRole('button', { name: 'Count the vault' }).click({ timeout: 15000 })
            const dlg = page.getByRole('dialog', { name: /Count the vault/ })
            await dlg.getByLabel('GH₵100', { exact: true }).fill('1').catch(() => problems.push('vault count: no GH₵100 box'))
            await dlg.getByLabel('GH₵20', { exact: true }).fill('2').catch(() => problems.push('vault count: no GH₵20 box'))
            await dlg.getByText('GH₵140.00').waitFor({ timeout: 5000 }).catch(() => problems.push('vault count: the total did not add up to GH₵140.00'))
            await page.screenshot({ path: out('vault-count') })
            await dlg.getByRole('button', { name: 'Cancel' }).click()
            await page.unroute('**/office-api/shifts/*')
          }
        }
      }

      // Customers, rebuilt (release 4): from the Sales menu, rows (or "No customers match"); a row opens the customer with
      // their account and open items; the payment and credit dialogs and the add form are drawn and left (nothing saved).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
      await page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName('Customers') }).first().click()
      await page.waitForURL(/\/office\/customers$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Customers did not open the new screen (${page.url()})`))
      const custRow = page.getByRole('table', { name: 'Customers' }).getByRole('row').nth(1)
      await Promise.race([custRow.waitFor({ timeout: 20000 }), page.getByText('No customers match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Customers showed neither rows nor "No customers match"`))
      await page.screenshot({ path: out(`customers-${look}-${w}`) })
      if (await custRow.isVisible().catch(() => false)) {
        await custRow.click()
        await page.waitForURL(/\/office\/customers\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a customer did not open`))
        await page.getByRole('region', { name: 'Open items' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a customer shows no open items`))
        await page.screenshot({ path: out(`customer-${look}-${w}`), fullPage: true })
        if (look === 'top' && w === 1366) {
          for (const [button, dialog] of [['Record payment', 'Record payment'], ['Credit', 'Credit'], ['Statement', 'Statement']]) {
            const b = page.getByRole('button', { name: button, exact: true })
            if (!(await b.waitFor({ timeout: 10000 }).then(() => true).catch(() => false))) { problems.push(`customer: no ${button} button for the owner`); continue }
            await b.click()
            const d = page.getByRole('dialog', { name: dialog })
            await d.waitFor({ timeout: 10000 }).catch(() => problems.push(`customer: ${button} did not open its dialog`))
            await page.waitForTimeout(300)
            await page.screenshot({ path: out(`customer-${button.replace(' ', '-').toLowerCase()}`) })
            await d.getByRole('button', { name: 'Back' }).click()
            await d.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => undefined) // the page behind is hidden until it closes
          }
        }
        await page.goBack()
      } else problems.push(`${look} ${w}: the dev data has no customers to open`)
      if (look === 'top' && w === 1366) {
        await page.getByRole('button', { name: 'Add customer' }).click()
        await page.waitForURL(/\/office\/customers\/new$/, { timeout: 15000 }).catch(() => problems.push('customer form: did not open'))
        await page.getByRole('textbox', { name: /^Name/ }).fill('Check customer').catch(() => problems.push('customer form: no Name box'))
        const industry = page.getByRole('main').getByRole('combobox', { name: /^Industry/ })
        if (await industry.isVisible().catch(() => false)) { await industry.click(); await page.getByRole('listbox').getByRole('option').first().click() }
        if (await page.getByRole('button', { name: 'Save', exact: true }).isDisabled()) problems.push('customer form: Save stays off with the name (and industry) filled in')
        await page.getByRole('region', { name: 'Marketing consent' }).waitFor({ timeout: 5000 }).catch(() => problems.push('customer form: no consent section'))
        await page.screenshot({ path: out('customer-form'), fullPage: true })
        await cancelForm() // nothing is saved
      }

      // Invoices, rebuilt (release 4): from the Sales menu, rows (or "No invoices match"); a row opens the invoice with
      // its lines and what settled it; at one size the Credit note dialog is drawn and left (nothing issued).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
      await page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName('Invoices') }).first().click()
      await page.waitForURL(/\/office\/invoices$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Invoices did not open the new screen (${page.url()})`))
      const invRow = page.getByRole('table', { name: 'Invoices' }).getByRole('row').nth(1)
      await Promise.race([invRow.waitFor({ timeout: 20000 }), page.getByText('No invoices match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Invoices showed neither rows nor "No invoices match"`))
      await page.screenshot({ path: out(`invoices-${look}-${w}`) })
      if (await invRow.isVisible().catch(() => false)) {
        await invRow.click()
        await page.waitForURL(/\/office\/invoices\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: an invoice did not open`))
        await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: an invoice shows no lines`))
        await page.screenshot({ path: out(`invoice-${look}-${w}`), fullPage: true })
        const credit = page.getByRole('button', { name: 'Credit note', exact: true })
        if (look === 'top' && w === 1366 && await credit.isVisible().catch(() => false)) {
          await credit.click()
          const d = page.getByRole('dialog')
          await d.waitFor({ timeout: 10000 }).catch(() => problems.push('invoice: Credit note did not open its dialog'))
          if (await d.getByRole('button', { name: 'Issue credit note' }).isEnabled().catch(() => true)) problems.push('invoice: Issue is on before a reason is given')
          await page.waitForTimeout(300)
          await page.screenshot({ path: out('invoice-credit-note') })
          await d.getByRole('button', { name: 'Back' }).click() // nothing is issued
          await d.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => undefined)
        }
        await page.goBack()
      }

      // Suppliers, rebuilt (release 5): from the Purchasing menu, rows (or "No suppliers match"); a row opens the supplier
      // with its products and documents; at one size the Scorecard dialog and the add form are drawn and left (nothing saved).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Purchasing/ }).first().click()
      await page.getByRole('navigation', { name: 'Purchasing' }).getByRole('link', { name: menuName('Suppliers') }).first().click()
      await page.waitForURL(/\/office\/suppliers$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Suppliers did not open the new screen (${page.url()})`))
      const supRow = page.getByRole('table', { name: 'Suppliers' }).getByRole('row').nth(1)
      await Promise.race([supRow.waitFor({ timeout: 20000 }), page.getByText('No suppliers match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Suppliers showed neither rows nor "No suppliers match"`))
      await page.screenshot({ path: out(`suppliers-${look}-${w}`) })
      if (await supRow.isVisible().catch(() => false)) {
        await supRow.click()
        await page.waitForURL(/\/office\/suppliers\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a supplier did not open`))
        await page.getByRole('region', { name: 'Documents' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a supplier shows no documents section`))
        await page.screenshot({ path: out(`supplier-${look}-${w}`), fullPage: true })
        if (look === 'top' && w === 1366) {
          await page.getByRole('button', { name: 'Scorecard', exact: true }).click()
          const d = page.getByRole('dialog')
          await d.waitFor({ timeout: 10000 }).catch(() => problems.push('supplier: Scorecard did not open its dialog'))
          await page.screenshot({ path: out('supplier-scorecard') })
          await d.getByRole('button', { name: 'Close' }).click()
          await d.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => undefined)
        }
        await page.goBack()
      } else problems.push(`${look} ${w}: the dev data has no suppliers to open`)
      if (look === 'top' && w === 1366) {
        await page.getByRole('button', { name: 'Add supplier' }).click()
        await page.waitForURL(/\/office\/suppliers\/new$/, { timeout: 15000 }).catch(() => problems.push('supplier form: did not open'))
        await page.getByRole('textbox', { name: /^Name/ }).fill('Check supplier').catch(() => problems.push('supplier form: no Name box'))
        const industry = page.getByRole('main').getByRole('combobox', { name: /^Industry/ })
        if (await industry.isVisible().catch(() => false)) { await industry.click(); await page.getByRole('listbox').getByRole('option').first().click() }
        if (await page.getByRole('button', { name: 'Save', exact: true }).isDisabled()) problems.push('supplier form: Save stays off with the name (and industry) filled in')
        await page.getByRole('button', { name: 'Add product' }).click()
        if (await page.getByRole('button', { name: 'Save', exact: true }).isEnabled()) problems.push('supplier form: Save is on with a product line not chosen')
        await page.screenshot({ path: out('supplier-form'), fullPage: true })
        await cancelForm() // nothing is saved
      }

      // Purchase orders, rebuilt (release 5): from the Purchasing menu, rows (or "No purchase orders match"); a row opens
      // the order with its lines; at one size the new-order form is drawn and left (Save stays off until a line is filled).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Purchasing/ }).first().click()
      await page.getByRole('navigation', { name: 'Purchasing' }).getByRole('link', { name: menuName('Purchase orders') }).first().click()
      await page.waitForURL(/\/office\/purchase-orders$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Purchase orders did not open the new screen (${page.url()})`))
      const poRow = page.getByRole('table', { name: 'Purchase orders' }).getByRole('row').nth(1)
      await Promise.race([poRow.waitFor({ timeout: 20000 }), page.getByText('No purchase orders match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Purchase orders showed neither rows nor "No purchase orders match"`))
      await page.screenshot({ path: out(`purchase-orders-${look}-${w}`) })
      if (await poRow.isVisible().catch(() => false)) {
        await poRow.click()
        await page.waitForURL(/\/office\/purchase-orders\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a purchase order did not open`))
        await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a purchase order shows no lines`))
        await page.screenshot({ path: out(`purchase-order-${look}-${w}`), fullPage: true })
        await page.goBack()
      }
      if (look === 'top' && w === 1366) {
        await page.getByRole('button', { name: 'New purchase order' }).click()
        await page.waitForURL(/\/office\/purchase-orders\/new$/, { timeout: 15000 }).catch(() => problems.push('purchase order form: did not open'))
        await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 10000 }).catch(() => problems.push('purchase order form: no Lines section'))
        if (await page.getByRole('button', { name: 'Save draft', exact: true }).isEnabled()) problems.push('purchase order form: Save is on with nothing filled in')
        await page.getByRole('button', { name: 'Service or non-stock item' }).first().click()
        await page.getByRole('textbox', { name: /^What/ }).waitFor({ timeout: 5000 }).catch(() => problems.push('purchase order form: a service line asks no "What"'))
        await page.screenshot({ path: out('purchase-order-form'), fullPage: true })
        await cancelForm() // nothing is saved
      }

      // Goods received, rebuilt (release 5): from the Purchasing menu, rows (or "No goods received notes match"); a row opens the note.
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Purchasing/ }).first().click()
      await page.getByRole('navigation', { name: 'Purchasing' }).getByRole('link', { name: menuName('Goods received') }).first().click()
      await page.waitForURL(/\/office\/goods-received$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Goods received did not open the new screen (${page.url()})`))
      const grnRow = page.getByRole('table', { name: 'Goods received' }).getByRole('row').nth(1)
      await Promise.race([grnRow.waitFor({ timeout: 20000 }), page.getByText('No goods received notes match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Goods received showed neither rows nor "No goods received notes match"`))
      await page.screenshot({ path: out(`goods-received-${look}-${w}`) })
      if (await grnRow.isVisible().catch(() => false)) {
        await grnRow.click()
        await page.waitForURL(/\/office\/goods-received\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a goods received note did not open`))
        await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a goods received note shows no lines`))
        await page.screenshot({ path: out(`goods-receipt-${look}-${w}`), fullPage: true })
        const lbl = page.getByRole('button', { name: 'Print labels', exact: true })
        if (look === 'top' && w === 1366 && await lbl.isVisible().catch(() => false)) {
          await lbl.click()
          const d = page.getByRole('dialog')
          await d.getByRole('button', { name: 'Make the labels' }).waitFor({ timeout: 10000 }).catch(() => problems.push('goods received: Print labels did not open the labels dialog'))
          await page.screenshot({ path: out('goods-receipt-labels') })
          await d.getByRole('button', { name: 'Cancel' }).click() // no labels made
          await d.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => undefined)
        }
        await page.goBack()
      }

      // Direct purchases, rebuilt (release 5): from the Purchasing menu, rows (or "No direct purchases match"); a row opens it;
      // at one size the form is drawn (Save off while empty) and the Scan dialog opened and left (nothing read or saved).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Purchasing/ }).first().click()
      await page.getByRole('navigation', { name: 'Purchasing' }).getByRole('link', { name: menuName('Direct purchases') }).first().click()
      await page.waitForURL(/\/office\/direct-purchases$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Direct purchases did not open the new screen (${page.url()})`))
      const dpRow = page.getByRole('table', { name: 'Direct purchases' }).getByRole('row').nth(1)
      await Promise.race([dpRow.waitFor({ timeout: 20000 }), page.getByText('No direct purchases match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Direct purchases showed neither rows nor "No direct purchases match"`))
      await page.screenshot({ path: out(`direct-purchases-${look}-${w}`) })
      if (await dpRow.isVisible().catch(() => false)) {
        await dpRow.click()
        await page.waitForURL(/\/office\/direct-purchases\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a direct purchase did not open`))
        await page.getByRole('region', { name: 'Products' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a direct purchase shows no products`))
        await page.screenshot({ path: out(`direct-purchase-${look}-${w}`), fullPage: true })
        await page.goBack()
      }
      if (look === 'top' && w === 1366) {
        await page.getByRole('button', { name: 'Enter a supplier invoice' }).click()
        await page.waitForURL(/\/office\/direct-purchases\/new$/, { timeout: 15000 }).catch(() => problems.push('direct purchase form: did not open'))
        await page.getByRole('region', { name: 'Products' }).waitFor({ timeout: 10000 }).catch(() => problems.push('direct purchase form: no Products section'))
        if (await page.getByRole('button', { name: 'Save', exact: true }).isEnabled()) problems.push('direct purchase form: Save is on with nothing filled in')
        await page.getByRole('button', { name: 'Scan the invoice' }).click()
        const d = page.getByRole('dialog')
        await d.waitFor({ timeout: 10000 }).catch(() => problems.push('direct purchase form: Scan the invoice did not open'))
        await page.screenshot({ path: out('direct-purchase-scan') })
        await d.getByRole('button', { name: 'Back' }).click()
        await d.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => undefined)
        // One line filled in (nothing saved): the product search, its units, and the margin beside the new price.
        const line1 = page.getByRole('group', { name: 'Line 1' })
        const dpPick = line1.getByRole('combobox', { name: /Product and unit/ })
        await dpPick.click() // opens the list, as a person typing does
        await dpPick.fill('tom')
        await page.getByRole('listbox').getByRole('option').first().click({ timeout: 10000 }).catch(() => problems.push('direct purchase form: the product search found nothing for "tom"'))
        await line1.getByRole('spinbutton', { name: /^Qty/ }).fill('2')
        await line1.getByRole('spinbutton', { name: /^Unit price/ }).fill('100')
        await line1.getByRole('spinbutton', { name: /^New selling price/ }).fill('150')
        await line1.getByText(/^Margin \d+%/).waitFor({ timeout: 10000 }).catch(() => problems.push('direct purchase form: no margin shown beside the new price'))
        await line1.scrollIntoViewIfNeeded()
        await page.screenshot({ path: out('direct-purchase-form') })
        await cancelForm() // nothing is saved
      }

      // Invoices from suppliers and Consignment to pay, rebuilt (release 5): from the Purchasing menu, each opens its new screen.
      for (const [link, path, ready] of [['Invoices from suppliers', /\/office\/supplier-invoices$/, () => page.getByText('No invoices from suppliers here').or(page.getByRole('table', { name: 'Invoices from suppliers' }).getByRole('row').nth(1))],
        ['Consignment to pay', /\/office\/consignment-to-pay$/, () => page.getByRole('region', { name: 'Owed for consigned goods sold' })]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Purchasing/ }).first().click()
        await page.getByRole('navigation', { name: 'Purchasing' }).getByRole('link', { name: menuName(link) }).first().click()
        await page.waitForURL(path, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${link} did not show its list`))
        await page.screenshot({ path: out(`${link.split(' ')[0].toLowerCase()}-${look}-${w}`) })
      }

      // Reorder levels, What to order and Suggested levels, rebuilt (release 5): each opens from the Purchasing menu and shows
      // its list loaded (rows, or its "nothing here" words); at one size the "Own figure" dialog is drawn and left.
      for (const [link, path, table, empty] of [['Reorder levels', /\/office\/reorder-levels$/, 'Reorder levels', 'No product has its own figure'],
        ['What to order', /\/office\/what-to-order$/, 'What to order', 'Nothing to order'], ['Suggested levels', /\/office\/suggested-levels$/, 'Suggested levels', 'No suggestions']]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Purchasing/ }).first().click()
        const item = page.getByRole('navigation', { name: 'Purchasing' }).getByRole('link', { name: menuName(link) }).first()
        if (!(await item.isVisible().catch(() => false))) { await page.keyboard.press('Escape'); continue } // Suggested levels is hidden while smart levels are off
        await item.click()
        await page.waitForURL(path, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await page.getByText(empty).or(page.getByRole('table', { name: table }).getByRole('row').nth(1)).first().waitFor({ timeout: 30000 })
          .catch(() => problems.push(`${look} ${w}: ${link} showed neither rows nor "${empty}"`))
        await page.screenshot({ path: out(`${link.replace(/ *\/ */g, ' ').replaceAll(' ', '-').toLowerCase()}-${look}-${w}`) })
        if (link === 'Reorder levels' && look === 'top' && w === 1366) {
          const add = page.getByRole('button', { name: 'Own figure for a product' })
          if (await add.waitFor({ timeout: 10000 }).then(() => true).catch(() => false)) {
            await add.click()
            const d = page.getByRole('dialog')
            await d.waitFor({ timeout: 10000 }).catch(() => problems.push('reorder levels: Own figure did not open'))
            await page.screenshot({ path: out('reorder-levels-own') })
            await d.getByRole('button', { name: 'Back' }).click()
            await d.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => undefined)
          }
        }
      }

      // Expense claims, rebuilt (release 6): from the Finance menu, rows (or "No expense claims match"); a row opens the claim;
      // at one size the New claim form is drawn (Submit off while empty) and left.
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Finance/ }).first().click()
      await page.getByRole('navigation', { name: 'Finance' }).getByRole('link', { name: menuName('Expense claims') }).first().click()
      await page.waitForURL(/\/office\/expense-claims$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Expense claims did not open the new screen (${page.url()})`))
      const ecRow = page.getByRole('table', { name: 'Expense claims' }).getByRole('row').nth(1)
      await page.getByText('No expense claims match').or(ecRow).first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: Expense claims showed neither rows nor "No expense claims match"`))
      await page.screenshot({ path: out(`expense-claims-${look}-${w}`) })
      if (await ecRow.isVisible().catch(() => false)) {
        await ecRow.click()
        await page.waitForURL(/\/office\/expense-claims\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: an expense claim did not open`))
        await page.getByRole('region', { name: 'The claim' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: an expense claim shows nothing`))
        await page.screenshot({ path: out(`expense-claim-${look}-${w}`) })
        await page.goBack()
      }
      if (look === 'top' && w === 1366) {
        const newClaim = page.getByRole('button', { name: 'New claim' })
        if (await newClaim.waitFor({ timeout: 10000 }).then(() => true).catch(() => false)) {
          await newClaim.click()
          await page.waitForURL(/\/office\/expense-claims\/new$/, { timeout: 15000 }).catch(() => problems.push('expense claim form: did not open'))
          await page.getByRole('region', { name: 'The claim' }).waitFor({ timeout: 10000 }).catch(() => problems.push('expense claim form: no form'))
          if (await page.getByRole('button', { name: 'Submit the claim' }).isEnabled()) problems.push('expense claim form: Submit is on with nothing filled in')
          await page.screenshot({ path: out('expense-claim-form') })
          await cancelForm() // nothing is saved
        }
      }

      // Supplier bills, rebuilt (release 6): from the Finance menu, rows (or "No supplier bills match"); a row opens the bill;
      // at one size Enter a bill is opened, a supplier chosen and what is still to bill shown (nothing saved).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Finance/ }).first().click()
      await page.getByRole('navigation', { name: 'Finance' }).getByRole('link', { name: menuName('Supplier bills') }).first().click()
      await page.waitForURL(/\/office\/supplier-bills$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Supplier bills did not open the new screen (${page.url()})`))
      const sbRow = page.getByRole('table', { name: 'Supplier bills' }).getByRole('row').nth(1)
      await page.getByText('No supplier bills match').or(sbRow).first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: Supplier bills showed neither rows nor "No supplier bills match"`))
      await page.screenshot({ path: out(`supplier-bills-${look}-${w}`) })
      if (await sbRow.isVisible().catch(() => false)) {
        await sbRow.click()
        await page.waitForURL(/\/office\/supplier-bills\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a supplier bill did not open`))
        await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a supplier bill shows no lines`))
        await page.screenshot({ path: out(`supplier-bill-${look}-${w}`), fullPage: true })
        await page.goBack()
      }
      if (look === 'top' && w === 1366) {
        const enter = page.getByRole('button', { name: 'Enter a bill' })
        if (await enter.waitFor({ timeout: 10000 }).then(() => true).catch(() => false)) {
          await enter.click()
          await page.waitForURL(/\/office\/supplier-bills\/new$/, { timeout: 15000 }).catch(() => problems.push('supplier bill form: did not open'))
          const sup = page.getByRole('combobox', { name: /^Supplier/ })
          await sup.click()
          await page.getByRole('listbox').getByRole('option').first().click().catch(() => problems.push('supplier bill form: no supplier to choose'))
          const goods = page.getByRole('region', { name: 'Goods received' })
          await goods.getByText(/Add everything still to bill from|Nothing received from this supplier/).first().waitFor({ timeout: 15000 })
            .catch(() => problems.push('supplier bill form: choosing a supplier did not show what is still to bill'))
          if (await page.getByRole('button', { name: 'Create the bill' }).isEnabled()) problems.push('supplier bill form: Create is on with no lines')
          await page.screenshot({ path: out('supplier-bill-form'), fullPage: true })
          await cancelForm() // nothing is saved
        }
      }

      // Supplier payments and Aged payables, rebuilt (release 6): both open from the Finance menu and load; at one size the
      // Pay a supplier dialog is drawn with a supplier and an amount, and left (nothing paid).
      for (const [link, path, ready] of [['Supplier payments', /\/office\/supplier-payments$/, () => page.getByText('No supplier payments match').or(page.getByRole('table', { name: 'Supplier payments' }).getByRole('row').nth(1)).first()],
        ['Aged payables', /\/office\/aged-payables$/, () => page.getByRole('region', { name: 'Owed to suppliers' })],
        ['Withholding tax', /\/office\/withholding-tax$/, () => page.getByRole('region', { name: 'Payments that kept tax back' })],
        ['Reports', /\/office\/financial-reports$/, () => page.getByRole('region', { name: 'Trial balance' })],
        ['Chart of accounts', /\/office\/accounts$/, () => page.getByRole('region', { name: 'Chart of accounts' })],
        ['Month-end', /\/office\/month-end$/, () => page.getByRole('region', { name: 'Months' })],
        ['Budgets', /\/office\/budgets$/, () => page.getByRole('region', { name: 'Budget' })],
        ['Money moved', /\/office\/money-moved$/, () => page.getByText('No money moved yet').or(page.getByRole('table', { name: 'Money moved' }).getByRole('row').nth(1)).first()],
        ['Bank & cash', /\/office\/bank-accounts$/, () => page.getByRole('region', { name: 'Bank and cash accounts' })],
        ['Fixed assets', /\/office\/fixed-assets$/, () => page.getByText('No fixed assets match').or(page.getByRole('table', { name: 'Fixed assets' }).getByRole('row').nth(1)).first()],
        ['GRA E-VAT', /\/office\/gra-evat$/, () => page.getByText('Nothing reported to GRA matches').or(page.getByRole('table', { name: 'GRA reports' }).getByRole('row').nth(1)).first()],
        ['Export to QuickBooks / Xero / Sage', /\/office\/accounting-export$/, () => page.getByRole('region', { name: 'Export the books' })],
        ['Journals', /\/office\/journals$/, () => page.getByText('No journals match').or(page.getByRole('table', { name: 'Journals' }).getByRole('row').nth(1)).first()]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Finance/ }).first().click()
        await page.getByRole('navigation', { name: 'Finance' }).getByRole('link', { name: menuName(link) }).first().click()
        await page.waitForURL(path, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`${link.replace(/ *\/ */g, ' ').replaceAll(' ', '-').toLowerCase()}-${look}-${w}`) })
      }
      // Users, rebuilt (settings and admin, part 1): opens from People and lists users; the first one opens with its access
      // (nothing is changed).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /People/ }).first().click()
      await page.getByRole('navigation', { name: 'People' }).getByRole('link', { name: menuName('Users') }).first().click()
      await page.waitForURL(/\/office\/users$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Users did not open the new screen (${page.url()})`))
      const firstUser = page.getByRole('table', { name: 'Users' }).getByRole('row').nth(1)
      await firstUser.waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Users did not load`))
      await page.screenshot({ path: out(`users-${look}-${w}`) })
      if (await firstUser.count()) {
        await firstUser.click()
        await page.getByRole('region', { name: 'Access' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: a user did not open with their access`))
        await page.screenshot({ path: out(`user-${look}-${w}`), fullPage: true })
      }
      // Roles and access requests, rebuilt (settings and admin, part 2): Roles lists and the first opens with its permissions;
      // Access requests opens from the menu (nothing is changed or decided).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /People/ }).first().click()
      await page.getByRole('navigation', { name: 'People' }).getByRole('link', { name: menuName('Roles') }).first().click()
      await page.waitForURL(/\/office\/roles$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Roles did not open the new screen (${page.url()})`))
      const firstRole = page.getByRole('table', { name: 'Roles' }).getByRole('row').nth(1)
      await firstRole.waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Roles did not load`))
      await page.screenshot({ path: out(`roles-${look}-${w}`) })
      if (await firstRole.count()) {
        await firstRole.click()
        await page.getByRole('region', { name: 'Permissions' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: a role did not open with its permissions`))
        await page.screenshot({ path: out(`role-${look}-${w}`) })
      }
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /People/ }).first().click()
      await page.getByRole('navigation', { name: 'People' }).getByRole('link', { name: /^Access requests/ }).first().click()
      await page.waitForURL(/\/office\/access-requests$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Access requests did not open the new screen (${page.url()})`))
      await page.getByText('No access requests.').or(page.getByRole('table', { name: 'Access requests' }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: Access requests did not load`))
      await page.screenshot({ path: out(`access-requests-${look}-${w}`) })
      // Company-wide access, Who can do what, IT and support activity, Staff cards, rebuilt (settings and admin, part 3):
      // each opens from People and loads (nothing is changed or printed).
      for (const [link, path, ready, shot] of [
        [/^Company-wide access/, /\/office\/company-wide-access$/, () => page.getByText('Nobody here.').or(page.getByRole('table', { name: 'Company-wide access' }).getByRole('row').nth(1)).first(), 'company-wide-access'],
        [/^Who can do what/, /\/office\/who-can-do-what$/, () => page.getByRole('region', { name: "Everyone's access" }), 'who-can-do-what'],
        [/^IT and support activity/, /\/office\/it-activity$/, () => page.getByText('Nothing in this period').or(page.getByRole('table', { name: 'IT and support activity' }).getByRole('row').nth(1)).first(), 'it-activity'],
        [/^Staff cards/, /\/office\/staff-cards$/, () => page.getByRole('table', { name: 'Staff cards' }).getByRole('row').nth(1), 'staff-cards']]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /People/ }).first().click()
        await page.getByRole('navigation', { name: 'People' }).getByRole('link', { name: link }).first().click()
        await page.waitForURL(path, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${shot} did not open the new screen (${page.url()})`))
        await ready().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${shot} did not load`))
        await page.screenshot({ path: out(`${shot}-${look}-${w}`) })
      }
      // Settings and Modules, rebuilt (settings and admin, part 4): both open from Setup and load (nothing is saved).
      for (const [link, path, ready, shot] of [
        ['Settings', /\/office\/settings$/, () => page.getByRole('region', { name: 'Settings' }), 'settings'],
        ['Modules', /\/office\/modules$/, () => page.getByRole('region', { name: 'Optional department modules' }), 'modules'],
        ['Messaging', /\/office\/messaging$/, () => page.getByRole('region', { name: 'SMS' }), 'messaging'],
        ['Templates', /\/office\/message-templates$/, () => page.getByRole('table', { name: 'Message templates' }).getByRole('row').nth(1), 'message-templates'],
        ['Devices', /\/office\/devices$/, () => page.getByText('No devices.').or(page.getByRole('table', { name: 'Devices' }).getByRole('row').nth(1)).first(), 'devices'],
        ['Computers', /\/office\/computers$/, () => page.getByText('No computers yet.').or(page.getByRole('table', { name: 'Computers' }).getByRole('row').nth(1)).first(), 'computers'],
        ['Message log', /\/office\/message-log$/, () => page.getByText('No messages match.').or(page.getByRole('table', { name: 'Message log' }).getByRole('row').nth(1)).first(), 'message-log']]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Setup/ }).first().click()
        await page.getByRole('navigation', { name: 'Setup' }).getByRole('link', { name: menuName(link) }).first().click()
        await page.waitForURL(path, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${shot} did not open the new screen (${page.url()})`))
        await ready().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${shot} did not load`))
        await page.screenshot({ path: out(`${shot}-${look}-${w}`) })
        if (shot === 'devices') { // Register a device opens with its branches, and is left (nothing registered)
          await page.getByRole('button', { name: 'Register a device' }).click()
          await page.getByRole('dialog').getByText('Till (for POS devices)').waitFor({ timeout: 10000 }).catch(() => problems.push(`${look} ${w}: Register a device did not open`))
          await page.screenshot({ path: out(`device-register-${look}-${w}`) })
          await page.getByRole('dialog').getByRole('button', { name: 'Back' }).click()
        }
        if (shot === 'settings') { // the search opens the groups that match, with their fields
          await page.getByRole('searchbox', { name: 'Find a setting' }).fill('receipt')
          await page.getByText(/Showing the settings with/).waitFor({ timeout: 10000 }).catch(() => problems.push(`${look} ${w}: settings search did not filter`))
          await page.screenshot({ path: out(`settings-found-${look}-${w}`) })
        }
      }
      // Catalogue and pricing lists, rebuilt (part 7): each opens from Stock and loads; the first category opens its form (left).
      for (const [link, path, table] of [[/^Categories/, /\/office\/categories$/, 'Categories'], [/^Brands/, /\/office\/brands$/, 'Brands'],
        [/^Units of measure/i, /\/office\/units$/, 'Units of measure'], [/^Tax rates/i, /\/office\/tax-rates$/, 'Tax rates'], [/^Tax groups/i, /\/office\/tax-groups$/, 'Tax groups'],
        [/^Price lists/i, /\/office\/price-lists$/, 'Price lists'], [/^Pricing rules/i, /\/office\/pricing-rules$/, 'Pricing rules']]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first().click()
        await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: link }).first().click()
        await page.waitForURL(path, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${table} did not open the new screen (${page.url()})`))
        const first = page.getByRole('table', { name: table }).getByRole('row').nth(1)
        await (table === 'Pricing rules' ? page.getByText(/No pricing rules/).or(first).first() : first).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${table} did not load`))
        await page.screenshot({ path: out(`${table.replaceAll(' ', '-').toLowerCase()}-${look}-${w}`) })
        if (table === 'Price lists' && await first.count()) {
          await first.click()
          await page.getByRole('dialog').getByText('Prices include tax').waitFor({ timeout: 10000 }).catch(() => problems.push(`${look} ${w}: a price list did not open its form`))
          await page.screenshot({ path: out(`price-list-form-${look}-${w}`) })
          await page.getByRole('dialog').getByRole('button', { name: 'Back' }).click()
        }
      }
      // Prices to review, rebuilt (part 8): opens from Stock and loads (nothing is approved).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first().click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: /^Prices to review/i }).first().click()
      await page.waitForURL(/\/office\/prices-to-review$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Prices to review did not open the new screen (${page.url()})`))
      await page.getByText('No prices waiting.').or(page.getByRole('table', { name: 'Prices to review' }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: Prices to review did not load`))
      await page.screenshot({ path: out(`prices-to-review-${look}-${w}`) })
      // Promotions, rebuilt (part 9): opens from Stock and loads; New promotion shows the form with its sections (nothing is saved).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first().click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: /^Promotions/i }).first().click()
      await page.waitForURL(/\/office\/promotions$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Promotions did not open the new screen (${page.url()})`))
      await page.getByText(/No promotions here/).or(page.getByRole('table', { name: 'Promotions' }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: Promotions did not load`))
      await page.screenshot({ path: out(`promotions-${look}-${w}`) })
      const newPromo = page.getByRole('button', { name: 'New promotion' })
      if (await newPromo.count()) {
        await newPromo.click()
        await page.getByRole('dialog').getByText('When and where').waitFor({ timeout: 10000 }).catch(() => problems.push(`${look} ${w}: New promotion did not open its form`))
        await page.waitForTimeout(400) // the dialog fades in
        await page.screenshot({ path: out(`promotion-form-${look}-${w}`) })
        await page.getByRole('dialog').getByRole('button', { name: 'Back' }).click()
        const firstPromo = page.getByRole('table', { name: 'Promotions' }).getByRole('row').nth(1)
        if (await firstPromo.count()) { // an existing one opens with its values filled in
          await firstPromo.click()
          await page.getByRole('dialog').getByRole('textbox', { name: /Name/ }).first().waitFor({ timeout: 10000 }).catch(() => undefined)
          const name = await page.getByRole('dialog').getByRole('textbox', { name: /Name/ }).first().inputValue().catch(() => '')
          if (!name) problems.push(`${look} ${w}: a promotion did not open with its values`)
          await page.getByRole('dialog').getByRole('button', { name: 'Back' }).click()
        }
      }
      // Price list and Shared products, rebuilt (part 10): the price list opens from Stock with its rows and Export;
      // Shared products only where the company has industries (it shows its list or "No shared products").
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first().click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: /^Price list$/i }).first().click()
      await page.waitForURL(/\/office\/price-list$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Price list did not open the new screen (${page.url()})`))
      await page.getByText('No products match.').or(page.getByRole('table', { name: 'Price list' }).getByRole('row').nth(1)).first().waitFor({ timeout: 30000 })
        .catch(() => problems.push(`${look} ${w}: Price list did not load`))
      if (!await page.getByRole('button', { name: /Export/ }).count()) problems.push(`${look} ${w}: Price list has no Export`)
      await page.screenshot({ path: out(`price-list-${look}-${w}`) })
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first().click()
      const shared = page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: /^Shared products/i }).first()
      if (await shared.count()) {
        await shared.click()
        await page.waitForURL(/\/office\/shared-products$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Shared products did not open the new screen (${page.url()})`))
        await page.getByText(/No shared products/).or(page.getByRole('table', { name: 'Shared products' }).getByRole('row').nth(1)).first().waitFor({ timeout: 30000 })
          .catch(() => problems.push(`${look} ${w}: Shared products did not load`))
        await page.screenshot({ path: out(`shared-products-${look}-${w}`) })
      } else await page.keyboard.press('Escape')
      // Pharmacy, rebuilt (part 11), where the pharmacy module is on: each screen opens from Industries → Pharmacy and loads.
      const industries = () => page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Industries/ }).first()
      let pharmacy = false
      if (await industries().count()) {
        await industries().click()
        pharmacy = await page.getByRole('navigation', { name: 'Industries' }).getByRole('link', { name: /^Prescriptions/i }).count() > 0
        await page.keyboard.press('Escape')
      }
      if (pharmacy) {
        for (const [link, url, table, empty] of [
          [/^Prescriptions/i, /\/office\/prescriptions$/, 'Prescriptions', 'No prescriptions dispensed yet.'],
          [/^Drug interactions/i, /\/office\/drug-interactions$/, 'Drug interactions', /No interactions yet/],
          [/^Refill reminders/i, /\/office\/refill-reminders$/, 'Refill reminders', 'No reminders here.'],
          [/^Controlled-drug register/i, /\/office\/controlled-drug-register/, null, /No controlled medicines moved|Opening /],
          [/^NHIS claims/i, /\/office\/nhis-claims$/, 'NHIS claims', /No NHIS claims yet/],
          [/^Sales to sign off/i, /\/office\/medicine-signoffs$/, 'Sales to sign off', /Nothing to sign off/],
          [/^Health checks/i, /\/office\/health-checks$/, 'Health checks', 'No readings yet.'],
          [/^Near-expiry markdowns/i, /\/office\/pharmacy\/markdowns/, null, /Expiring soon/]]) {
          await industries().click()
          const a = page.getByRole('navigation', { name: 'Industries' }).getByRole('link', { name: link }).first()
          if (!await a.count()) { problems.push(`${look} ${w}: Industries → Pharmacy has no ${link} link`); await page.keyboard.press('Escape'); continue }
          await a.click()
          await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
          const loaded = table ? page.getByText(empty).or(page.getByRole('table', { name: table }).getByRole('row').nth(1)).first() : page.getByText(empty).first()
          await loaded.waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
          await page.screenshot({ path: out(`pharmacy-${String(table ?? (String(url).includes('markdowns') ? 'markdowns' : 'register')).toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        }
      }
      // Expiry & returns, rebuilt (part 13): opens from Stock with its list (or "Nothing here").
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first().click()
      const expiry = page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: /^Expiry & returns/i }).first()
      if (await expiry.count()) {
        await expiry.click()
        await page.waitForURL(/\/office\/expiry/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Expiry & returns did not open the new screen (${page.url()})`))
        await page.getByText(/Nothing here/).or(page.getByRole('table', { name: 'Expiring batches' }).getByRole('row').nth(1)).first().waitFor({ timeout: 30000 })
          .catch(() => problems.push(`${look} ${w}: Expiry & returns did not load`))
        // Its filters come in a second answer, which may arrive after the rows.
        await page.waitForFunction(() => /\S/.test(document.querySelector('[aria-label="Show"]')?.textContent ?? ''), null, { timeout: 15000 })
          .catch(() => problems.push(`${look} ${w}: Expiry & returns did not load its filters`))
        await page.screenshot({ path: out(`expiry-${look}-${w}`) })
      } else await page.keyboard.press('Escape')
      // The Cash area, rebuilt (part 14): each screen opens from Sales → Cash and loads (Cash desk and Income control only with cash control on).
      for (const [link, url, region] of [['Close the day', /\/office\/close-day/, 'Close the day'], ['Cash desk', /\/office\/cash-desk/, 'Vaults'],
        ['Income control', /\/office\/income-control/, 'Handovers to receive'], ['Shifts and vaults', /\/office\/cash-setup/, 'Shifts']]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
        const a = page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName(link) }).first()
        if (!await a.count()) {
          if (link === 'Close the day' || link === 'Shifts and vaults') problems.push(`${look} ${w}: Sales → Cash has no ${link} link`)
          await page.keyboard.press('Escape')
          continue
        }
        await a.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await page.getByRole('region', { name: region, exact: true }).waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`cash-${link.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
      }
      // Quotes & orders, Payments received and Aged receivables, rebuilt (part 15): each opens from Sales; a quote or order
      // opens with its lines, and New quotation opens the form (nothing is saved).
      const salesLink = async (name) => {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName(name) }).first()
        if (!await l.count()) { problems.push(`${look} ${w}: Sales has no ${name} link`); await page.keyboard.press('Escape'); return false }
        await l.click()
        return true
      }
      if (await salesLink('Quotes & orders')) {
        await page.waitForURL(/\/office\/customer-orders$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Quotes & orders did not open the new screen (${page.url()})`))
        const firstOrder = page.getByRole('table', { name: 'Quotes and orders' }).getByRole('row').nth(1)
        await page.getByText(/No quotes or orders match/).or(firstOrder).first().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Quotes & orders did not load`))
        await page.screenshot({ path: out(`customer-orders-${look}-${w}`) })
        if (await firstOrder.count()) {
          await firstOrder.click()
          await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: a quote or order did not open with its lines`))
          await page.screenshot({ path: out(`customer-order-${look}-${w}`), fullPage: true })
          await page.goBack()
        }
        // The button comes with the page's options, a moment after the list: wait for it (the owner may always quote).
        const newQuote = page.getByRole('button', { name: 'New quotation' })
        await newQuote.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Quotes & orders has no New quotation`))
        if (await newQuote.count()) {
          await newQuote.click()
          await page.getByRole('region', { name: 'Quotation' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: New quotation did not open the form`))
          if (await page.getByRole('button', { name: 'Save quotation' }).isEnabled()) problems.push(`${look} ${w}: Save quotation is on with nothing filled in`)
          await page.screenshot({ path: out(`customer-order-form-${look}-${w}`) })
          await cancelForm()
        }
      }
      if (await salesLink('Payments received')) {
        await page.waitForURL(/\/office\/customer-payments$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Payments received did not open the new screen (${page.url()})`))
        await page.getByText(/No payments match/).or(page.getByRole('table', { name: 'Payments received' }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 })
          .catch(() => problems.push(`${look} ${w}: Payments received did not load`))
        await page.screenshot({ path: out(`customer-payments-${look}-${w}`) })
      }
      if (await salesLink('Aged receivables')) {
        await page.waitForURL(/\/office\/aged-receivables$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Aged receivables did not open the new screen (${page.url()})`))
        await page.getByRole('region', { name: 'Owed by customers' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Aged receivables did not load`))
        await page.screenshot({ path: out(`aged-receivables-${look}-${w}`) })
      }
      // Till requests, Back-office returns, Review queue and Gift cards & credit, rebuilt (part 16): each opens from Sales and
      // shows its list (or its empty line); a back-office return opens with its goods.
      for (const [link, url, table, empty] of [['Till requests', /\/office\/till-requests/, 'Till requests', /Nothing waiting/],
        ['Back-office returns', /\/office\/office-returns$/, 'Back-office returns', /No returns match/], ['Review queue', /\/office\/review-queue$/, 'Review queue', /Nothing to review/],
        ['Gift cards & credit', /\/office\/gift-cards$/, 'Gift cards and credit', /No gift cards/]]) {
        if (!await salesLink(link)) continue
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        const first = page.getByRole('table', { name: table }).getByRole('row').nth(1)
        await page.getByText(empty).or(first).first().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`${link.split(' ')[0].toLowerCase()}-desk-${look}-${w}`) })
        if (link === 'Back-office returns' && await first.count()) {
          await first.click()
          await page.getByRole('region', { name: 'Goods' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: a back-office return did not open with its goods`))
          await page.screenshot({ path: out(`office-return-${look}-${w}`) })
          await page.goBack()
        }
      }
      // Returns to suppliers, Waybills, Bins and My day, rebuilt (part 17): each opens from Stock with its list (or its empty
      // line); a return to a supplier opens with its lines; My day shows its figures.
      for (const [link, url, ready] of [
        ['Returns to suppliers', /\/office\/supplier-returns$/, () => page.getByText(/No returns match/).or(page.getByRole('table', { name: 'Returns to suppliers' }).getByRole('row').nth(1))],
        ['Waybills', /\/office\/waybills$/, () => page.getByText(/No waybills match/).or(page.getByRole('table', { name: 'Waybills' }).getByRole('row').nth(1))],
        ['Bins', /\/office\/bins$/, () => page.getByText(/No bins/).or(page.getByRole('table', { name: 'Bins' }).getByRole('row').nth(1))],
        ['My day', /\/office\/my-day/, () => page.getByText('Low stock', { exact: true })]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { problems.push(`${look} ${w}: Stock has no ${link} link`); await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`${link.split(' ')[0].toLowerCase()}-floor-${look}-${w}`) })
        const firstReturn = page.getByRole('table', { name: 'Returns to suppliers' }).getByRole('row').nth(1)
        if (link === 'Returns to suppliers' && await firstReturn.count()) {
          await firstReturn.click()
          await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: a return to a supplier did not open with its lines`))
          await page.screenshot({ path: out(`supplier-return-${look}-${w}`) })
          await page.goBack()
        }
      }
      // CRM, rebuilt (part 25): each opens from Sales and loads; New lead and New deal open their forms (nothing is saved).
      for (const [link, url, ready] of [
        ['Pipeline', /\/office\/crm\/pipeline/, () => page.getByRole('region', { name: 'Pipeline' })],
        ['Leads', /\/office\/crm\/leads/, () => page.getByText('No leads match.').or(page.getByRole('table', { name: 'Leads' }).getByRole('row').nth(1))],
        ['Deals', /\/office\/crm\/deals/, () => page.getByText('No deals match.').or(page.getByRole('table', { name: 'Deals' }).getByRole('row').nth(1))],
        ['Follow-ups', /\/office\/crm\/follow-ups/, () => page.getByText('Nothing to follow up.').or(page.getByRole('table', { name: 'Follow-ups' }).getByRole('row').nth(1))],
        ['Campaigns', /\/office\/crm\/campaigns$/, () => page.getByRole('region', { name: 'Campaigns' })],
        ['Pipeline stages', /\/office\/crm\/stages/, () => page.getByRole('table', { name: 'Pipeline stages' }).getByRole('row').nth(1)]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { problems.push(`${look} ${w}: Sales has no ${link} link`); await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`crm-${link.split(' ')[0].toLowerCase()}${link.includes(' ') ? '-' + link.split(' ')[1] : ''}-${look}-${w}`) })
        for (const [button, form] of [['New lead', 'Lead'], ['New deal', 'Deal']]) {
          const b = page.getByRole('button', { name: button })
          if ((link === 'Leads' && button === 'New lead') || (link === 'Deals' && button === 'New deal')) {
            await b.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} has no ${button}`))
            if (await b.count()) {
              await b.click()
              await page.getByRole('region', { name: form, exact: true }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${button} did not open the form`))
              await page.screenshot({ path: out(`crm-${form.toLowerCase()}-form-${look}-${w}`) })
              await cancelForm()
            }
          }
        }
      }
      // Projects, rebuilt (part 27), where the projects add-on is on: each opens from Sales and loads; New project opens its
      // form, the first project opens with its figures and costs, and Add time opens its dialog (nothing is saved).
      for (const [link, url, ready] of [
        ['Projects', /\/office\/projects$/, () => page.getByText('No projects match.').or(page.getByRole('table', { name: 'Projects' }).getByRole('row').nth(1))],
        ['My time', /\/office\/my-time/, () => page.getByText(/^No time this week/).or(page.getByRole('table', { name: 'My time' }).getByRole('row').nth(1))],
        ['Time to approve', /\/office\/time-approvals/, () => page.getByRole('region', { name: 'Nothing to approve' }).or(page.getByRole('button', { name: /^Approve ticked/ }))],
        ['Hourly rates', /\/office\/project-rates/, () => page.getByText(/Nobody logs time on projects yet/).or(page.getByRole('table', { name: 'Hourly rates' }).getByRole('row').nth(1))]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`projects-${link.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        if (link === 'Projects') {
          const first = page.getByRole('table', { name: 'Projects' }).getByRole('row').nth(1)
          if (await first.count() && !await page.getByText('No projects match.').count()) {
            await first.click()
            await page.getByRole('region', { name: 'How it is doing' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: a project did not open with its figures`))
            await page.getByRole('region', { name: 'Costs' }).waitFor({ timeout: 10000 }).catch(() => problems.push(`${look} ${w}: a project did not show its costs`))
            await page.screenshot({ path: out(`projects-one-${look}-${w}`) })
            await page.goBack()
          }
          const b = page.getByRole('button', { name: 'New project' })
          // After going back the list loads again: wait for its button rather than counting at once.
          if (await b.waitFor({ timeout: 15000 }).then(() => true, () => false)) {
            await b.click()
            await page.getByRole('region', { name: 'Project', exact: true }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: New project did not open the form`))
            await page.screenshot({ path: out(`projects-new-${look}-${w}`) })
            await cancelForm()
          }
        }
        if (link === 'My time') {
          await page.getByRole('button', { name: 'Add time' }).click()
          const dlg = page.getByRole('dialog')
          await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Add time did not open its dialog`))
          await page.screenshot({ path: out(`projects-add-time-${look}-${w}`) })
          await dlg.getByRole('button', { name: 'Back' }).click().catch(() => page.keyboard.press('Escape'))
          await dlg.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => problems.push(`${look} ${w}: Add time's dialog did not close`))
        }
      }
      // Service desk, rebuilt (part 28), where the add-on is on: Tickets and Deadlines open from Sales and load; the first ticket
      // opens with its conversation, New ticket opens its form and Note its dialog (nothing is saved).
      for (const [link, url, ready] of [
        ['Tickets', /\/office\/tickets$/, () => page.getByText(/^No tickets\./).or(page.getByRole('table', { name: 'Tickets' }).getByRole('row').nth(1))],
        ['Deadlines', /\/office\/service-levels/, () => page.getByRole('table', { name: 'Deadlines' }).getByRole('row').nth(1)]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`desk-${link.toLowerCase()}-${look}-${w}`) })
        if (link !== 'Tickets') continue
        const first = page.getByRole('table', { name: 'Tickets' }).getByRole('row').nth(1)
        if (await first.count() && !await page.getByText(/^No tickets\./).count()) {
          await first.click()
          await page.getByRole('region', { name: 'Conversation' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: a ticket did not open with its conversation`))
          await page.screenshot({ path: out(`desk-ticket-${look}-${w}`) })
          const note = page.getByRole('button', { name: 'Note', exact: true })
          if (await note.count()) {
            await note.click()
            const dlg = page.getByRole('dialog')
            await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Note did not open its dialog`))
            await dlg.getByRole('button', { name: 'Back' }).click().catch(() => page.keyboard.press('Escape'))
            await dlg.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => problems.push(`${look} ${w}: Note's dialog did not close`))
          }
          await page.goBack()
        }
        const b = page.getByRole('button', { name: 'New ticket' })
        if (await b.waitFor({ timeout: 15000 }).then(() => true, () => false)) {
          await b.click()
          await page.getByRole('region', { name: 'Ticket', exact: true }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: New ticket did not open the form`))
          await page.screenshot({ path: out(`desk-new-${look}-${w}`) })
          await cancelForm()
        } else problems.push(`${look} ${w}: Tickets has no New ticket`)
      }
      // Ask your business, Alerts and the AI usage log, rebuilt (part 29): each opens from Insights and loads; a quick question
      // (they work without AI) answers with its table. Nothing is changed.
      for (const [link, url, ready] of [
        ['Ask your business', /\/office\/ask$/, () => page.getByRole('group', { name: 'Quick questions' })],
        ['Alerts', /\/office\/alerts/, () => page.getByText('No alerts match.').or(page.getByRole('table', { name: 'Alerts' }).getByRole('row').nth(1))],
        ['AI usage log', /\/office\/ai-usage/, () => page.getByText('No AI requests yet.').or(page.getByRole('table', { name: 'AI usage log' }).getByRole('row').nth(1))]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Insights/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Insights' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        if (link === 'Ask your business') {
          const first = page.getByRole('group', { name: 'Quick questions' }).getByRole('button').first()
          if (await first.count()) {
            const label = (await first.innerText()).trim()
            await first.click()
            await page.locator('[aria-live="polite"] table').first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: the quick question “${label}” did not answer with a table`))
          } else problems.push(`${look} ${w}: Ask your business has no quick questions`)
        }
        await page.screenshot({ path: out(`insights-${link.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
      }
      // The restaurant, rebuilt (part 30), where the module is on: each page opens from Industries and loads; New booking, New
      // table, New kitchen station and New modifier group open their dialogs (nothing is saved).
      for (const [link, url, ready, button] of [
        ['Bookings', /\/office\/restaurant\/bookings/, () => page.getByText('No bookings match.').or(page.getByRole('table', { name: 'Bookings' }).getByRole('row').nth(1)), 'New booking'],
        ['Bills', /\/office\/restaurant\/bills/, () => page.getByText('No bills match.').or(page.getByRole('table', { name: 'Bills' }).getByRole('row').nth(1)), null],
        ['Floor plan', /\/office\/restaurant\/tables/, () => page.getByText('No tables yet.').or(page.getByRole('table', { name: 'Floor plan' }).getByRole('row').nth(1)), 'New table'],
        ['Kitchen stations', /\/office\/restaurant\/stations/, () => page.getByText('No kitchen stations yet.').or(page.getByRole('table', { name: 'Kitchen stations' }).getByRole('row').nth(1)), 'New kitchen station'],
        ['Modifiers', /\/office\/restaurant\/modifiers/, () => page.getByText('No modifier groups yet.').or(page.getByRole('table', { name: 'Modifiers' }).getByRole('row').nth(1)), 'New modifier group'],
        ['Tips & voids', /\/office\/restaurant\/tips-voids/, () => page.getByRole('region', { name: 'Voided items' }), null]]) {
        const menu = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Industries|Restaurant/ }).first()
        if (!await menu.waitFor({ timeout: 10000 }).then(() => true, () => false)) break
        const tab = /^Restaurant/.test((await menu.innerText()).trim()) ? 'Restaurant' : 'Industries'
        await menu.click()
        const l = page.getByRole('navigation', { name: tab }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`restaurant-${link.toLowerCase().replace(/[ &]+/g, '-')}-${look}-${w}`) })
        if (!button) continue
        const b = page.getByRole('button', { name: button })
        if (!await b.waitFor({ timeout: 10000 }).then(() => true, () => false)) { problems.push(`${look} ${w}: ${link} has no ${button}`); continue }
        await b.click()
        const dlg = page.getByRole('dialog')
        await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${button} did not open its dialog`))
        await page.screenshot({ path: out(`restaurant-${button.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        await dlg.getByRole('button', { name: 'Back' }).click().catch(() => page.keyboard.press('Escape'))
        await dlg.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => problems.push(`${look} ${w}: ${button}'s dialog did not close`))
      }
      // Fashion and Electronics, rebuilt (part 36), where the module is on: each page opens from Industries (or its own tab) and
      // loads; New alteration, New repair job and Register numbers open their dialogs, New style its form (nothing is saved).
      for (const [link, url, ready, button] of [
        ['Styles', /\/office\/fashion\/styles/, () => page.getByText('No styles yet.').or(page.getByRole('table', { name: 'Styles' }).getByRole('row').nth(1)), 'New style'],
        ['Alterations', /\/office\/fashion\/alterations/, () => page.getByText('No alterations yet.').or(page.getByRole('table', { name: 'Alterations' }).getByRole('row').nth(1)), 'New alteration'],
        ['Sell-through & consignment', /\/office\/fashion\/sell-through/, () => page.getByRole('region', { name: 'Sell-through by style' }), null],
        ['Repairs', /\/office\/electronics\/repairs/, () => page.getByText('No repair jobs yet.').or(page.getByRole('table', { name: 'Repairs' }).getByRole('row').nth(1)), 'New repair job'],
        ['Serial numbers', /\/office\/electronics\/serials/, () => page.getByText('No serial numbers yet.').or(page.getByRole('table', { name: 'Serial numbers' }).getByRole('row').nth(1)), 'Register numbers for stock on hand']]) {
        const menu = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Industries|Fashion|Electronics/ }).first()
        if (!await menu.waitFor({ timeout: 10000 }).then(() => true, () => false)) break
        const tab = (await menu.innerText()).trim().match(/^(Fashion|Electronics)/)?.[1] ?? 'Industries'
        await menu.click()
        const l = page.getByRole('navigation', { name: tab }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`industry-${link.toLowerCase().replace(/[ &-]+/g, '-')}-${look}-${w}`) })
        if (!button) continue
        const b = page.getByRole('button', { name: button })
        if (!await b.waitFor({ timeout: 10000 }).then(() => true, () => false)) { problems.push(`${look} ${w}: ${link} has no ${button}`); continue }
        await b.click()
        if (button === 'New style') {
          await page.getByRole('region', { name: 'Sizes and colours' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: New style did not open the form`))
          await page.screenshot({ path: out(`industry-new-style-${look}-${w}`) })
          await cancelForm()
          await page.waitForURL(/\/office\/fashion\/styles\/?$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Cancel did not leave the style form`))
          continue
        }
        const dlg = page.getByRole('dialog')
        await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${button} did not open its dialog`))
        await page.screenshot({ path: out(`industry-${button.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        await dlg.getByRole('button', { name: 'Back' }).click().catch(() => page.keyboard.press('Escape'))
        await dlg.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => problems.push(`${look} ${w}: ${button}'s dialog did not close`))
      }
      // Maintenance, rebuilt (part 31), where the add-on is on: Equipment and Work orders open from Stock and load; the first of
      // each opens (equipment with its plans, a work order with its cost); New work order opens its form (nothing is saved).
      for (const [link, url, table, empty, opened] of [
        ['Equipment', /\/office\/maintenance\/equipment$/, 'Equipment', /^No equipment yet/, 'Servicing plans'],
        ['Work orders', /\/office\/maintenance\/orders/, 'Work orders', /^No work orders/, 'Cost']]) {
        const menu = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first()
        if (!await menu.waitFor({ timeout: 10000 }).then(() => true, () => false)) break
        await menu.click()
        const l = page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        const first = page.getByRole('table', { name: table }).getByRole('row').nth(1)
        await page.getByText(empty).or(first).first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`maintenance-${link.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        if (await first.count() && !await page.getByText(empty).count()) {
          await first.click()
          await page.getByRole('region', { name: opened }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: the first of ${link} did not open with its ${opened}`))
          await page.screenshot({ path: out(`maintenance-${link.toLowerCase().replace(/ /g, '-')}-one-${look}-${w}`) })
          await page.goBack()
        }
        if (link === 'Work orders') {
          const b = page.getByRole('button', { name: 'New work order' })
          if (await b.waitFor({ timeout: 15000 }).then(() => true, () => false)) {
            await b.click()
            await page.getByRole('region', { name: 'Work order', exact: true }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: New work order did not open the form`))
            await page.screenshot({ path: out(`maintenance-new-order-${look}-${w}`) })
            await cancelForm()
          } else problems.push(`${look} ${w}: Work orders has no New work order`)
        }
      }
      // Sensors, rebuilt (part 32), where the add-on is on: the four pages open from Stock and load; the first sensor opens
      // with its reading now, and Record a check opens its dialog (nothing is signed).
      for (const [link, url, ready] of [
        ['Sensors', /\/office\/sensors$/, () => page.getByText(/^No sensors yet/).or(page.getByRole('table', { name: 'Sensors' }).getByRole('row').nth(1))],
        ['Alarms', /\/office\/sensors\/alarms/, () => page.getByRole('region', { name: 'Alarms' })],
        ['Temperature log', /\/office\/sensors\/log/, () => page.getByText(/^Add a fridge, cold room/).or(page.getByRole('region', { name: 'Temperature log' }))],
        ['Sensor clouds', /\/office\/sensors\/clouds/, () => page.getByText('No sensor clouds.').or(page.getByRole('table', { name: 'Sensor clouds' }).getByRole('row').nth(1))]]) {
        const menu = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first()
        if (!await menu.waitFor({ timeout: 10000 }).then(() => true, () => false)) break
        await menu.click()
        const l = page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`sensors-${link.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        if (link !== 'Sensors') continue
        const first = page.getByRole('table', { name: 'Sensors' }).getByRole('row').nth(1)
        if (await first.count() && !await page.getByText(/^No sensors yet/).count()) {
          await first.click()
          await page.getByRole('region', { name: 'Now' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: a sensor did not open with its reading now`))
          await page.screenshot({ path: out(`sensors-one-${look}-${w}`) })
          const b = page.getByRole('button', { name: 'Record a check' })
          if (await b.count()) {
            await b.click()
            const dlg = page.getByRole('dialog')
            await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Record a check did not open its dialog`))
            await dlg.getByRole('button', { name: 'Back' }).click().catch(() => page.keyboard.press('Escape'))
            await dlg.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => problems.push(`${look} ${w}: Record a check's dialog did not close`))
          }
          await page.goBack()
        }
      }
      // Online, rebuilt (part 33): Online orders, Channels and WhatsApp orders (where switched on) open from Sales and load; the
      // first order opens with its items, and New channel opens its form (nothing is saved).
      for (const [link, url, table, empty, opened] of [
        ['Online orders', /\/office\/online\/orders/, 'Online orders', 'No online orders.', 'Items'],
        ['Channels', /\/office\/online\/channels$/, 'Channels', /^No online channels yet/, 'Channel'],
        ['WhatsApp orders', /\/office\/online\/whatsapp/, 'WhatsApp orders', /^No WhatsApp orders waiting/, 'Message']]) {
        const menu = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first()
        if (!await menu.waitFor({ timeout: 10000 }).then(() => true, () => false)) break
        await menu.click()
        const l = page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        const first = page.getByRole('table', { name: table }).getByRole('row').nth(1)
        await page.getByText(empty).or(first).first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`online-${link.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        if (await first.count() && !await page.getByText(empty).count()) {
          await first.click()
          await page.getByRole('region', { name: opened, exact: true }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: the first of ${link} did not open with its ${opened}`))
          await page.screenshot({ path: out(`online-${link.toLowerCase().replace(/ /g, '-')}-one-${look}-${w}`) })
          await page.goBack()
        }
        if (link === 'Channels') {
          const b = page.getByRole('button', { name: 'New channel' })
          if (await b.waitFor({ timeout: 15000 }).then(() => true, () => false)) {
            await b.click()
            await page.getByRole('region', { name: 'Channel', exact: true }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: New channel did not open the form`))
            await page.screenshot({ path: out(`online-new-channel-${look}-${w}`) })
            await cancelForm()
          } else problems.push(`${look} ${w}: Channels has no New channel`)
        }
      }
      // Sales & distribution, rebuilt (part 34), where the module is on: each page opens from More (or its own tab) and
      // loads; New van and New route open their dialogs (nothing is saved). Pages not in the menu are skipped.
      for (const [link, url, ready, button] of [
        ['Vans', /\/office\/distribution\/vans/, () => page.getByText('No vans yet.').or(page.getByRole('table', { name: 'Vans' }).getByRole('row').nth(1)), 'New van'],
        ['Van settlements', /\/office\/distribution\/settlements/, () => page.getByText(/^No settlements yet/).or(page.getByRole('table', { name: 'Van settlements' }).getByRole('row').nth(1)), null],
        ['Routes', /\/office\/distribution\/routes/, () => page.getByText('No routes yet.').or(page.getByRole('table', { name: 'Routes' }).getByRole('row').nth(1)), 'New route'],
        ['Visits', /\/office\/distribution\/visits/, () => page.getByText('No visits match.').or(page.getByRole('table', { name: 'Visits' }).getByRole('row').nth(1)), null],
        ['Rep targets', /\/office\/distribution\/targets/, () => page.getByText('No targets yet.').or(page.getByRole('table', { name: 'Rep targets' }).getByRole('row').nth(1)), 'Set a target'],
        ['Rep performance', /\/office\/distribution\/performance/, () => page.getByRole('region', { name: 'Reps against target' }), null]]) {
        // Distribution is not one of the named tabs, so it sits under More (a More holding only Distribution takes its name).
        const menu = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /^(More|Distribution)\b/ }).first()
        if (!await menu.waitFor({ timeout: 10000 }).then(() => true, () => false)) break
        const tab = /^Distribution/.test((await menu.innerText()).trim()) ? 'Distribution' : 'More'
        await menu.click()
        const l = page.getByRole('navigation', { name: tab }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`distribution-${link.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        if (!button) continue
        const b = page.getByRole('button', { name: button })
        if (!await b.waitFor({ timeout: 10000 }).then(() => true, () => false)) { problems.push(`${look} ${w}: ${link} has no ${button}`); continue }
        await b.click()
        const dlg = page.getByRole('dialog')
        await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${button} did not open its dialog`))
        await page.screenshot({ path: out(`distribution-${button.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        await dlg.getByRole('button', { name: 'Back' }).click().catch(() => page.keyboard.press('Escape'))
        await dlg.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => problems.push(`${look} ${w}: ${button}'s dialog did not close`))
      }
      // Manufacturing, rebuilt (part 35), where the module is on: each page opens from More (or its own tab) and loads; New
      // work order and New work centre open their dialogs, New bill of materials its form (nothing is saved).
      for (const [link, url, ready, button] of [
        ['Work orders', /\/office\/manufacturing\/orders/, () => page.getByText(/^No work orders\. Raise one/).or(page.getByRole('table', { name: 'Work orders' }).getByRole('row').nth(1)), 'New work order'],
        ['Bills of materials', /\/office\/manufacturing\/boms/, () => page.getByText('No bills of materials yet.').or(page.getByRole('table', { name: 'Bills of materials' }).getByRole('row').nth(1)), 'New bill of materials'],
        ['Production planning (MRP)', /\/office\/manufacturing\/planning/, () => page.getByRole('region', { name: 'To make' }), null],
        ['Work centres', /\/office\/manufacturing\/centres/, () => page.getByText('No work centres yet.').or(page.getByRole('table', { name: 'Work centres' }).getByRole('row').nth(1)), 'New work centre']]) {
        // Manufacturing is not one of the named tabs, so it sits under More (a More holding only Manufacturing takes its name).
        const menu = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /^(More|Manufacturing)\b/ }).first()
        if (!await menu.waitFor({ timeout: 10000 }).then(() => true, () => false)) break
        const tab = /^Manufacturing/.test((await menu.innerText()).trim()) ? 'Manufacturing' : 'More'
        await menu.click()
        const l = page.getByRole('navigation', { name: tab }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`manufacturing-${link.toLowerCase().replace(/[ ()]+/g, '-')}-${look}-${w}`) })
        if (!button) continue
        const b = page.getByRole('button', { name: button })
        if (!await b.waitFor({ timeout: 10000 }).then(() => true, () => false)) { problems.push(`${look} ${w}: ${link} has no ${button}`); continue }
        await b.click()
        if (button === 'New bill of materials') {
          await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: New bill of materials did not open the form`))
          await page.screenshot({ path: out(`manufacturing-new-bom-${look}-${w}`) })
          await cancelForm()
          await page.waitForURL(/\/office\/manufacturing\/boms\/?$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Cancel did not leave the BOM form`))
          continue
        }
        const dlg = page.getByRole('dialog')
        await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${button} did not open its dialog`))
        await page.screenshot({ path: out(`manufacturing-${button.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        await dlg.getByRole('button', { name: 'Back' }).click().catch(() => page.keyboard.press('Escape'))
        await dlg.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => problems.push(`${look} ${w}: ${button}'s dialog did not close`))
      }
      // Go-live and Import data, rebuilt (part 24): each opens from Setup and loads. Nothing is planned, uploaded or imported.
      for (const [link, url, ready] of [
        ['Go-live', /\/office\/go-live$/, () => page.getByRole('table', { name: 'Checklist' }).getByRole('row').first()],
        ['Import data (CSV / Excel)', /\/office\/import/, () => page.getByRole('region', { name: '3. Upload, check, import' })]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Setup/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Setup' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { problems.push(`${look} ${w}: Setup has no ${link} link`); await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`system-${link.split(' ')[0].toLowerCase()}-${look}-${w}`) })
      }
      // System, second half, rebuilt (part 23): API clients, Mobile money, Support access and Sync Center open from Setup and load
      // (each only where this person may see it). Nothing is made, given or synced.
      for (const [link, url, ready] of [
        ['API clients', /\/office\/api-clients$/, () => page.getByRole('region', { name: 'Clients' })],
        ['Mobile money', /\/office\/mobile-money/, () => page.getByText('No mobile money requests match.').or(page.getByRole('table', { name: 'Mobile money requests' }).getByRole('row').nth(1))],
        ['Support access', /\/office\/support-access$/, () => page.getByRole('region', { name: 'Access given' })],
        ['Sync Center', /\/office\/sync-center$/, () => page.getByRole('region', { name: 'Servers' })]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Setup/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Setup' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`system-${link.split(' ')[0].toLowerCase()}-${look}-${w}`) })
      }
      // System, first half, rebuilt (part 22): each opens from Setup and loads (Integrations only where its add-on is on). Nothing
      // is run or changed: no backup, no key, no check-in.
      for (const [link, url, ready] of [
        ['Health', /\/office\/system-health$/, () => page.getByRole('table', { name: 'Health checks' }).getByRole('row').nth(1)],
        ['Backups', /\/office\/backups$/, () => page.getByRole('region', { name: 'Recent backups' })],
        ['Licence', /\/office\/licence$/, () => page.getByRole('region', { name: 'Licence', exact: true })],
        ['Audit log', /\/office\/audit-log/, () => page.getByRole('table', { name: 'Audit log' }).getByRole('row').nth(1)],
        ['Problem reports', /\/office\/problem-reports$/, () => page.getByText('No problem reports here.').or(page.getByRole('table', { name: 'Problem reports' }).getByRole('row').nth(1))],
        ['Integrations', /\/office\/integrations$/, () => page.getByText(/working/).first()]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Setup/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Setup' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) {
          if (link !== 'Integrations') problems.push(`${look} ${w}: Setup has no ${link} link`)
          await page.keyboard.press('Escape')
          continue
        }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`system-${link.split(' ')[0].toLowerCase()}-${look}-${w}`) })
        if (link === 'Audit log') { // a row opens the entry in full
          await page.getByRole('table', { name: 'Audit log' }).getByRole('row').nth(1).click()
          await page.getByRole('dialog').getByText('Changes (before → after)').waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: an audit entry did not open`))
          await page.screenshot({ path: out(`system-audit-entry-${look}-${w}`) })
          await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click()
        }
      }
      // HR & payroll, rebuilt (part 21): each opens from People and loads; Add employee opens the form and Prepare payroll its
      // dialog (nothing is saved). My leave & payslips only shows for someone who is an employee.
      for (const [link, url, ready] of [
        ['Employees', /\/office\/employees$/, () => page.getByText('No employees match.').or(page.getByRole('table', { name: 'Employees' }).getByRole('row').nth(1))],
        ['Timesheets', /\/office\/timesheets/, () => page.getByRole('region', { name: 'Hours this month' })],
        ['Leave', /\/office\/leave$/, () => page.getByText('No leave requests match.').or(page.getByRole('table', { name: 'Leave' }).getByRole('row').nth(1))],
        ['Payroll', /\/office\/payroll$/, () => page.getByText('No payroll yet.').or(page.getByRole('table', { name: 'Payroll' }).getByRole('row').nth(1))],
        ['Loans & advances', /\/office\/loans$/, () => page.getByText('No loans or advances.').or(page.getByRole('table', { name: 'Loans and advances' }).getByRole('row').nth(1))],
        ['My leave & payslips', /\/office\/my-hr$/, () => page.getByRole('region', { name: 'My payslips' })]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /People/ }).first().click()
        const l = page.getByRole('navigation', { name: 'People' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) {
          if (link !== 'My leave & payslips') problems.push(`${look} ${w}: People has no ${link} link`)
          await page.keyboard.press('Escape')
          continue
        }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`hr-${link.split(' ')[0].toLowerCase()}-${look}-${w}`) })
        if (link === 'Employees' && await page.getByRole('button', { name: 'Add employee' }).count()) {
          await page.getByRole('button', { name: 'Add employee' }).click()
          await page.getByRole('region', { name: 'Pay (per month)' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Add employee did not open the form`))
          await page.screenshot({ path: out(`hr-employee-form-${look}-${w}`) })
          await cancelForm()
        }
        if (link === 'Payroll' && await page.getByRole('button', { name: 'Prepare payroll' }).count()) {
          await page.getByRole('button', { name: 'Prepare payroll' }).click()
          const dlg = page.getByRole('dialog', { name: 'Prepare payroll' })
          await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Prepare payroll did not open`))
          await dlg.getByRole('button', { name: 'Back', exact: true }).click().catch(() => page.keyboard.press('Escape'))
        }
      }
      // Branches, Industries, Legal entities and Branding, rebuilt (part 20): each opens from Setup → Company and loads; New branch
      // opens the form, and a branch opens with its stores and tills (nothing is saved). Scale items opens from Industries →
      // Grocery where the grocery module is on.
      for (const [link, url, ready] of [
        ['Branches', /\/office\/branches$/, () => page.getByRole('table', { name: 'Branches' }).getByRole('row').nth(1)],
        ['Industries', /\/office\/industries$/, () => page.getByText('No industries.').or(page.getByRole('table', { name: 'Industries' }).getByRole('row').nth(1))],
        ['Legal entities', /\/office\/legal-entities$/, () => page.getByText(/No legal entities match/).or(page.getByRole('table', { name: 'Legal entities' }).getByRole('row').nth(1))],
        ['Branding', /\/office\/branding$/, () => page.getByRole('region', { name: 'Logo and icon' })]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Setup/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Setup' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { problems.push(`${look} ${w}: Setup has no ${link} link`); await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`company-${link.split(' ')[0].toLowerCase()}-${look}-${w}`) })
        if (link === 'Branches') {
          await page.getByRole('table', { name: 'Branches' }).getByRole('row').nth(1).click()
          await page.getByRole('region', { name: 'Tills', exact: true }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a branch did not open with its tills`))
          await page.screenshot({ path: out(`company-branch-${look}-${w}`) })
          await page.goBack()
          const add = page.getByRole('button', { name: 'New branch' })
          await add.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Branches has no New branch`))
          if (await add.count()) {
            await add.click()
            await page.getByLabel('Tills to create').waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: New branch did not open the form`))
            await page.screenshot({ path: out(`company-branch-new-${look}-${w}`) })
            await cancelForm()
          }
        }
      }
      if (await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Industries/ }).count()) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Industries/ }).first().click()
        const scale = page.getByRole('navigation', { name: 'Industries' }).getByRole('link', { name: /^Scale items/i }).first()
        if (await scale.count()) {
          await scale.click()
          await page.waitForURL(/\/office\/scale-items$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Scale items did not open the new screen (${page.url()})`))
          await page.getByText('No product has a scale code yet.').or(page.getByRole('table', { name: 'Scale items' }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 })
            .catch(() => problems.push(`${look} ${w}: Scale items did not load`))
          await page.screenshot({ path: out(`grocery-scale-${look}-${w}`) })
        } else await page.keyboard.press('Escape')
      }
      // Services, rebuilt (part 26), where the services module is on: each opens from More (or its own Services tab) and loads; Book
      // appointment and Sign up a customer open their dialogs (nothing is saved). Pages not in the menu (module off) are skipped.
      for (const [link, url, ready, button] of [
        ['Staff calendar', /\/office\/services\/calendar/, () => page.getByRole('region', { name: 'Day' }), 'Book appointment'],
        ['Appointments', /\/office\/services\/appointments/, () => page.getByText('No appointments match.').or(page.getByRole('table', { name: 'Appointments' }).getByRole('row').nth(1)), null],
        ['Prepaid passes', /\/office\/services\/passes/, () => page.getByText('No passes match.').or(page.getByRole('table', { name: 'Prepaid passes' }).getByRole('row').nth(1)), null],
        ['Memberships', /\/office\/services\/memberships/, () => page.getByText('No memberships match.').or(page.getByRole('table', { name: 'Memberships' }).getByRole('row').nth(1)), 'Sign up a customer'],
        ['Membership plans', /\/office\/services\/plans/, () => page.getByText('No plans yet.').or(page.getByRole('table', { name: 'Membership plans' }).getByRole('row').nth(1)), 'New plan'],
        ['Commissions', /\/office\/services\/commissions/, () => page.getByRole('region', { name: 'By staff member' }), null],
        ['Licence keys', /\/office\/services\/keys/, () => page.getByText('No keys match.').or(page.getByRole('table', { name: 'Licence keys' }).getByRole('row').nth(1)), 'Load keys']]) {
        // Services is not one of the named tabs, so it sits under More (a More holding only Services takes its name).
        const menu = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /^(More|Services)\b/ }).first()
        if (!await menu.waitFor({ timeout: 10000 }).then(() => true, () => false)) break
        const tab = /^Services/.test((await menu.innerText()).trim()) ? 'Services' : 'More'
        await menu.click()
        const l = page.getByRole('navigation', { name: tab }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`services-${link.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
        if (button) {
          const b = page.getByRole('button', { name: button })
          if (await b.count()) {
            await b.first().click()
            const dlg = page.getByRole('dialog')
            await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${button} did not open its dialog`))
            await page.screenshot({ path: out(`services-${button.toLowerCase().replace(/ /g, '-')}-${look}-${w}`) })
            await dlg.getByRole('button', { name: 'Back' }).click().catch(() => page.keyboard.press('Escape'))
            // While a dialog closes, the page behind it is hidden from the accessibility tree: wait until it is gone.
            await dlg.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => problems.push(`${look} ${w}: ${button}'s dialog did not close`))
          } else problems.push(`${look} ${w}: ${link} has no ${button}`)
        }
      }
      // Landed costs, Cost runs, Estimated costs and Spend analytics, rebuilt (part 19): each opens from its menu with its list,
      // empty line or figures; Cost runs' New button opens the form and Landed costs' Spread opens its dialog (nothing is saved).
      for (const [group, link, url, ready] of [
        ['Purchasing', 'Landed costs', /\/office\/landed-costs$/, () => page.getByText(/No landed costs match/).or(page.getByRole('table', { name: 'Landed costs' }).getByRole('row').nth(1))],
        ['Stock', 'Cost runs', /\/office\/cost-runs$/, () => page.getByText(/No cost runs match/).or(page.getByRole('table', { name: 'Cost runs' }).getByRole('row').nth(1))],
        ['Stock', 'Estimated costs', /\/office\/estimated-costs$/, () => page.getByText(/No estimated costs/).or(page.getByRole('table', { name: 'Estimated costs' }).getByRole('row').nth(1))],
        ['Purchasing', 'Spend analytics', /\/office\/spend-analytics$/, () => page.getByRole('region', { name: 'Spend by month' })]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: new RegExp(group) }).first().click()
        const l = page.getByRole('navigation', { name: group }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { problems.push(`${look} ${w}: ${group} has no ${link} link`); await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().first().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`${link.split(' ')[0].toLowerCase()}-cost-${look}-${w}`) })
        if (link === 'Cost runs') {
          const btn = page.getByRole('button', { name: 'New cost run' })
          await btn.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Cost runs has no New cost run`))
          if (await btn.count()) {
            await btn.click()
            await page.getByRole('region', { name: 'How to work out the cost' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: New cost run did not open the form`))
            await page.screenshot({ path: out(`cost-run-form-${look}-${w}`) })
            await cancelForm()
          }
        }
        if (link === 'Landed costs') {
          const btn = page.getByRole('button', { name: 'Spread freight and duty' })
          await btn.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Landed costs has no Spread freight and duty`))
          if (await btn.count()) {
            await btn.click()
            const dlg = page.getByRole('dialog', { name: 'Spread freight and duty' })
            await dlg.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Spread freight and duty did not open`))
            await page.screenshot({ path: out(`landed-spread-${look}-${w}`) })
            await dlg.getByRole('button', { name: 'Back', exact: true }).click().catch(() => page.keyboard.press('Escape'))
          }
        }
      }
      // Purchase requests, Requests for quotation and Contracts and blanket orders, rebuilt (part 18): each opens from Purchasing
      // with its list (or its empty line), and its New button opens the form (nothing is saved).
      for (const [link, url, table, empty, create, region] of [
        ['Purchase requests', /\/office\/purchase-requests$/, 'Purchase requests', /No purchase requests match/, 'New purchase request', 'Request'],
        ['Requests for quotation', /\/office\/rfqs$/, 'Requests for quotation', /No requests for quotation match/, 'New request for quotation', 'Request'],
        ['Contracts and blanket orders', /\/office\/agreements$/, 'Contracts and blanket orders', /No contracts or blanket orders match/, 'New agreement', 'Agreement']]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Purchasing/ }).first().click()
        const l = page.getByRole('navigation', { name: 'Purchasing' }).getByRole('link', { name: menuName(link) }).first()
        if (!await l.count()) { problems.push(`${look} ${w}: Purchasing has no ${link} link`); await page.keyboard.press('Escape'); continue }
        await l.click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await page.getByText(empty).or(page.getByRole('table', { name: table }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 })
          .catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`${link.split(' ')[0].toLowerCase()}-proc-${look}-${w}`) })
        const btn = page.getByRole('button', { name: create })
        await btn.waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} has no ${create}`))
        if (await btn.count()) {
          await btn.click()
          await page.getByRole('region', { name: region }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${create} did not open the form`))
          await page.screenshot({ path: out(`${link.split(' ')[0].toLowerCase()}-proc-form-${look}-${w}`) })
          await cancelForm()
        }
      }
      // Fixed assets, rebuilt (release 8): Kinds of asset opens from the register with its kinds.
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Finance/ }).first().click()
      await page.getByRole('navigation', { name: 'Finance' }).getByRole('link', { name: menuName('Fixed assets') }).first().click()
      const kinds = page.getByRole('button', { name: 'Kinds of asset' })
      await kinds.waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Fixed assets did not offer Kinds of asset`))
      if (await kinds.count()) {
        await kinds.click()
        await page.getByRole('region', { name: 'Kinds of asset' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Kinds of asset did not load`))
        await page.screenshot({ path: out(`asset-kinds-${look}-${w}`) })
      }
      // Bank & cash, rebuilt (release 8): an account's Reconcile opens with the summary and both lists (nothing is matched).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Finance/ }).first().click()
      await page.getByRole('navigation', { name: 'Finance' }).getByRole('link', { name: menuName('Bank & cash') }).first().click()
      await page.getByRole('region', { name: 'Bank and cash accounts' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Bank & cash did not load`))
      const reconcile = page.getByRole('button', { name: 'Reconcile', exact: true }).first()
      if (await reconcile.count()) {
        await reconcile.click()
        await page.getByRole('region', { name: 'Statement lines to match' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Reconcile did not load`))
        await page.screenshot({ path: out(`reconcile-${look}-${w}`) })
      }
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Finance/ }).first().click()
      await page.getByRole('navigation', { name: 'Finance' }).getByRole('link', { name: menuName('Journals') }).first().click()
      await page.waitForURL(/\/office\/journals$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Journals did not open again (${page.url()})`))
      await page.getByText('No journals match').or(page.getByRole('table', { name: 'Journals' }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 }).catch(() => undefined)
      // Journals, rebuilt (release 8): a journal opens from the list with its lines (nothing is posted).
      const firstJournal = page.getByRole('table', { name: 'Journals' }).getByRole('row').nth(1)
      if (page.url().endsWith('/office/journals') && await firstJournal.count()) {
        await firstJournal.click()
        await page.getByRole('region', { name: 'Journal lines' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: a journal did not open with its lines`))
      }
      // Insights, rebuilt (release 7): opens from the Insights menu with the forecast; Stock health shows its four tables.
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Insights/ }).first().click()
      await page.getByRole('navigation', { name: 'Insights' }).getByRole('link', { name: menuName('Insights') }).first().click()
      await page.waitForURL(/\/office\/insights$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Insights did not open the new screen (${page.url()})`))
      await page.getByRole('region', { name: 'Demand forecast and reorder' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Insights did not load`))
      await page.getByRole('tab', { name: 'Stock health' }).click()
      await page.getByRole('region', { name: 'Transfers between branches' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Insights stock health did not load`))
      await page.screenshot({ path: out(`insights-${look}-${w}`) })
      // Returns, rebuilt (release 7): opens from the Sales menu and loads.
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
      await page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: menuName('Returns') }).first().click()
      await page.waitForURL(/\/office\/returns$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Returns did not open the new screen (${page.url()})`))
      await page.getByText('No returns match').or(page.getByRole('table', { name: 'Returns' }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: Returns did not load`))
      await page.getByText(/in the last 30 days/).first().waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Returns did not show the 30-day summary`))
      await page.screenshot({ path: out(`returns-${look}-${w}`) })
      // Shrink and Slow & dead stock, rebuilt (release 7): both open from the Stock menu and load.
      for (const [link, path, ready] of [['Shrink', /\/office\/shrink$/, () => page.getByRole('region', { name: 'By reason (at cost)' })],
        ['Slow & dead stock', /\/office\/slow-stock$/, () => page.getByRole('region', { name: 'Slow and dead stock' })],
        ['Batches & expiry', /\/office\/batches$/, () => page.getByRole('region', { name: 'Expired — take off the shelf' })]]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first().click()
        await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName(link) }).first().click()
        await page.waitForURL(path, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        await ready().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${link} did not load`))
        await page.screenshot({ path: out(`${link.replaceAll(' ', '-').replace('&', 'and').toLowerCase()}-${look}-${w}`) })
      }
      // Report builder and Saved reports, rebuilt (release 7): both open from the Insights menu; the builder runs its first
      // report, and a line of it opens the rows behind it (nothing is saved).
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Insights/ }).first().click()
      await page.getByRole('navigation', { name: 'Insights' }).getByRole('link', { name: menuName('Report builder') }).first().click()
      await page.waitForURL(/\/office\/report-builder$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Report builder did not open the new screen (${page.url()})`))
      await page.getByRole('region', { name: 'Report choices' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Report builder did not load`))
      await page.getByRole('main').getByText(/ · /).first().waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: Report builder did not run its report`))
      const line = page.locator('tr[tabindex="0"]').first()
      if (await line.count()) {
        await line.click()
        await page.getByRole('button', { name: 'Back to the summary' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a report line did not open the rows behind it`))
      }
      await page.screenshot({ path: out(`report-builder-${look}-${w}`) })
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Insights/ }).first().click()
      await page.getByRole('navigation', { name: 'Insights' }).getByRole('link', { name: menuName('Saved reports') }).first().click()
      await page.waitForURL(/\/office\/saved-reports$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Saved reports did not open the new screen (${page.url()})`))
      await page.getByText('No saved reports yet').or(page.getByRole('table', { name: 'Saved reports' }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: Saved reports did not load`))
      if (look === 'top' && w === 1366) {
        const payBtn = page.getByRole('button', { name: 'Pay a supplier' })
        if (await payBtn.waitFor({ timeout: 10000 }).then(() => true).catch(() => false)) {
          await payBtn.click()
          const d = page.getByRole('dialog')
          await d.waitFor({ timeout: 10000 }).catch(() => problems.push('pay a supplier: the dialog did not open'))
          await d.getByRole('combobox', { name: /^Supplier/ }).click()
          await page.getByRole('listbox').getByRole('option').first().click().catch(() => problems.push('pay a supplier: no supplier to choose'))
          await d.getByRole('spinbutton', { name: /^Amount/ }).fill('10')
          await page.waitForTimeout(800)
          await page.screenshot({ path: out('pay-a-supplier') })
          await d.getByRole('button', { name: 'Back' }).click() // nothing is paid
          await d.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => undefined)
        }
      }

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
          await confirm.getByLabel(/6-digit code from your authenticator app/).waitFor({ timeout: 15000 })
            .catch(() => problems.push('To-do: the confirm dialog did not ask for the code'))
          await page.screenshot({ path: out('todo-confirm') })
          await confirm.getByRole('button', { name: 'Cancel' }).click()
        }
      }
      await page.goto(`${base}/office/`)
      await page.getByRole('heading', { name: 'Business Portal', level: 1 }).waitFor({ timeout: 20000 })
      const sideways = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)
      if (sideways > 1) problems.push(`${look} ${w}: the page runs ${sideways}px off the side`)

      // Products, rebuilt: from the menu, rows with prices and stock; a row opens the product with its stock and movements.
      const area = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first()
      await area.click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName('Products') }).first().click()
      await page.waitForURL(/\/office\/products$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Products did not open the new screen (${page.url()})`))
      const productRow = page.getByRole('table', { name: 'Products' }).getByRole('row').nth(1)
      await Promise.race([productRow.waitFor({ timeout: 20000 }), page.getByText('No products match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Products showed neither rows nor "No products match"`))
      await page.getByRole('button', { name: 'New product' }).waitFor({ timeout: 10000 })
        .catch(() => problems.push(`${look} ${w}: Products has no "New product" (the dev owner may add products): ${optionsAnswer}`))
      await page.screenshot({ path: out(`products-${look}-${w}`) })
      if (await productRow.isVisible().catch(() => false)) {
        if (look === 'top' && w === 1366) { // tick a product: the actions show; Print labels opens its form (nothing is made)
          await productRow.getByRole('checkbox', { name: 'Tick this row' }).check()
          await page.getByRole('region', { name: 'Ticked products' }).getByText('1 ticked').waitFor({ timeout: 5000 }).catch(() => problems.push('products: ticking a row shows no actions'))
          await page.getByRole('region', { name: 'Ticked products' }).getByRole('button', { name: 'Print labels' }).click()
          const dlg = page.getByRole('dialog', { name: 'Print labels' })
          await dlg.getByText('For 1 product.').waitFor({ timeout: 5000 }).catch(() => problems.push('products: Print labels did not open its form'))
          await page.waitForTimeout(400)
          await page.screenshot({ path: out('products-labels') })
          await dlg.getByRole('button', { name: 'Cancel' }).click()
          await page.getByRole('region', { name: 'Ticked products' }).getByRole('button', { name: 'Clear' }).click()
        }
        await productRow.click()
        await page.waitForURL(/\/office\/products\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a product did not open`))
        await page.getByRole('heading', { name: 'Barcodes and units', level: 2 }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: the product shows no units`))
        await page.screenshot({ path: out(`product-${look}-${w}`), fullPage: true })
        // Edit opens the new form; typing keeps its place (a field rebuilt on each key would lose it); Cancel goes back.
        await page.getByRole('button', { name: 'Edit', exact: true }).click()
        await page.waitForURL(/\/office\/products\/[0-9a-f-]{36}\/edit$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Edit did not open the new form (${page.url()})`))
        await page.getByRole('button', { name: 'Save changes' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: the product form did not open`))
        const shelf = page.getByLabel('Location / shelf')
        await shelf.click()
        await page.keyboard.type('B2 TEST')
        if (!(await shelf.inputValue().catch(() => '')).endsWith('B2 TEST')) problems.push(`${look} ${w}: typing in the product form loses its place`)
        await page.screenshot({ path: out(`product-form-${look}-${w}`), fullPage: true })
        if (look === 'top' && w === 1366) {
          await page.getByRole('region', { name: 'Barcodes and units' }).scrollIntoViewIfNeeded().catch(() => problems.push('product form: no Barcodes and units'))
          await page.screenshot({ path: out('product-form-units') })
          await page.getByRole('button', { name: 'Add a price' }).scrollIntoViewIfNeeded().catch(() => problems.push('product form: no Prices'))
          await page.mouse.wheel(0, 200)
          await page.screenshot({ path: out('product-form-prices') })
        }
        if (!await page.getByText(/^Unsaved changes/).count()) problems.push(`${look} ${w}: the save bar does not say there are unsaved changes`)
        await page.getByRole('button', { name: 'Cancel', exact: true }).click() // nothing is saved
        // Typed in, so leaving asks first (part 44): leave without saving.
        const leave = page.getByRole('dialog', { name: 'You have unsaved changes' })
        await leave.waitFor({ timeout: 10000 }).catch(() => problems.push(`${look} ${w}: leaving the product form with changes did not ask first`))
        await leave.getByRole('button', { name: 'Leave without saving' }).click().catch(() => undefined)
        await page.waitForURL(/\/office\/products\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Cancel did not go back to the product`))
      }

      // Stock on hand and the stock ledger, rebuilt: rows (or "Nothing matches"); the adjustment form opens and is cancelled.
      for (const [link, url, heading, empty] of [['Stock on hand', /\/office\/stock$/, 'Stock on hand', 'Nothing matches'], ['Stock ledger', /\/office\/stock\/movements$/, 'Stock ledger', 'No movements match']]) {
        await area.click()
        await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName(link) }).first().click()
        await page.waitForURL(url, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: ${link} did not open the new screen (${page.url()})`))
        const first = page.getByRole('table', { name: heading }).getByRole('row').nth(1)
        await Promise.race([first.waitFor({ timeout: 20000 }), page.getByText(empty).waitFor({ timeout: 20000 })]).catch(() => problems.push(`${look} ${w}: ${link} showed neither rows nor "${empty}"`))
        await page.screenshot({ path: out(`${link.replace(/ /g, '-').toLowerCase()}-${look}-${w}`) })
        if (link === 'Stock ledger' && look === 'top' && w === 1366) { // Export → CSV: a file comes down, with the list's columns
          await page.getByRole('button', { name: 'Export' }).click()
          const [file] = await Promise.all([page.waitForEvent('download', { timeout: 60000 }), page.getByRole('menuitem', { name: 'Export to CSV' }).click()])
            .catch(() => [null])
          if (!file) problems.push('export: Export to CSV gave no file')
          else {
            const text = await file.path().then((p) => readFileSync(p, 'utf8')).catch(() => '')
            if (!/\.csv$/.test(file.suggestedFilename()) || !text.includes('Base qty')) problems.push(`export: the CSV is not the ledger (${file.suggestedFilename()})`)
          }
        }
        if (link === 'Stock on hand' && look === 'top' && w === 1366) {
          await page.getByRole('button', { name: 'Opening stock / adjustment' }).click().catch(() => problems.push('stock: no "Opening stock / adjustment" for the dev owner'))
          const dlg = page.getByRole('dialog', { name: 'Opening stock / adjustment' })
          await dlg.getByRole('radio', { name: 'Adjustment' }).check().catch(() => problems.push('stock: the adjustment form did not open'))
          await dlg.getByText('Reason').first().waitFor({ timeout: 5000 }).catch(() => problems.push('stock: an adjustment asks no reason'))
          await page.waitForTimeout(400)
          await page.screenshot({ path: out('stock-adjust') })
          await dlg.getByRole('button', { name: 'Cancel' }).click()
        }
      }

      // Stock adjustments, rebuilt: the list, a document, and the draft form: a product picked shows what the warehouse holds.
      await area.click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName('Stock adjustments') }).first().click()
      await page.waitForURL(/\/office\/adjustments$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Stock adjustments did not open the new screen (${page.url()})`))
      const adjRow = page.getByRole('table', { name: 'Stock adjustments' }).getByRole('row').nth(1)
      await Promise.race([adjRow.waitFor({ timeout: 20000 }), page.getByText('No adjustments match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Stock adjustments showed neither rows nor "No adjustments match"`))
      await page.screenshot({ path: out(`adjustments-${look}-${w}`) })
      if (await adjRow.isVisible().catch(() => false)) {
        await adjRow.click()
        await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: an adjustment shows no lines`))
        await page.screenshot({ path: out(`adjustment-${look}-${w}`), fullPage: true })
        await page.goBack()
      }
      if (look === 'top' && w === 1366) {
        await page.getByRole('button', { name: 'New stock adjustment' }).click()
        await page.waitForURL(/\/office\/adjustments\/new$/, { timeout: 15000 }).catch(() => problems.push('adjustment form: did not open'))
        const lineRegion = page.getByRole('region', { name: 'Lines' })
        await lineRegion.waitFor({ timeout: 15000 }).catch(() => problems.push('adjustment form: no lines'))
        const wh = page.getByRole('combobox', { name: /Warehouse/ })
        if (!(await wh.inputValue().catch(() => ''))) { await wh.click(); await page.getByRole('listbox').getByRole('option').first().click() }
        const picker = lineRegion.getByRole('combobox', { name: /Product and unit/ })
        await picker.click()
        await picker.fill('am')
        await page.getByRole('listbox').getByRole('option').first().click({ timeout: 15000 }).catch(() => problems.push('adjustment form: the product search offered nothing'))
        await lineRegion.getByRole('spinbutton', { name: /Quantity/ }).fill('1')
        await lineRegion.getByText(/^-?\d+(\.\d+)? \S+$/).first().waitFor({ timeout: 10000 }).catch(() => problems.push('adjustment form: no stock shown for the product'))
        await page.waitForTimeout(400)
        await page.screenshot({ path: out('adjustment-form') })
        await cancelForm() // nothing is saved

        // One adjustment's page, drawn from an answer made up in the browser (the dev data may hold none; nothing is saved).
        const fake = '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f'
        await page.route(`**/office-api/adjustments/${fake}`, (r) => r.fulfill({ json: {
          currency: 'GHS', exponent: 2, id: fake, number: 'ADJ-HQ-000042', status: 'submitted', status_label: 'Waiting for approval', kind: 'adjustment',
          facts: { Warehouse: 'HQ-W1 · Main store', Reason: 'Damaged', Reference: 'DR-7', 'Prepared by': 'Esi Cashier', 'Submitted by': 'Esi Cashier' },
          note: 'Carton fell from the shelf.', disposal: null,
          lines: { headings: ['#', 'SKU', 'Product', 'Change', 'Quantity', 'On hand before', 'Change (base units)', 'On hand after', 'Batch', 'Reason', 'Note'],
            rows: [['1', 'MILO-400', 'MILO 400G', 'Decrease (−)', '3.000 PC', '40.000', '-3.000', '37.000', '', 'Damaged', 'Dropped']], numeric: [0, 5, 6, 7] },
          value: -9000, value_note: 'If posted now (against stock on hand now), value at average cost', over_limit: null, photos: [],
          actions: { edit: false, submit: false, post: 'Approve and post', reopen: true, cancel: true, import: null, disposal_record: null },
        } }))
        await page.evaluate((id) => { history.pushState(null, '', `/office/adjustments/${id}`); window.dispatchEvent(new Event('zx-navigate')) }, fake)
        await page.getByRole('button', { name: 'Approve and post' }).waitFor({ timeout: 15000 }).catch(() => problems.push('adjustment: the page did not draw'))
        await page.screenshot({ path: out('adjustment'), fullPage: true })
        await page.unroute(`**/office-api/adjustments/${fake}`)
      }

      // Stock counts, rebuilt: the list and the Start dialog; one count drawn from a made-up answer, with Enter counts (nothing saved).
      await area.click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName('Stock counts') }).first().click()
      await page.waitForURL(/\/office\/counts$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Stock counts did not open the new screen (${page.url()})`))
      const countRow = page.getByRole('table', { name: 'Stock counts' }).getByRole('row').nth(1)
      await Promise.race([countRow.waitFor({ timeout: 20000 }), page.getByText('No counts match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Stock counts showed neither rows nor "No counts match"`))
      await page.screenshot({ path: out(`counts-${look}-${w}`) })
      if (await countRow.isVisible().catch(() => false)) { // a real count: its lines
        await countRow.click()
        await page.getByRole('region', { name: /^Lines/ }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a count shows no lines`))
        await page.screenshot({ path: out(`count-${look}-${w}`), fullPage: true })
        await page.goBack()
      }
      if (look === 'top' && w === 1366) {
        await page.getByRole('button', { name: 'Start a count' }).click().catch(() => problems.push('counts: no "Start a count" for the dev owner'))
        await page.getByRole('dialog', { name: 'Start a count' }).getByRole('button', { name: 'Cancel' }).click().catch(() => problems.push('counts: the Start dialog did not open'))
        const fakeCount = '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e1f'
        await page.route(`**/office-api/counts/${fakeCount}`, (r) => r.fulfill({ json: {
          currency: 'GHS', exponent: 2, id: fakeCount, number: 'CNT-HQ-000007', status: 'counting', status_label: 'Counting', blind: true,
          facts: { Warehouse: 'HQ-W1 · Main store', Counting: 'Whole warehouse', Type: 'Blind', 'Started by': 'Ama Owner · 17 Nov 2026 09:00' },
          note: 'Month end', shows_expected: false, counted: 1, bins: [],
          lines: [{ sku: 'MILO-400', product: 'MILO 400G', unit: 'PC', bin: null, counted: '38', by: 'Yaw Store', expected: null, difference: null }],
          actions: { count: true, submit: true, post: false, cancel: true, import: null },
        } }))
        await page.evaluate((id) => { history.pushState(null, '', `/office/counts/${id}`); window.dispatchEvent(new Event('zx-navigate')) }, fakeCount)
        await page.getByRole('button', { name: 'Enter counts' }).click({ timeout: 15000 }).catch(() => problems.push('count: the page did not draw'))
        await page.getByRole('dialog', { name: 'Enter counts' }).waitFor({ timeout: 5000 }).catch(() => problems.push('count: Enter counts did not open'))
        await page.waitForTimeout(400)
        await page.screenshot({ path: out('count') })
        await page.getByRole('dialog', { name: 'Enter counts' }).getByRole('button', { name: 'Cancel' }).click()
        await page.unroute(`**/office-api/counts/${fakeCount}`)
      }

      // Transfers, rebuilt: the list, a real transfer, the draft form (a product shows what can be sent), and Receive on a
      // transfer drawn from a made-up answer (nothing saved).
      await area.click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName('Transfers') }).first().click()
      await page.waitForURL(/\/office\/transfers$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Transfers did not open the new screen (${page.url()})`))
      const trRow = page.getByRole('table', { name: 'Transfers' }).getByRole('row').nth(1)
      await Promise.race([trRow.waitFor({ timeout: 20000 }), page.getByText('No transfers match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Transfers showed neither rows nor "No transfers match"`))
      await page.screenshot({ path: out(`transfers-${look}-${w}`) })
      if (await trRow.isVisible().catch(() => false)) {
        await trRow.click()
        await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a transfer shows no lines`))
        await page.screenshot({ path: out(`transfer-${look}-${w}`), fullPage: true })
        await page.goBack()
      }
      if (look === 'top' && w === 1366) {
        await page.getByRole('button', { name: 'New transfer' }).click()
        await page.waitForURL(/\/office\/transfers\/new$/, { timeout: 15000 }).catch(() => problems.push('transfer form: did not open'))
        const fromBox = page.getByRole('combobox', { name: /^From/ })
        if (!(await fromBox.inputValue().catch(() => ''))) { await fromBox.click(); await page.getByRole('listbox').getByRole('option').first().click() }
        const linesRegion = page.getByRole('region', { name: 'Lines' })
        const pick = linesRegion.getByRole('combobox', { name: /Product and unit/ })
        await pick.click()
        await pick.fill('am')
        await page.getByRole('listbox').getByRole('option').first().click({ timeout: 15000 }).catch(() => problems.push('transfer form: the product search offered nothing'))
        await linesRegion.getByRole('spinbutton', { name: /Quantity/ }).fill('1')
        await linesRegion.getByText(/^-?\d+(\.\d+)? \S+$/).first().waitFor({ timeout: 10000 }).catch(() => problems.push('transfer form: no stock to send shown'))
        await page.screenshot({ path: out('transfer-form') })
        await cancelForm() // nothing is saved

        const fakeTr = '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e2f'
        await page.route(`**/office-api/transfers/${fakeTr}`, (r) => r.fulfill({ json: {
          id: fakeTr, number: 'TRF-HQ-000012', status: 'in_transit', status_label: 'In transit',
          facts: { From: 'HQ-W1 · Main store', To: 'A-W1 · Branch A store', 'Created by': 'Ama Owner', 'Dispatched by': 'Kojo · 17 Nov 2026 10:05' },
          note: 'Weekly top-up', difference: null, bins: [],
          lines: [{ id: 'l1', sku: 'MILO-400', name: 'MILO 400G', unit: 'PC', sent: '10', received: null, serials: [] },
            { id: 'l2', sku: 'SUGAR-1KG', name: 'SUGAR 1KG', unit: 'PC', sent: '24', received: null, serials: [] }],
          actions: { edit: false, dispatch: false, receive: true, cancel: false, print: '/print/transfer/x', waybill: '/admin/waybills/create', waybill_label: 'Make a waybill' },
        } }))
        await page.evaluate((x) => { history.pushState(null, '', `/office/transfers/${x}`); window.dispatchEvent(new Event('zx-navigate')) }, fakeTr)
        await page.getByRole('button', { name: 'Receive' }).click({ timeout: 15000 }).catch(() => problems.push('transfer: the page did not draw'))
        const rec = page.getByRole('dialog', { name: 'Receive' })
        await rec.getByLabel('Arrived: MILO 400G').fill('9').catch(() => problems.push('transfer: Receive has no box per line'))
        await rec.getByText('Different from what was sent').waitFor({ timeout: 5000 }).catch(() => problems.push('transfer: a difference is not pointed out'))
        await page.waitForTimeout(300)
        await page.screenshot({ path: out('transfer-receive') })
        await rec.getByRole('button', { name: 'Back' }).click()
        await page.unroute(`**/office-api/transfers/${fakeTr}`)
      }

      // Stock requests, rebuilt: the list, a real request, the "Ask for stock" form (a product shows what both sides hold),
      // and Send on a request between industries drawn from a made-up answer (nothing saved).
      await area.click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: menuName('Stock requests') }).first().click()
      await page.waitForURL(/\/office\/requests$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Stock requests did not open the new screen (${page.url()})`))
      const rqRow = page.getByRole('table', { name: 'Stock requests' }).getByRole('row').nth(1)
      await Promise.race([rqRow.waitFor({ timeout: 20000 }), page.getByText('No stock requests match').waitFor({ timeout: 20000 })])
        .catch(() => problems.push(`${look} ${w}: Stock requests showed neither rows nor "No stock requests match"`))
      await page.screenshot({ path: out(`requests-${look}-${w}`) })
      if (await rqRow.isVisible().catch(() => false)) {
        await rqRow.click()
        await page.getByRole('region', { name: 'Lines' }).waitFor({ timeout: 15000 }).catch(() => problems.push(`${look} ${w}: a stock request shows no lines`))
        await page.screenshot({ path: out(`request-${look}-${w}`), fullPage: true })
        await page.goBack()
      }
      if (look === 'top' && w === 1366) {
        await page.getByRole('button', { name: 'Ask for stock' }).click()
        await page.waitForURL(/\/office\/requests\/new$/, { timeout: 15000 }).catch(() => problems.push('request form: did not open'))
        const forBox = page.getByRole('combobox', { name: /^For/ })
        if (!(await forBox.inputValue().catch(() => ''))) { await forBox.click(); await page.getByRole('listbox').getByRole('option').first().click() }
        const askBox = page.getByRole('combobox', { name: /^Ask/ })
        await askBox.click()
        await page.getByRole('listbox').getByRole('option').nth(1).click().catch(() => problems.push('request form: nobody to ask'))
        const needRegion = page.getByRole('region', { name: 'What you need' })
        const pick = needRegion.getByRole('combobox', { name: /Product and unit/ })
        await pick.click()
        await pick.fill('am')
        await page.getByRole('listbox').getByRole('option').first().click({ timeout: 15000 }).catch(() => problems.push('request form: the product search offered nothing'))
        await needRegion.getByRole('spinbutton', { name: /Quantity/ }).fill('2')
        await needRegion.getByText(/^-?\d+(\.\d+)? \S+$/).nth(1).waitFor({ timeout: 10000 }).catch(() => problems.push('request form: "You have" / "They have" not shown'))
        await page.screenshot({ path: out('request-form') })
        await cancelForm() // nothing is saved

        const fakeRq = '0198f2c4-1a2b-7c3d-8e4f-5a6b7c8d9e3f'
        await page.route(`**/office-api/requests/${fakeRq}`, (r) => r.fulfill({ json: {
          id: fakeRq, number: 'SRQ-000007', status: 'approved', status_label: 'Approved', waiting_for: 'supply', cross: true,
          facts: { For: 'G1-W1 · Grocery store', 'Asked of': 'P1-W1 · Pharmacy store', 'Asked by': 'Ama · 17 Nov 2026 09:10',
            'Between industries': 'Yes: both sides approve, moved at cost', 'Approved (asking side)': 'Kwesi · 17 Nov 2026 09:30', 'Approved (supplying side)': 'Kojo · 17 Nov 2026 10:00' },
          note: 'For the weekend', transfer: null,
          lines: [{ id: 'l1', sku: 'GR-WATER', name: 'Bottled water', unit: 'PC', qty: '6', sent: null }],
          actions: { approve: false, approve_text: '', supply: true, reject: true, cancel: false, print: '/print/stock-request/x' },
        } }))
        await page.route(`**/office-api/requests/${fakeRq}/products**`, (r) => r.fulfill({ json: [{ id: 'p1', label: 'PH-WATER · Bottled water (pharmacy)' }] }))
        await page.evaluate((x) => { history.pushState(null, '', `/office/requests/${x}`); window.dispatchEvent(new Event('zx-navigate')) }, fakeRq)
        await page.getByRole('button', { name: 'Send', exact: true }).click({ timeout: 15000 }).catch(() => problems.push('request: the page did not draw'))
        const sendDlg = page.getByRole('dialog', { name: 'Send' })
        await sendDlg.getByLabel('Send: Bottled water').fill('4').catch(() => problems.push('request: Send has no box per line'))
        const own = sendDlg.getByRole('combobox', { name: /Your product for/ })
        await own.click().catch(() => problems.push('request: between industries, Send does not ask for your own product'))
        await own.fill('wa')
        await page.getByRole('option', { name: /PH-WATER/ }).click({ timeout: 5000 }).catch(() => problems.push('request: your own products are not offered'))
        if (await sendDlg.getByRole('button', { name: 'Send', exact: true }).isDisabled()) problems.push('request: Send stays off with everything filled in')
        await page.waitForTimeout(300)
        await page.screenshot({ path: out('request-send') })
        await sendDlg.getByRole('button', { name: 'Back' }).click()
        await page.unroute(`**/office-api/requests/${fakeRq}/products**`)
        await page.unroute(`**/office-api/requests/${fakeRq}`)
      }

      // Insights → Today, rebuilt (part 37): it opens from Insights and shows the day; Yesterday is one tap away.
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Insights/ }).first().click()
      await page.getByRole('navigation', { name: 'Insights' }).getByRole('link', { name: menuName('Today') }).first().click()
      await page.waitForURL(/\/office\/today$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Today did not open the new screen (${page.url()})`))
      await page.getByRole('region', { name: 'Branches' }).waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: Today did not load`))
      await page.screenshot({ path: out(`today-${look}-${w}`) })
      await page.getByRole('tab', { name: 'Yesterday' }).click()
      await page.waitForURL(/\/office\/today\?yesterday=1$/, { timeout: 10000 }).catch(() => problems.push(`${look} ${w}: Yesterday did not follow in the address`))
      await page.getByRole('region', { name: 'Branches' }).waitFor({ timeout: 30000 }).catch(() => problems.push(`${look} ${w}: Yesterday did not load`))

      // Departments, rebuilt (part 38): it opens from Employees and loads; New department opens its dialog (nothing saved).
      // Tax and SSNIT rules opens from Payroll.
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /People/ }).first().click()
      await page.getByRole('navigation', { name: 'People' }).getByRole('link', { name: menuName('Employees') }).first().click()
      await page.getByRole('button', { name: 'Departments' }).click()
      await page.waitForURL(/\/office\/departments$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Departments did not open the new screen (${page.url()})`))
      await page.getByText('No departments yet.').or(page.getByRole('table', { name: 'Departments' }).getByRole('row').nth(1)).first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: Departments did not load`))
      if (await page.getByRole('button', { name: 'New department' }).count()) {
        await page.getByRole('button', { name: 'New department' }).click()
        const dd = page.getByRole('dialog')
        await dd.waitFor({ timeout: 10000 }).catch(() => problems.push(`${look} ${w}: New department did not open its dialog`))
        await page.screenshot({ path: out(`departments-new-${look}-${w}`) })
        await dd.getByRole('button', { name: 'Back' }).click().catch(() => page.keyboard.press('Escape'))
        await dd.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => problems.push(`${look} ${w}: New department's dialog did not close`))
      }
      await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /People/ }).first().click()
      await page.getByRole('navigation', { name: 'People' }).getByRole('link', { name: menuName('Payroll') }).first().click()
      await page.getByRole('button', { name: 'Tax and SSNIT rules' }).click()
      await page.waitForURL(/\/office\/payroll-rules$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Tax and SSNIT rules did not open the new screen (${page.url()})`))
      await page.getByRole('table', { name: 'PAYE bands' }).or(page.getByText('No payroll profile is set up.')).first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: Tax and SSNIT rules did not load`))
      await page.screenshot({ path: out(`payroll-rules-${look}-${w}`) })
      // The setup wizard (part 38): the dev company is set up, so it says so (the steps are covered by OfficeLastPagesTest).
      await page.goto(`${base}/office/setup-wizard`)
      await page.getByText('Your company is already set up.', { exact: false }).waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: the setup wizard did not say the company is set up`))

      // "Confirm it's you", rebuilt (part 40): the new page asks for the code from the app (the dev owner has one). Nothing
      // is confirmed.
      await page.goto(`${base}/office/confirm-its-you`)
      await page.getByLabel(/6-digit code from your authenticator app/).waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: "Confirm it's you" did not ask for the code`))
      await page.screenshot({ path: out(`confirm-its-you-${look}-${w}`) })

      // The classic back office is switched off (part 43): an old address, inside the new look (/office/a/…) or the old one
      // (/admin/…), opens the same page in the new look.
      for (const old of ['/office/a/confirm-its-you', '/admin/confirm-its-you']) {
        await page.goto(base + old)
        await page.waitForURL(/\/office\/confirm-its-you$/, { timeout: 20000 }).catch(() => problems.push(`${look} ${w}: ${old} did not open the new page (${page.url()})`))
      }
      if (await page.locator('iframe').count()) problems.push(`${look} ${w}: a page still opens inside a frame`)
      await page.screenshot({ path: out(`${look}-${w}`) })

      // My profile, rebuilt (part 39): it opens from your menu and shows its parts; Set up (two-factor) and the passkey
      // name are there. Nothing is changed.
      await page.getByRole('button', { name: /your menu/ }).click()
      await page.getByRole('menuitem', { name: 'My profile and settings' }).click()
      await page.waitForURL(/\/office\/profile$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: My profile did not open the new screen (${page.url()})`))
      await page.getByRole('region', { name: 'Personal information' }).waitFor({ timeout: 20000 }).catch(() => problems.push(`${look} ${w}: My profile did not load`))
      for (const part of ['Look', 'Preferences', 'Notifications', 'Password', 'Two-factor sign-in', 'Passkeys']) {
        if (!await page.getByRole('region', { name: part, exact: true }).count()) problems.push(`${look} ${w}: My profile has no ${part}`)
      }
      await page.screenshot({ path: out(`profile-${look}-${w}`), fullPage: true })

      // Recent lists the page just opened; the star adds it to favourites and takes it off again.
      const nav = page.getByRole('navigation', { name: 'Menu' })
      await (look === 'top' ? nav.getByRole('button', { name: 'Recent pages' }) : nav.getByRole('button', { name: 'Recent' })).click()
      await page.getByRole('dialog', { name: 'Recent pages' }).getByRole('link', { name: /profile/i }).first().waitFor({ timeout: 5000 })
        .catch(() => problems.push(`${look} ${w}: Recent does not list My profile`))
      await page.keyboard.press('Escape')
      const star = look === 'top' ? nav.getByRole('button', { name: 'Favourites' }) : nav.getByRole('button', { name: 'Favourites' })
      await star.click()
      const fav = page.getByRole('dialog', { name: 'Favourite pages' })
      await fav.getByRole('button', { name: /^Add “/ }).click().catch(() => problems.push(`${look} ${w}: Favourites has no "Add this page"`))
      await fav.getByRole('link', { name: /profile/i }).first().waitFor({ timeout: 5000 }).catch(() => problems.push(`${look} ${w}: My profile did not become a favourite`))
      await page.screenshot({ path: out(`favourites-${look}-${w}`) })
      await fav.getByRole('button', { name: /^Take “/ }).last().click().catch(() => undefined) // leave the dev account as it was
      await fav.getByRole('button', { name: /^Add “/ }).waitFor({ timeout: 5000 }).catch(() => problems.push(`${look} ${w}: My profile could not be taken off favourites`))
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
  const where = (e.stack ?? '').split('\n').find((l) => l.includes('office-check.mjs'))?.trim() ?? '?' // which step
  problems.push(`stopped: ${e.message.split('\n')[0]} (${where})`)
  await page.screenshot({ path: out('error') }).catch(() => undefined)
} finally {
  if (restoreScope) await restoreScope().catch(() => undefined)
  await browser.close()
}

if (problems.length) {
  console.error(`New back office check failed:\n- ${problems.join('\n- ')}`)
  process.exit(1)
}
console.log('New back office check passed: 2 looks × 2 sizes (screenshots in .local-secrets/office-*.png)')
