import { describe, expect, it } from 'vitest'
import { alsoOn, lineEffect } from './AdjustmentFormPage'

describe('an adjustment line', () => {
  // 40 PC on hand at an average cost of GH₵ 30.00 a piece (3000 minor), counted in cartons of 24 for the second case.
  const pc = { qty: 40, unit: 'PC', factor: 1, cost: 3000, batch: null }
  const ctn = { qty: 40 / 24, unit: 'CTN', factor: 24, cost: 3000, batch: null }

  it('takes away, adds, or sets to the counted quantity, valued at average cost', () => {
    expect(lineEffect({ mode: 'remove', qty: '3', unit_cost: '' }, pc, 2)).toEqual({ after: 37, value: 9000 })
    expect(lineEffect({ mode: 'set', qty: '35', unit_cost: '' }, pc, 2)).toEqual({ after: 35, value: 15000 })
    expect(lineEffect({ mode: 'add', qty: '1', unit_cost: '' }, ctn, 2).value).toBe(72000) // 24 pieces × 30.00
  })

  it('values an increase at the cost typed for it, per unit of the line', () => {
    expect(lineEffect({ mode: 'add', qty: '2', unit_cost: '600' }, ctn, 2).value).toBe(120000) // 2 cartons × 600.00
  })

  it('shows nothing until the product and a quantity are known', () => {
    expect(lineEffect({ mode: 'remove', qty: '', unit_cost: '' }, pc, 2)).toEqual({ after: null, value: null })
    expect(lineEffect({ mode: 'remove', qty: '3', unit_cost: '' }, null, 2)).toEqual({ after: null, value: null })
    expect(lineEffect({ mode: 'remove', qty: '3', unit_cost: '' }, { ...pc, cost: null }, 2).value).toBeNull()
  })
})

describe('the same product on two lines', () => {
  it('is pointed out, unless they are different batches', () => {
    const lines = [{ product_uom_id: 'a', batch_no: '' }, { product_uom_id: 'b', batch_no: '' }, { product_uom_id: 'a', batch_no: '' }]
    expect(alsoOn(lines, 0)).toEqual([3])
    expect(alsoOn(lines, 1)).toEqual([])
    expect(alsoOn([{ product_uom_id: 'a', batch_no: 'B1' }, { product_uom_id: 'a', batch_no: 'B2' }], 0)).toEqual([])
  })
})
