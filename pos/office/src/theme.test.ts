import { describe, expect, it } from 'vitest'
import { contrast, MIN_CONTRAST, MIN_CONTROL_CONTRAST, themes } from './theme'

// Owner report 2026-11-27: links and reminders hard to read in dark with the company's colour. Every word written in
// the brand colour must reach WCAG AA on the backgrounds it sits on, for any colour a company may choose.
const BRANDS = ['#B45309', '#0f5c8c', '#1F2A44', '#0B1F3A', '#2F3542', '#14532D', '#000000', '#FACC15', '#A3E635', '#F9A8D4', '#FFFFFF', '#7C3AED', '#DC2626']
const TOKENS = ['colorBrandForeground1', 'colorBrandForeground2', 'colorBrandForegroundLink', 'colorBrandForegroundLinkHover', 'colorCompoundBrandForeground1',
  'colorNeutralForeground2BrandHover', 'colorNeutralForeground3BrandHover'] as const

describe('brand text contrast', () => {
  for (const hex of BRANDS) {
    it(`reads in light and dark with ${hex}`, () => {
      const { light, dark } = themes(hex)
      for (const [name, t, bgs] of [['light', light, [light.colorNeutralBackground1, light.colorNeutralBackground2]], ['dark', dark, [dark.colorNeutralBackground1, dark.colorNeutralBackground2, dark.colorNeutralBackground3]]] as const) {
        for (const token of TOKENS) for (const bg of bgs) {
          expect(contrast(t[token], bg), `${name} ${token} ${t[token]} on ${bg}`).toBeGreaterThanOrEqual(MIN_CONTRAST)
        }
      }
    })
  }

  for (const hex of BRANDS) {
    it(`fills buttons that stand out, with readable text, with ${hex}`, () => {
      const { light, dark } = themes(hex)
      for (const [name, t] of [['light', light], ['dark', dark]] as const) {
        expect(contrast(t.colorBrandBackground, t.colorNeutralBackground1), `${name} button ${t.colorBrandBackground}`).toBeGreaterThanOrEqual(MIN_CONTROL_CONTRAST)
        for (const fill of [t.colorBrandBackground, t.colorBrandBackgroundHover, t.colorBrandBackgroundPressed]) {
          expect(contrast(t.colorNeutralForegroundOnBrand, fill), `${name} text ${t.colorNeutralForegroundOnBrand} on ${fill}`).toBeGreaterThanOrEqual(MIN_CONTRAST)
        }
      }
    })
  }
})
