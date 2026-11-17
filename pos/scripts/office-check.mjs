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
let optionsAnswer = 'no answer yet' // the products screen's options, for a clearer problem report
page.on('response', async (r) => { if (r.url().includes('/office-api/products/options')) optionsAnswer = `options answered ${r.status()} ${(await r.text().catch(() => '')).slice(0, 200)}` })
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

      // Till shifts and Till drawer, rebuilt: from the menu, rows (or "no … match"); a shift opens with the report's figures.
      for (const [link, url, heading, empty] of [['Till shifts', /\/office\/shifts$/, 'Till shifts', 'No shifts match'], ['Till drawer', /\/office\/drawer$/, 'Till drawer', 'Nothing went in or out']]) {
        await page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Sales/ }).first().click()
        await page.getByRole('navigation', { name: 'Sales' }).getByRole('link', { name: link, exact: true }).first().click()
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

      // Products, rebuilt: from the menu, rows with prices and stock; a row opens the product with its stock and movements.
      const area = page.getByRole('navigation', { name: 'Menu' }).getByRole('button', { name: /Stock/ }).first()
      await area.click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: 'Products', exact: true }).first().click()
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
        await page.getByRole('button', { name: 'Cancel', exact: true }).click() // nothing is saved
        await page.waitForURL(/\/office\/products\/[0-9a-f-]{36}$/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Cancel did not go back to the product`))
      }

      // Stock on hand and the stock ledger, rebuilt: rows (or "Nothing matches"); the adjustment form opens and is cancelled.
      for (const [link, url, heading, empty] of [['Stock on hand', /\/office\/stock$/, 'Stock on hand', 'Nothing matches'], ['Stock ledger', /\/office\/stock\/movements$/, 'Stock ledger', 'No movements match']]) {
        await area.click()
        await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: link, exact: true }).first().click()
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
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: 'Stock adjustments', exact: true }).first().click()
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
        if (!(await wh.inputValue().catch(() => ''))) { await wh.click(); await page.getByRole('option').first().click() }
        const picker = lineRegion.getByRole('combobox', { name: /Product and unit/ })
        await picker.click()
        await picker.fill('am')
        await page.getByRole('option').first().click({ timeout: 15000 }).catch(() => problems.push('adjustment form: the product search offered nothing'))
        await lineRegion.getByRole('spinbutton', { name: /Quantity/ }).fill('1')
        await lineRegion.getByText(/^-?\d+(\.\d+)? \S+$/).first().waitFor({ timeout: 10000 }).catch(() => problems.push('adjustment form: no stock shown for the product'))
        await page.waitForTimeout(400)
        await page.screenshot({ path: out('adjustment-form') })
        await page.getByRole('button', { name: 'Cancel', exact: true }).click() // nothing is saved

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
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: 'Stock counts', exact: true }).first().click()
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

      // Transfers is still the current page: it opens inside, and the address follows.
      await area.click()
      await page.getByRole('navigation', { name: 'Stock' }).getByRole('link', { name: 'Transfers', exact: true }).first().click()
      await page.waitForURL(/\/office\/a\/stock-transfers/, { timeout: 15000 }).catch(() => problems.push(`${look} ${w}: Transfers did not open at /office/a/stock-transfers (${page.url()})`))
      await page.waitForFunction(() => {
        const f = document.querySelector('iframe[title="Page"]')
        return f?.contentWindow?.location.pathname === '/admin/stock-transfers' && !!f.contentDocument?.querySelector('h1')
      }, null, { timeout: 20000 }).catch(() => problems.push(`${look} ${w}: the Transfers page did not open inside`))
      const f = await frameReady()
      await f.locator('.fi-ta-table, .fi-ta-empty-state').first().waitFor({ timeout: 20000 })
        .catch(() => problems.push(`${look} ${w}: the Transfers list did not show inside`))
      const chrome = await f.locator('.fi-sidebar, .fi-topbar').evaluateAll((els) => els.filter((e) => getComputedStyle(e).display !== 'none').length)
      if (chrome) problems.push(`${look} ${w}: the embedded page still shows its own menu`)
      await page.waitForTimeout(600) // the page's own loader fades out
      await page.screenshot({ path: out(`${look}-${w}`) })

      // Recent lists the page just opened; the star adds it to favourites and takes it off again.
      const nav = page.getByRole('navigation', { name: 'Menu' })
      await (look === 'top' ? nav.getByRole('button', { name: 'Recent pages' }) : nav.getByRole('button', { name: 'Recent' })).click()
      await page.getByRole('dialog', { name: 'Recent pages' }).getByRole('link', { name: /Transfers/ }).first().waitFor({ timeout: 5000 })
        .catch(() => problems.push(`${look} ${w}: Recent does not list Transfers`))
      await page.keyboard.press('Escape')
      const star = look === 'top' ? nav.getByRole('button', { name: 'Favourites' }) : nav.getByRole('button', { name: 'Favourites' })
      await star.click()
      const fav = page.getByRole('dialog', { name: 'Favourite pages' })
      await fav.getByRole('button', { name: /^Add “/ }).click().catch(() => problems.push(`${look} ${w}: Favourites has no "Add this page"`))
      await fav.getByRole('link', { name: /Transfers/ }).first().waitFor({ timeout: 5000 }).catch(() => problems.push(`${look} ${w}: Transfers did not become a favourite`))
      await page.screenshot({ path: out(`favourites-${look}-${w}`) })
      await fav.getByRole('button', { name: /^Take “/ }).last().click().catch(() => undefined) // leave the dev account as it was
      await fav.getByRole('button', { name: /^Add “/ }).waitFor({ timeout: 5000 }).catch(() => problems.push(`${look} ${w}: Transfers could not be taken off favourites`))
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
