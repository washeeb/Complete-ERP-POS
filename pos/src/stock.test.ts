import { describe, expect, it } from 'vitest'
import { isLow, leftMilli, queuedLines, takenMilli } from './stock'
import type { Product } from './types'

const tab = { id: 'u-tab', uom_code: 'TAB', uom_name: 'Tablet', decimals: 0, factor: '1', is_base: true, barcodes: [] }
const strip = { id: 'u-strip', uom_code: 'STRIP', uom_name: 'Strip', decimals: 0, factor: '10', is_base: false, barcodes: [] }
const para = { id: 'p1', sku: 'P1', name: 'Paracetamol', track_stock: true, on_hand: '100.000', tax: [], units: [tab, strip], prices: [] } as Product
const service = { ...para, id: 'p2', track_stock: false, on_hand: null } as Product
const products = new Map([[para.id, para], [service.id, service]])

describe('stock left while selling', () => {
  it('takes the cart and unsent sales off the stock, in the base unit', () => {
    const cart = takenMilli(products, [{ productId: 'p1', unitId: 'u-strip', qtyMilli: 2000 }, { productId: 'p1', unitId: 'u-tab', qtyMilli: 3000 }])
    const queued = takenMilli(products, queuedLines([{ lines: [{ product_id: 'p1', product_uom_id: 'u-tab', qty: '5' }] }]))
    expect(leftMilli(para, cart, queued)).toBe(72000) // 100 − 2 strips of 10 − 3 − 5
  })

  it('goes below zero when more is sold than the system shows', () => {
    const cart = takenMilli(products, [{ productId: 'p1', unitId: 'u-strip', qtyMilli: 11000 }])
    expect(leftMilli(para, cart, new Map())).toBe(-10000)
  })

  it('shows nothing for products whose stock is not tracked', () => {
    expect(leftMilli(service, new Map(), new Map())).toBeNull()
  })

  it('marks stock at or below the minimum as low, but not none left', () => {
    const p = { ...para, min_qty: '50.000' } as Product
    expect(isLow(p, 50000)).toBe(true)
    expect(isLow(p, 51000)).toBe(false)
    expect(isLow(p, 0)).toBe(false)
    expect(isLow(para, 1000)).toBe(false) // no minimum known
  })
})
