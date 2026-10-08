import { describe, expect, it } from 'vitest'
import { byStaff, coveringItems, isService, slots } from './services'
import type { CartLine } from './cart'
import type { Appointment, Product, ServicePass } from './types'

const product = (id: string, minutes: number | null): Product => ({ id, sku: id, name: id, track_stock: false, on_hand: null, tax: [], prices: [], service_minutes: minutes,
  units: [{ id: `u-${id}`, uom_code: 'PC', uom_name: 'Piece', decimals: 0, factor: '1.000000', is_base: true, barcodes: [] }] })
const pass: ServicePass = { id: 'p1', number: 'PASS-HQ-000001', name: '5 massages', expires_on: null,
  items: [{ label: 'Massage', product_ids: ['massage'], sessions: 5, remaining: 2 }, { label: 'Haircuts', product_ids: ['cut'], sessions: null, remaining: null }] }

describe('services at the till', () => {
  it('knows a bookable service by its duration', () => {
    expect(isService(product('cut', 45))).toBe(true)
    expect(isService(product('shampoo', null))).toBe(false)
  })

  it('offers booking times between the day start and end', () => {
    expect(slots('08:00', '09:00', 15)).toEqual(['08:00', '08:15', '08:30', '08:45'])
    expect(slots('17:30', '18:00', 20)).toEqual(['17:30', '17:50'])
  })

  it('finds the pass items that can pay for a service, less what is already in the cart', () => {
    const massage = product('massage', 60)
    expect(coveringItems([pass], 'massage').map((x) => [x.item, x.left])).toEqual([[0, 2]])
    const cart: CartLine[] = [{ key: 'a', product: massage, unit: massage.units[0], qtyMilli: 2000, pass: { id: 'p1', item: 0, number: pass.number } }]
    expect(coveringItems([pass], 'massage', cart)).toEqual([]) // both sessions already in the cart
    expect(coveringItems([pass], 'cut', cart).map((x) => x.left)).toEqual([null]) // unlimited
    expect(coveringItems([pass], 'nails')).toEqual([])
  })

  it('lists the day by staff member in time order, without cancellations', () => {
    const a = (id: string, emp: string, at: string, status: Appointment['status'] = 'booked'): Appointment => ({ id, number: id, customer_id: null, customer_name: id, phone: null,
      employee_id: emp, employee_name: null, product_id: 'cut', service: 'Haircut', starts_at: at, ends_at: at, status, status_label: status, note: null })
    const day = byStaff([{ id: 'e1', name: 'Abla', job_title: null }, { id: 'e2', name: 'Yaw', job_title: null }],
      [a('x', 'e1', '2026-10-22T11:00:00Z'), a('y', 'e1', '2026-10-22T09:00:00Z'), a('z', 'e2', '2026-10-22T10:00:00Z', 'cancelled')])
    expect(day.map((d) => d.appointments.map((x) => x.id))).toEqual([['y', 'x'], []])
  })
})
