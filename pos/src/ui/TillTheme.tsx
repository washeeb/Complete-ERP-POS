import { FluentProvider } from '@fluentui/react-components'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { themes } from '../../office/src/theme'
import { BRAND_EVENT } from './brandEvent'

/** The product's own colour until the company's arrives (Company → Branding, sent with the bootstrap). */
export const PRODUCT_COLOR = '#031E4C'


/** Light or dark as the till shows it: the choice on this device (theme.ts sets <html data-theme>), else the device's. */
function useDark(): boolean {
  const read = () => {
    const t = document.documentElement.dataset.theme
    return t === 'dark' || (t !== 'light' && typeof matchMedia === 'function' && matchMedia('(prefers-color-scheme: dark)').matches)
  }
  const [dark, setDark] = useState(read)
  useEffect(() => {
    const on = () => setDark(read())
    const watch = new MutationObserver(on)
    watch.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })
    const media = typeof matchMedia === 'function' ? matchMedia('(prefers-color-scheme: dark)') : null
    media?.addEventListener('change', on)
    return () => { watch.disconnect(); media?.removeEventListener('change', on) }
  }, [])
  return dark
}

/**
 * The till in the new look (part 48, owner 2026-11-28): Fluent UI's theme in the company's colour, light or dark, with
 * the same contrast rules as the back office (office/src/theme.ts: brand words 4.5:1, filled buttons 3:1). The till's
 * own stylesheet takes its colours from this theme too (App.css `.till-root`), so every screen follows it.
 */
export function TillTheme({ children }: { children: ReactNode }) {
  const dark = useDark()
  const [brand, setBrand] = useState(PRODUCT_COLOR)
  useEffect(() => {
    const on = (e: Event) => setBrand((e as CustomEvent<string>).detail || PRODUCT_COLOR)
    window.addEventListener(BRAND_EVENT, on)
    return () => window.removeEventListener(BRAND_EVENT, on)
  }, [])
  const t = useMemo(() => themes(brand), [brand])
  return <FluentProvider theme={dark ? t.dark : t.light} className="till-root">{children}</FluentProvider>
}
