import { describe, expect, it } from 'vitest'
import { allocate, divRound, factorMicro, formatMoney, lineTax, lineTotal, parseMoney, sumTax, toMilli } from './money'

// Same vectors as server/tests/Unit/MoneyAndTaxTest.php — if one side changes, both must.
const gh = [
  { code: 'VAT', name: 'VAT', rate_bp: 1500 },
  { code: 'NHIL', name: 'NHIL', rate_bp: 250 },
  { code: 'GETFUND', name: 'GETFund', rate_bp: 250 },
]

describe('quantities', () => {
  it('parses to exact thousandths', () => {
    expect(toMilli('1')).toBe(1000)
    expect(toMilli('1.5')).toBe(1500)
    expect(toMilli('0.125')).toBe(125)
    expect(toMilli('-2.25')).toBe(-2250)
    expect(() => toMilli('1.2345')).toThrow()
  })
  it('converts factors and rounds half away from zero', () => {
    expect(divRound(2000 * factorMicro('24.000000'), 1_000_000)).toBe(48000)
    expect(divRound(5, 10)).toBe(1)
    expect(divRound(4, 10)).toBe(0)
    expect(divRound(-5, 10)).toBe(-1)
    expect(lineTotal(1500, 1999)).toBe(2999) // 2998.5 → 2999
  })
})

describe('tax', () => {
  it('splits a tax-inclusive price exactly', () => {
    const t = lineTax(1000, gh, true)
    expect([t.gross, t.net, t.tax]).toEqual([1000, 833, 167])
    expect(t.breakdown.map((b) => b.amount_minor)).toEqual([125, 21, 21])
  })
  it('adds tax on exclusive prices', () => {
    const t = lineTax(1000, gh, false)
    expect([t.net, t.tax, t.gross]).toEqual([1000, 200, 1200])
  })
  it('keeps sums exact for awkward amounts and exempt items', () => {
    expect(lineTax(999, [], true).tax).toBe(0)
    for (const g of [1, 7, 99, 333, 12345, 999999]) {
      const t = lineTax(g, gh, true)
      expect(t.net + t.tax).toBe(g)
      expect(t.breakdown.reduce((a, b) => a + b.amount_minor, 0)).toBe(t.tax)
    }
  })
  it('allocates the spare pesewa like the server', () => {
    expect(lineTax(500, gh, true).breakdown.map((b) => b.amount_minor)).toEqual([62, 11, 10])
    expect(allocate(83, [1500, 250, 250])).toEqual([62, 11, 10])
    expect(sumTax([lineTax(1000, gh, true).breakdown, lineTax(500, gh, true).breakdown]).map((b) => b.amount_minor)).toEqual([187, 32, 31])
  })
})

describe('money', () => {
  it('parses and formats', () => {
    expect(parseMoney('12.5')).toBe(1250)
    expect(parseMoney('1,234.56')).toBe(123456)
    expect(() => parseMoney('1.234')).toThrow()
    expect(formatMoney(123456, 'GHS')).toBe('GH₵ 1,234.56')
  })
})
