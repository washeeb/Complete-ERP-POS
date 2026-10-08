import { describe, expect, it } from 'vitest'
import { priceCart } from './cart'
import { styleGrid, styleOf } from './fashion'
import type { Product, Style } from './types'

const style: Style = { id: 'st', code: 'KD', name: 'Kente dress', sizes: ['S', 'M'], colours: ['Black', 'Gold'], season: 'SS26', collection: null }
const v = (id: string, colour: string, size: string, onHand: string | null): Product => ({ id, sku: `KD-${colour}-${size}`, name: `Kente dress ${colour} ${size}`,
  track_stock: true, on_hand: onHand, tax: [], units: [{ id: `u-${id}`, uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }],
  prices: [{ price_list_id: 'l', product_uom_id: `u-${id}`, min_qty: '1', amount_minor: 45000, starts_at: null, ends_at: null }], style_id: 'st', colour, size })
const list = { id: 'l', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }

describe('fashion at the till', () => {
  it('lays out a style as colours × sizes with stock, gaps where no product exists', () => {
    const products = [v('a', 'Black', 'S', '3.000'), v('b', 'Black', 'M', '0.000'), v('c', 'Gold', 'M', '5.000')]
    const g = styleGrid(style, products)
    expect(g.cells.map((r) => r.map((c) => c?.onHand ?? null))).toEqual([[3, 0], [null, 5]])
    expect(styleOf(products[0], [style])?.code).toBe('KD')
    expect(styleOf({ ...products[0], style_id: null }, [style])).toBeNull()
  })

  it('charges an alteration at its agreed price, outside price lists', () => {
    const alt: Product = { id: 'alt', sku: 'ALTERATION', name: 'Alteration', track_stock: false, on_hand: null, tax: [], units: [{ id: 'u-alt', uom_code: 'PC', uom_name: 'Piece',
      decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }], prices: [] }
    const cart = priceCart([{ key: 'alt', product: alt, unit: alt.units[0], qtyMilli: 1000, alteration: { id: 'a1', charge_minor: 5000 } }], list, new Date())
    expect(cart.total).toBe(5000)
    expect(cart.unpriced).toHaveLength(0)
  })
})
