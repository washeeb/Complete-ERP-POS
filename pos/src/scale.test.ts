import { describe, expect, it } from 'vitest'
import { addMarkdown, addWeighed, priceCart } from './cart'
import { ean13Valid, findMarkdown, parseScaleLabel, productForPlu } from './scale'
import type { Product } from './types'

const kg = { id: 'u-kg', uom_code: 'KG', uom_name: 'Kilogram', decimals: 3, factor: '1.000000', is_base: true, barcodes: [] }
const pc = { id: 'u-pc', uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }
const tomatoes: Product = { id: 'p-tom', sku: 'TOM', name: 'Tomatoes', plu: '00123', track_stock: true, on_hand: null, tax: [], units: [kg],
  prices: [{ price_list_id: 'l', product_uom_id: 'u-kg', min_qty: '0.001', amount_minor: 1200, starts_at: null, ends_at: null }] }
const yogo: Product = { id: 'p-yog', sku: 'YOG', name: 'Yogo', track_stock: true, on_hand: null, tax: [], units: [pc],
  prices: [{ price_list_id: 'l', product_uom_id: 'u-pc', min_qty: '1', amount_minor: 1000, starts_at: null, ends_at: null }] }
const list = { id: 'l', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }

describe('grocery scale labels and markdown stickers', () => {
  it('checks EAN-13 digits like the server', () => {
    expect(ean13Valid('2100123012503')).toBe(true)
    expect(ean13Valid('2100123012508')).toBe(false)
  })

  it('reads item and weight from a scale label with a known prefix', () => {
    expect(parseScaleLabel('2100123012503', ['21'])).toEqual({ plu: '00123', grams: 1250 })
    expect(parseScaleLabel('2100123012503', ['22'])).toBeNull()
    expect(productForPlu([yogo, tomatoes], '123')?.product.id).toBe('p-tom')
  })

  it('prices a weighed pack by weight and a sticker at its own price, outside promotions', () => {
    let lines = addWeighed([], tomatoes, kg, 1250)
    const md = { id: 'm1', code: '2912345678903', product_id: 'p-yog', product_uom_id: 'u-pc', price_minor: 700, percent_bp: 3000, valid_until: '2099-01-01' }
    const hit = findMarkdown({ scale_prefixes: ['21'], markdown_prefix: '29', markdowns: [md] }, md.code, [yogo])!
    lines = addMarkdown(lines, hit.product, hit.unit, hit.markdown)
    const promo = { id: 'pr', name: '10% yogo', type: 'percent' as const, product_ids: ['p-yog'], category_ids: [], value_bp: 1000, value_minor: 0, buy_qty: 0, get_qty: 0, bundle_qty: 0,
      min_spend_minor: 0, coupon_code: null, starts_at: null, ends_at: null, days: [], time_from: null, time_to: null, branch_ids: [], price_list_ids: [] }
    const cart = priceCart(lines, list, new Date(), { promotions: [promo] })
    expect(cart.lines.map((l) => l.total)).toEqual([1500, 700])
  })
})
