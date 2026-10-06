import { expect, test, type Route } from '@playwright/test'

/**
 * The warehouse handheld in a real browser: an enrolled handheld (kind "handheld") signs in by PIN and receives a
 * purchase order by scanning, sending one receipt with a stable client id. The API is mocked (contract as in WmsApiTest).
 */

const bootstrap = {
  user: { id: 'u-yaw', name: 'Yaw Storekeeper' }, device: { id: 'dev-h', name: 'Scanner 1', kind: 'handheld' },
  branch: { id: 'b-hq', code: 'HQ', name: 'Head Office' },
  warehouses: [{ id: 'w-1', code: 'HQ-W1', name: 'Main store', use_bins: true, can: ['stock.receive', 'stock.transfer', 'stock.count'],
    bins: [{ id: 'bin-recv', code: 'RECV', name: 'Receiving dock', kind: 'receiving' }, { id: 'bin-a1', code: 'A-01', name: null, kind: 'storage' }] }],
}
const order = { id: 'po-1', number: 'PO-HQ-000001', supplier: 'Accra Wholesale Distributors', expected_on: null,
  lines: [{ id: 'pol-1', product_uom_id: 'u-ctn', sku: 'TOM-400', name: 'Gino tomato paste 400g', uom_code: 'CTN', ordered: '3.000', received: '0.000', barcodes: ['2000099'] }] }

test('receives a purchase order by scanning on the handheld', async ({ page }) => {
  const receipts: { client_id: string; lines: { qty: string; bin_id: string | null }[] }[] = []
  const json = (route: Route, status: number, body: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  await page.route('**/up', (r) => r.fulfill({ status: 200, body: 'ok' }))
  await page.route('**/api/v1/**', async (route) => {
    const req = route.request()
    const path = new URL(req.url()).pathname.replace('/api/v1/', '')
    switch (`${req.method()} ${path}`) {
      case 'POST devices/enroll': return json(route, 201, { device_id: 'dev-h', token: 'device-token', kind: 'handheld' })
      case 'POST pos/login': return json(route, 200, { token: 'staff-token', expires_at: null, user: { id: 'u-yaw', name: 'Yaw Storekeeper' }, offline_grant: null })
      case 'GET wms/bootstrap': return json(route, 200, bootstrap)
      case 'GET wms/purchase-orders': return json(route, 200, [order])
      case 'POST wms/receipts': {
        receipts.push(req.postDataJSON())
        return json(route, 201, { id: receipts[0].client_id, number: 'GRN-HQ-000001', status: 'posted' })
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

  await page.getByRole('button', { name: 'Receive delivery' }).click()
  await page.getByRole('button', { name: /PO-HQ-000001/ }).click()
  const scan = page.getByLabel('Scan each item (or type quantities below)')
  for (let i = 0; i < 3; i++) {
    await scan.fill('2000099')
    await scan.press('Enter')
  }
  await expect(page.getByLabel('Received now Gino tomato paste 400g')).toHaveValue('3')
  await page.getByLabel('Into bin').selectOption('bin-a1')
  await page.getByRole('button', { name: 'Post receipt' }).click()

  await expect(page.getByText('GRN-HQ-000001 received into stock.')).toBeVisible()
  expect(receipts).toHaveLength(1)
  expect(receipts[0].lines).toEqual([{ purchase_order_line_id: 'pol-1', qty: '3', bin_id: 'bin-a1' }])
  expect(receipts[0].client_id).toMatch(/^[0-9a-f-]{36}$/)
})
