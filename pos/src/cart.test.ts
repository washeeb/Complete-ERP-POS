import { describe, expect, it } from 'vitest'
import { addToCart, findByBarcode, priceCart, qtyAllowed, resolvePrice, search, setQty } from './cart'
import type { PriceList, Product } from './types'

const retail: PriceList = { id: 'R', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }
const tax = [{ code: 'VAT', name: 'VAT', rate_bp: 1500 }, { code: 'NHIL', name: 'NHIL', rate_bp: 250 }, { code: 'GETFUND', name: 'GETFund', rate_bp: 250 }]
const pc = { id: 'pc', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: ['111'] }
const ctn = { id: 'ctn', uom_code: 'CTN', uom_name: 'Carton', decimals: 0, factor: '24.000000', is_base: false, barcodes: ['222'] }
const milo: Product = {
  id: 'milo', sku: 'MILO-400', name: 'Milo 400g tin', track_stock: true, on_hand: '10.000', tax, units: [pc, ctn],
  prices: [
    { price_list_id: 'R', product_uom_id: 'pc', min_qty: '1.000', amount_minor: 5800, starts_at: null, ends_at: null },
    { price_list_id: 'R', product_uom_id: 'pc', min_qty: '6.000', amount_minor: 5600, starts_at: null, ends_at: null },
    { price_list_id: 'R', product_uom_id: 'ctn', min_qty: '1.000', amount_minor: 67200, starts_at: null, ends_at: null },
    { price_list_id: 'R', product_uom_id: 'pc', min_qty: '1.000', amount_minor: 5000, starts_at: '2026-12-01T00:00:00Z', ends_at: '2026-12-03T00:00:00Z' },
  ],
}
const now = new Date('2026-10-06T10:00:00Z')

describe('price resolution (mirrors server)', () => {
  it('picks tiers and promotions', () => {
    expect(resolvePrice(milo, 'pc', 'R', 1000, now)?.amount_minor).toBe(5800)
    expect(resolvePrice(milo, 'pc', 'R', 6000, now)?.amount_minor).toBe(5600)
    expect(resolvePrice(milo, 'pc', 'R', 1000, new Date('2026-12-02T00:00:00Z'))?.amount_minor).toBe(5000)
    expect(resolvePrice(milo, 'pc', 'WHOLESALE', 1000, now)).toBeNull()
  })
})

describe('cart', () => {
  it('merges scans of the same unit, prices by tier, totals with tax', () => {
    let c = addToCart([], milo, pc)
    c = addToCart(c, milo, pc)
    c = addToCart(c, milo, ctn)
    expect(c).toHaveLength(2)
    const p = priceCart(c, retail, now)
    expect(p.lines[0].total).toBe(11600)
    expect(p.total).toBe(11600 + 67200)
    expect(p.net + p.tax).toBe(p.total)
    expect(p.taxBreakdown.reduce((a, b) => a + b.amount_minor, 0)).toBe(p.tax)

    c = setQty(c, c[0].key, 6000)
    expect(priceCart(c, retail, now).lines[0].total).toBe(6 * 5600) // bulk tier kicks in
    expect(setQty(c, c[0].key, 0)).toHaveLength(1)
  })
  it('finds by barcode and searches by words', () => {
    expect(findByBarcode([milo], '222')?.unit.uom_code).toBe('CTN')
    expect(findByBarcode([milo], '999')).toBeNull()
    expect(search([milo], 'tin milo')).toHaveLength(1)
    expect(search([milo], 'sugar')).toHaveLength(0)
  })
  it('respects unit decimals', () => {
    expect(qtyAllowed(pc, 1500)).toBe(false)
    expect(qtyAllowed({ ...pc, decimals: 3 }, 1250)).toBe(true)
  })
})
