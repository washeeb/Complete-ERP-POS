import { describe, expect, it } from 'vitest'
import { brandRamp, menuShade, onColor } from './theme'

describe('the company colour as Fluent brand shades', () => {
  it('keeps the colour itself at 80, darker below and lighter above', () => {
    const r = brandRamp('#0f5c8c')
    expect(r[80]).toBe('#0f5c8c')
    const light = (h: string) => parseInt(h.slice(1, 3), 16) + parseInt(h.slice(3, 5), 16) + parseInt(h.slice(5, 7), 16)
    expect(light(r[10])).toBeLessThan(light(r[70]))
    expect(light(r[90])).toBeGreaterThan(light(r[80]))
    expect(light(r[160])).toBeGreaterThan(light(r[120]))
    expect(Object.keys(r)).toHaveLength(16)
  })

  it('falls back to a default for a colour it cannot read, and picks readable text', () => {
    expect(brandRamp('not a colour')[80]).toBe('#0f5c8c')
    expect(onColor('#0f5c8c')).toBe('#ffffff')
    expect(onColor('#ffd400')).toBe('#1b1b1f')
    expect(menuShade('#0f5c8c')).toMatch(/^#[0-9a-f]{6}$/)
  })
})
