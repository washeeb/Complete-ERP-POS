import { expect, test, type Page, type Route } from '@playwright/test'
import { pbkdf2Sync, randomBytes } from 'node:crypto'

/**
 * The warehouse handheld in a real browser: an enrolled handheld (kind "handheld") signs in by PIN and receives a
 * purchase order by scanning. Every action goes through the handheld's signed queue (offline handhelds): sent at once
 * when the server answers, kept and sent later when it does not. The API is mocked (contract as in OfflineHandheldTest).
 */

const salt = randomBytes(16)
const grant = {
  v: 1, id: 'g-yaw', tenant_id: 't1', device_id: 'dev-h', till_id: null, user_id: 'u-yaw', user_name: 'Yaw Storekeeper', username: 'yaw',
  issued_at: new Date().toISOString(), expires_at: new Date(Date.now() + 72 * 3600_000).toISOString(),
  pin: { alg: 'PBKDF2-SHA256', iterations: 1000, salt: salt.toString('base64'), hash: pbkdf2Sync('568135', salt, 1000, 32, 'sha256').toString('base64') },
  sig: 'server-signature',
}
const bootstrap = {
  user: { id: 'u-yaw', name: 'Yaw Storekeeper' }, device: { id: 'dev-h', name: 'Scanner 1', kind: 'handheld' },
  branch: { id: 'b-hq', code: 'HQ', name: 'Head Office' },
  warehouses: [{ id: 'w-1', code: 'HQ-W1', name: 'Main store', use_bins: true, can: ['stock.receive', 'stock.transfer', 'stock.count'],
    bins: [{ id: 'bin-recv', code: 'RECV', name: 'Receiving dock', kind: 'receiving' }, { id: 'bin-a1', code: 'A-01', name: null, kind: 'storage' }] }],
}
const order = { id: 'po-1', number: 'PO-HQ-000001', supplier: 'Accra Wholesale Distributors', expected_on: null,
  lines: [{ id: 'pol-1', product_uom_id: 'u-ctn', sku: 'TOM-400', name: 'Gino tomato paste 400g', uom_code: 'CTN', ordered: '3.000', received: '0.000', barcodes: ['2000099'] }] }
const catalog = { warehouse_id: 'w-1', generated_at: new Date().toISOString(), bins: bootstrap.warehouses[0].bins,
  units: [{ product_uom_id: 'u-ctn', product_id: 'p-tom', sku: 'TOM-400', name: 'Gino tomato paste 400g', uom_code: 'CTN', factor: '24.000000', decimals: 0, is_base: false, barcodes: ['2000099'] }] }

type Sent = { id: string; type: string; payload: Record<string, unknown>; grant: { username: string } }

async function handheld(page: Page, server: { down: boolean; sent: Sent[] }) {
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => server.down ? r.abort('connectionrefused') : r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    if (server.down) return route.abort('connectionrefused')
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-h', token: 'device-token', kind: 'handheld' })
      case 'POST pos/login': return json(route, 200, { token: 'staff-token', expires_at: null, user: { id: 'u-yaw', name: 'Yaw Storekeeper' }, offline_grant: grant })
      case 'GET wms/bootstrap': return json(route, 200, bootstrap)
      case 'GET wms/catalog': return json(route, 200, catalog)
      case 'GET wms/purchase-orders': return json(route, 200, [order])
      case 'POST pos/sync': {
        const events = (req.postDataJSON() as { events: Sent[] }).events
        // The server applies each action once: a repeat answers "duplicate".
        const results = events.map((e) => {
          const seen = server.sent.some((s) => s.id === e.id)
          if (!seen) server.sent.push(e)
          return { id: e.id, status: seen ? 'duplicate' : 'accepted', number: 'GRN-HQ-000001', detail: 'Received into stock.' }
        })
        return json(route, 200, { results })
      }
    }
    return json(route, 404, { error: 'not_found', detail: path })
  })
  await page.goto('/')
  await page.getByPlaceholder('ABCD-EFGH-JKMN').fill('ABCD-EFGH-JKMN')
  await page.getByRole('button', { name: 'Enrol this device' }).click()
  await page.getByLabel('Username').fill('yaw')
  await page.getByLabel('PIN', { exact: true }).fill('568135')
  await page.getByRole('button', { name: 'OK' }).click()
}

test('receives a purchase order by scanning on the handheld', async ({ page }) => {
  const server = { down: false, sent: [] as Sent[] }
  await handheld(page, server)

  await page.getByRole('button', { name: 'Receive delivery' }).click()
  await page.getByRole('button', { name: /PO-HQ-000001/ }).click()
  const scan = page.getByLabel('Scan each item (or type quantities below)')
  for (let i = 0; i < 2; i++) {
    await scan.fill('2000099')
    await scan.press('Enter')
  }
  // The third with the cursor out of the box (after tapping the page), as a scanner types it: fast, ending with Tab.
  await page.locator('body').click({ position: { x: 5, y: 5 } })
  await page.keyboard.type('2000099', { delay: 5 })
  await page.keyboard.press('Tab')
  await expect(page.getByLabel('Received now Gino tomato paste 400g')).toHaveValue('3')
  await page.getByLabel('Into bin').selectOption('bin-a1')
  await page.getByRole('button', { name: 'Post receipt' }).click()

  await expect(page.getByText('GRN-HQ-000001 received into stock.')).toBeVisible()
  expect(server.sent).toHaveLength(1)
  expect(server.sent[0]).toMatchObject({ type: 'wms_receive', grant: { username: 'yaw' },
    payload: { purchase_order_id: 'po-1', lines: [{ purchase_order_line_id: 'pol-1', qty: '3', bin_id: 'bin-a1' }] } })
  expect(server.sent[0].id).toBe(server.sent[0].payload.client_id) // one id per delivery: a resend never receives twice
})

test('keeps receiving when the server cannot be reached, and sends it when it is back', async ({ page }) => {
  test.setTimeout(60_000)
  const server = { down: false, sent: [] as Sent[] }
  await handheld(page, server)
  await expect(page.getByRole('button', { name: 'Receive delivery' })).toBeVisible()
  await page.getByRole('button', { name: 'Receive delivery' }).click()
  await expect(page.getByRole('button', { name: /PO-HQ-000001/ })).toBeVisible() // the list is now kept on the handheld

  server.down = true // the warehouse Wi-Fi drops
  await page.getByRole('button', { name: 'Menu' }).click()
  await page.getByRole('button', { name: 'Receive delivery' }).click()
  await page.getByRole('button', { name: /PO-HQ-000001/ }).click()
  await page.getByLabel('Received now Gino tomato paste 400g').fill('2')
  await page.getByRole('button', { name: 'Post receipt' }).click()
  await expect(page.getByText(/saved on this handheld/)).toBeVisible()
  await expect(page.getByText('1 action(s) waiting to be sent.')).toBeVisible()
  expect(server.sent).toHaveLength(0)

  server.down = false // back: the handheld sends it by itself
  await expect(page.getByText('1 action(s) waiting to be sent.')).toBeHidden({ timeout: 40_000 })
  expect(server.sent).toHaveLength(1)
  expect(server.sent[0]).toMatchObject({ type: 'wms_receive', payload: { lines: [{ qty: '2' }] } })
})
