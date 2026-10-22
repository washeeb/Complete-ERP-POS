import { describe, expect, it } from 'vitest'
import { addToCart } from './cart'
import { dosageEscPos, dosageLabels } from './dosage'
import type { Bootstrap, PostedSale, Product } from './types'

const boot = { company: { name: 'Asante Pharmacy', timezone: 'Africa/Accra' }, branch: { name: 'Osu', phone: '0302000000' } } as unknown as Bootstrap
const sale = {
  receipt_no: 'R-1', occurred_at: '2026-10-22T10:00:00Z', walk_in: { name: 'AMA OWUSU', phone: null },
  lines: [
    { line_no: 1, sku: 'AMOX', name: 'AMOXICILLIN 500MG', uom: 'CAP', qty: '15.000', unit_price_minor: 100, line_total_minor: 1500, dosage: '1 CAPSULE 3 TIMES DAILY FOR 5 DAYS' },
    { line_no: 2, sku: 'MILO', name: 'MILO 400G', uom: 'PC', qty: '1.000', unit_price_minor: 100, line_total_minor: 100, dosage: null },
  ],
} as unknown as PostedSale

describe('dosage labels', () => {
  it('makes one label per line with directions, and none for the others', () => {
    expect(dosageLabels(sale)).toEqual([{ name: 'AMOXICILLIN 500MG', qty: '15 CAP', dosage: '1 CAPSULE 3 TIMES DAILY FOR 5 DAYS' }])
    const bytes = atob(dosageEscPos(sale, boot).toBase64())
    expect(bytes).toContain('Patient: AMA OWUSU')
    expect(bytes).toContain('1 CAPSULE 3 TIMES DAILY FOR 5 DAYS')
    expect(bytes).toContain('KEEP OUT OF REACH OF CHILDREN')
    expect(bytes).not.toContain('MILO')
  })

  it('starts a cart line with the product usual directions', () => {
    const product = { id: 'p', dosage: '2 TABLETS TWICE DAILY', units: [] } as unknown as Product
    const unit = { id: 'u' } as never
    expect(addToCart([], product, unit)[0].dosage).toBe('2 TABLETS TWICE DAILY')
    expect(addToCart([], { ...product, dosage: null }, unit)[0].dosage).toBeUndefined()
  })
})
