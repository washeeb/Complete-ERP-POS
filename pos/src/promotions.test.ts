/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { computePromotions, type EngineInput } from './promotions'

// The same vectors the server engine must pass (server/tests/Unit/PromotionEngineTest.php).
const spec = JSON.parse(readFileSync(new URL('../../spec/vectors/promotions.json', import.meta.url), 'utf8'))

function build(input: Record<string, unknown>): EngineInput {
  return {
    ...(input as object),
    lines: (input.lines as object[]).map((l) => ({ ...spec._defaults.line, ...l })),
    promotions: (input.promotions as object[]).map((p) => ({ ...spec._defaults.promotion, ...p })),
  } as EngineInput
}

describe('promotion engine (shared vectors)', () => {
  for (const c of spec.cases) {
    it(c.name, () => {
      const r = computePromotions(build(c.input))
      const e = c.expect
      expect(r.total).toBe(e.total)
      expect(r.discount).toBe(e.discount)
      expect(Object.fromEntries(r.lines.map((l) => [l.key, l.discount]))).toEqual(e.lines)
      if ('net' in e) expect(r.net).toBe(e.net)
      if ('tax' in e) expect(r.tax).toBe(e.tax)
      if ('breakdown' in e) expect(Object.fromEntries(r.breakdown.map((b) => [b.code, b.amount_minor]))).toEqual(e.breakdown)
      if ('coupon_applied' in e) expect(r.coupon_applied).toBe(e.coupon_applied)
      if ('coupon_error' in e) expect(r.coupon_error).toBe(e.coupon_error)
    })
  }
})
