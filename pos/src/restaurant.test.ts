import { describe, expect, it } from 'vitest'
import { priceCart } from './cart'
import { billLines, describeChoices, evenSplit, heldCourses, kitchenNote, modifierError, serviceAmount, unpaid } from './restaurant'
import type { DiningItem, DiningOrder, ModifierGroup, Product } from './types'

const pc = (id: string) => [{ id, uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }]
const price = (unit: string, amount: number) => [{ price_list_id: 'l', product_uom_id: unit, min_qty: '1', amount_minor: amount, starts_at: null, ends_at: null }]
const vat = [{ code: 'VAT', name: 'VAT', rate_bp: 1500 }]
const jollof: Product = { id: 'p-jol', sku: 'JOL', name: 'Jollof', track_stock: false, on_hand: null, tax: vat, units: pc('u-jol'), prices: price('u-jol', 6000),
  station: 'KITCHEN', modifier_group_ids: ['g-spice', 'g-extra'] }
const cheese: Product = { id: 'p-chs', sku: 'CHS', name: 'Extra cheese', track_stock: true, on_hand: null, tax: vat, units: pc('u-chs'), prices: price('u-chs', 500) }
const service: Product = { id: 'p-svc', sku: 'SERVICE', name: 'Service charge', track_stock: false, on_hand: null, tax: vat, units: pc('u-svc'), prices: [] }
const groups: ModifierGroup[] = [
  { id: 'g-spice', name: 'Spice', min: 1, max: 1, options: [{ id: 'o-mild', name: 'Mild', product_id: null }, { id: 'o-hot', name: 'Hot', product_id: null }] },
  { id: 'g-extra', name: 'Extras', min: 0, max: 2, options: [{ id: 'o-chs', name: 'Extra cheese', product_id: 'p-chs' }, { id: 'o-noon', name: 'No onions', product_id: null }] },
]
const list = { id: 'l', code: 'RETAIL', name: 'Retail', prices_include_tax: true, is_default: true }
const item = (over: Partial<DiningItem>): DiningItem => ({ id: 'i1', product_id: 'p-jol', product_uom_id: 'u-jol', name: 'Jollof', qty: '1.000', modifiers: [], note: null,
  course: 0, seat: null, station: 'KITCHEN', status: 'fired', paid: false, added_by: 'Ama', fired_at: null, ready_at: null, void_reason: null, ...over })

describe('restaurant at the till', () => {
  it('checks modifier choices like the server and describes them for the kitchen', () => {
    expect(modifierError(jollof, groups, [])).toBe('Choose 1 for Spice.')
    expect(modifierError(jollof, groups, ['o-mild', 'o-hot'])).toBe('Choose 1 for Spice.')
    expect(modifierError(jollof, groups, ['o-hot', 'o-chs', 'o-noon'])).toBeNull()
    expect(describeChoices(jollof, groups, ['o-hot', 'o-chs'])).toEqual({ names: ['Hot', 'Extra cheese'], chargedProductIds: ['p-chs'] })
    expect(kitchenNote(['Hot'], ' well done ')).toBe('Hot, well done')
    expect(kitchenNote([], '')).toBeNull()
  })

  it('pays a bill item with its priced extras, and adds the service charge after discounts', () => {
    const it1 = item({ modifiers: [{ group: 'Spice', option_id: 'o-hot', name: 'Hot', product_id: null }, { group: 'Extras', option_id: 'o-chs', name: 'Extra cheese', product_id: 'p-chs' }] })
    const { lines, missing } = billLines([it1, item({ id: 'i2', product_id: 'gone' })], [jollof, cheese])
    expect(missing.map((m) => m.id)).toEqual(['i2'])
    expect(lines.map((l) => [l.product.id, l.diningItemId, l.note])).toEqual([['p-jol', 'i1', 'Hot, Extra cheese'], ['p-chs', 'i1', 'with Jollof']])

    const withService = [...lines, { key: 'svc', product: service, unit: service.units[0], qtyMilli: 1000, service: true }]
    const cart = priceCart(withService, list, new Date(), { serviceBp: 1000, saleDiscountBp: 1000 })
    // 65.00 less 10 % = 58.50; service 10 % of that = 5.85; VAT inside both.
    expect(cart.lines.find((l) => l.service)!.total).toBe(585)
    expect(cart.total).toBe(5850 + 585)
    expect(cart.tax).toBe(cart.lines.reduce((a, l) => a + l.tax, 0))
    expect(serviceAmount({ total: 10000, net: 8696 }, false, 1000)).toBe(870)
  })

  it('splits evenly to the pesewa and knows what is left to pay and to fire', () => {
    expect(evenSplit(10000, 3)).toEqual([3334, 3333, 3333])
    expect(evenSplit(5, 0)).toEqual([5])
    const order = { items: [item({}), item({ id: 'i2', status: 'void' }), item({ id: 'i3', paid: true }), item({ id: 'i4', status: 'held', course: 2 }), item({ id: 'i5', status: 'held', course: 1 })] } as DiningOrder
    expect(unpaid(order).map((i) => i.id)).toEqual(['i1', 'i4', 'i5'])
    expect(heldCourses(order)).toEqual([1, 2])
  })
})
