import { describe, expect, it } from 'vitest'
import { calculate, changeBreakdown } from './calc'

describe('till calculator', () => {
  it('does sums with the usual order, brackets and decimals', () => {
    expect(calculate('12 + 3 × 4')).toBe(24)
    expect(calculate('(12 + 3) * 4')).toBe(60)
    expect(calculate('0.1 + 0.2')).toBe(0.3)
    expect(calculate('100 ÷ 8')).toBe(12.5)
    expect(calculate('-5 + 2')).toBe(-3)
    expect(calculate('1,250 + 50')).toBe(1300)
  })
  it('treats percent like a shop calculator', () => {
    expect(calculate('200 + 10%')).toBe(220)
    expect(calculate('200 - 25%')).toBe(150)
    expect(calculate('50%')).toBe(0.5)
    expect(calculate('80 * 15%')).toBe(12)
  })
  it('refuses anything that is not a sum', () => {
    expect(() => calculate('2 +')).toThrow()
    expect(() => calculate('alert(1)')).toThrow()
    expect(() => calculate('4 / 0')).toThrow('divide by zero')
    expect(calculate('')).toBe(0)
  })
})

describe('change in notes and coins', () => {
  it('counts out the change largest first', () => {
    expect(changeBreakdown(3750)).toEqual([{ value: 2000, count: 1 }, { value: 1000, count: 1 }, { value: 500, count: 1 }, { value: 200, count: 1 }, { value: 50, count: 1 }])
    expect(changeBreakdown(0)).toEqual([])
    expect(changeBreakdown(15)).toEqual([{ value: 10, count: 1 }, { value: 1, count: 5 }])
  })
})
