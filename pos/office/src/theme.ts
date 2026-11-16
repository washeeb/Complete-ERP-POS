import { createDarkTheme, createLightTheme, type BrandVariants, type Theme } from '@fluentui/react-components'

/**
 * The company's colour (Company → Branding) as Fluent's 16 brand shades: 80 is the colour itself, lower numbers darker,
 * higher lighter, so buttons, links and selections are in the company's colour, in light and dark.
 */
export function brandRamp(hex: string): BrandVariants {
  const rgb = parse(hex) ?? [15, 92, 140]
  const keys = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160] as const
  const out = {} as Record<(typeof keys)[number], string>
  for (const k of keys) {
    const t = (k - 80) / 80 // −0.875 … +1
    out[k] = t <= 0 ? mix(rgb, [0, 0, 0], -t * 0.92) : mix(rgb, [255, 255, 255], t * 0.88)
  }
  return out as BrandVariants
}

export function themes(hex: string): { light: Theme; dark: Theme } {
  const brand = brandRamp(hex)
  const dark = createDarkTheme(brand)
  // In dark, Fluent uses a lighter shade for the brand so it reads on a dark background.
  return { light: createLightTheme(brand), dark: { ...dark, colorBrandForeground1: brand[110], colorBrandForeground2: brand[120] } }
}

/** Text that reads on a colour: white on dark colours, near-black on light ones. */
export function onColor(hex: string): string {
  const [r, g, b] = (parse(hex) ?? [0, 0, 0]).map((c) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.4 ? '#1b1b1f' : '#ffffff'
}

/** A dark shade of the brand for the menu bar, when the company did not choose a menu colour. */
export function menuShade(hex: string): string {
  return mix(parse(hex) ?? [15, 92, 140], [10, 18, 32], 0.55)
}

function parse(hex: string): [number, number, number] | null {
  const m = hex.trim().match(/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i)
  if (!m) return null
  const h = m[1].length === 3 ? [...m[1]].map((c) => c + c).join('') : m[1]
  return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) as [number, number, number]
}

function mix(a: [number, number, number], b: [number, number, number], t: number): string {
  return `#${a.map((c, i) => Math.round(c + (b[i] - c) * t).toString(16).padStart(2, '0')).join('')}`
}
