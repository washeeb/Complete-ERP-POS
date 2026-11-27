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

const STEPS = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160] as const

/** WCAG 2.2 AA for normal text: what every brand-coloured word (links, labels, figures) must reach on its background. */
export const MIN_CONTRAST = 4.5

/** The contrast ratio between two colours (1 … 21), as WCAG defines it. */
export function contrast(a: string, b: string): number {
  const [x, y] = [luminance(a), luminance(b)].sort((m, n) => n - m)
  return (x + 0.05) / (y + 0.05)
}

/**
 * The brand shade nearest to `from` that reads on `bg` (MIN_CONTRAST): lighter ones on a dark background, darker
 * ones on a light one; white or black when no shade does. The next one along is the hover shade.
 */
function readable(brand: BrandVariants, bg: string, from: number, lighter: boolean): [string, string] {
  const order = lighter ? STEPS.filter((k) => k >= from) : [...STEPS].reverse().filter((k) => k <= from)
  const i = order.findIndex((k) => contrast(brand[k], bg) >= MIN_CONTRAST)
  if (i < 0) return lighter ? ['#ffffff', '#ffffff'] : ['#000000', '#000000']
  const next = order[i + 1] !== undefined && contrast(brand[order[i + 1]], bg) >= MIN_CONTRAST ? brand[order[i + 1]] : brand[order[i]]
  return [brand[order[i]], next]
}

/** Every Fluent token that writes text in the brand colour, set to shades that read on the theme's backgrounds. */
function brandText(theme: Theme, brand: BrandVariants, lighter: boolean): Theme {
  const bg = theme.colorNeutralBackground1 // cards and panels; the page behind them is a little darker (dark) or the same (light)
  const [base, hover] = readable(brand, bg, lighter ? 110 : 80, lighter)
  const [strong, strongHover] = readable(brand, bg, lighter ? 120 : 70, lighter)
  return {
    ...theme,
    colorBrandForeground1: base, colorBrandForeground2: strong, colorBrandForeground2Hover: strongHover, colorBrandForeground2Pressed: strong,
    colorBrandForegroundLink: base, colorBrandForegroundLinkHover: hover, colorBrandForegroundLinkPressed: base, colorBrandForegroundLinkSelected: base,
    colorCompoundBrandForeground1: base, colorCompoundBrandForeground1Hover: hover, colorCompoundBrandForeground1Pressed: base,
    colorNeutralForeground2BrandHover: base, colorNeutralForeground2BrandPressed: base, colorNeutralForeground2BrandSelected: base,
    colorNeutralForeground3BrandHover: base, colorNeutralForeground3BrandPressed: base, colorNeutralForeground3BrandSelected: base,
  }
}

/** WCAG 2.2 AA for controls (1.4.11): a filled button, a selected tab or a focused box against what is around it. */
export const MIN_CONTROL_CONTRAST = 3

/**
 * Filled controls in the brand colour (primary buttons, Create, switches, selected items, focus lines): a shade that
 * stands out from the background, with white or near-black on it, whichever reads (a navy brand in dark had a Create
 * button almost invisible, 1.1:1).
 */
function brandFill(theme: Theme, brand: BrandVariants, lighter: boolean): Theme {
  const bg = theme.colorNeutralBackground1
  // Shades moving away from the background: lighter in dark, darker in light.
  const order = (lighter ? STEPS.filter((k) => k >= 70) : [...STEPS].reverse().filter((k) => k <= 80)).map((k) => brand[k])
  order.push(lighter ? '#ffffff' : '#000000')
  const shades = (i: number) => [order[i], order[Math.min(i + 1, order.length - 1)], order[Math.min(i + 2, order.length - 1)]]
  const fits = (i: number) => contrast(order[i], bg) >= MIN_CONTROL_CONTRAST && Math.min(...shades(i).map((f) => contrast(textOn(shades(i)), f))) >= MIN_CONTRAST
  const i = Math.max(0, order.findIndex((_, n) => fits(n)))
  const [fill, hover, pressed] = shades(i)
  return {
    ...theme,
    colorBrandBackground: fill, colorBrandBackgroundHover: hover, colorBrandBackgroundPressed: pressed, colorBrandBackgroundSelected: hover,
    colorCompoundBrandBackground: fill, colorCompoundBrandBackgroundHover: hover, colorCompoundBrandBackgroundPressed: pressed,
    colorBrandStroke1: fill, colorCompoundBrandStroke: fill, colorCompoundBrandStrokeHover: hover, colorCompoundBrandStrokePressed: pressed,
    colorNeutralForegroundOnBrand: textOn([fill, hover, pressed]),
  }
}

/**
 * Light and dark themes in the company's colour. Any brand colour works: words written in it (links, reminders,
 * figures) and controls filled with it always reach WCAG AA contrast, so a dark brand stays readable in dark and a pale
 * one in light (owner report 2026-11-27: grey-blue links on dark grey).
 */
export function themes(hex: string): { light: Theme; dark: Theme } {
  const brand = brandRamp(hex)
  return {
    light: brandFill(brandText(createLightTheme(brand), brand, false), brand, false),
    dark: brandFill(brandText(createDarkTheme(brand), brand, true), brand, true),
  }
}

function luminance(hex: string): number {
  const [r, g, b] = (parse(hex) ?? [0, 0, 0]).map((c) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/** White or near-black, whichever reads better on all these shades (a button's normal, hover and pressed colours). */
function textOn(fills: string[]): string {
  const worst = (text: string) => Math.min(...fills.map((f) => contrast(text, f)))
  return worst('#ffffff') >= worst('#000000') ? '#ffffff' : '#000000'
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
