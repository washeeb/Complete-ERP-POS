import { describe, expect, it } from 'vitest'
import { achieved, stopsToday, withDeposits } from './distribution'
import type { CartLine } from './cart'
import type { Product, VanDay } from './types'

const product = (id: string, extra: Partial<Product> = {}, decimals = 0): Product => ({ id, sku: id, name: id, track_stock: true, on_hand: '0', tax: [], prices: [],
  units: [{ id: `u-${id}`, uom_code: 'PC', uom_name: 'Piece', decimals, factor: '1.000000', is_base: true, barcodes: [] }], ...extra })
const crate = product('crate', { is_container: true })
const bottle = product('bottle', { is_container: true })
const malt = product('malt', { deposit_product_id: 'bottle', deposit_per_unit: '1' })
const single = product('single', { deposit_product_id: 'crate', deposit_per_unit: '0.042' }) // a crate holds 24
const line = (p: Product, qtyMilli: number, extra: Partial<CartLine> = {}): CartLine => ({ key: p.id, product: p, unit: p.units[0], qtyMilli, ...extra })

describe('deposits and empties', () => {
  it('adds the containers that go with what is sold, and follows quantity changes', () => {
    const out = withDeposits([line(malt, 6000)], [malt, bottle, crate])
    expect(out.map((l) => [l.product.id, l.qtyMilli, !!l.autoDeposit])).toEqual([['malt', 6000, false], ['bottle', 6000, true]])
    // Recomputed, not added twice.
    const again = withDeposits([{ ...out[0], qtyMilli: 2000 }, out[1]], [malt, bottle, crate])
    expect(again.filter((l) => l.autoDeposit).map((l) => l.qtyMilli)).toEqual([2000])
  })

  it('rounds part of a crate up to a whole crate, and leaves empties handed back alone', () => {
    const out = withDeposits([line(single, 10000), line(crate, -3000, { key: 'empties' })], [single, crate])
    expect(out.map((l) => [l.product.id, l.qtyMilli])).toEqual([['single', 10000], ['crate', -3000], ['crate', 1000]])
    expect(withDeposits([line(single, 0)], [single, crate])).toHaveLength(1)
  })
})

describe('the van day', () => {
  const day: VanDay = { van: { id: 'v', code: 'V01', name: 'Van', vehicle_reg: null }, routes: [], weekday: 1,
    stops: [{ route_id: 'r', customer_id: 'b', sequence: 2 }, { route_id: 'r', customer_id: 'a', sequence: 1 }],
    month: { target_minor: 500000, sold_minor: 123400, visit_target: null } }
  it('lists stops in order with what happened', () => {
    expect(stopsToday(day, { b: 'sold' }).map((s) => [s.customer_id, s.outcome])).toEqual([['a', null], ['b', 'sold']])
  })
  it('works out the share of the target', () => {
    expect(achieved(day)).toBe(24)
    expect(achieved({ ...day, month: { ...day.month, target_minor: null } })).toBeNull()
  })
})
