/**
 * Colour theme of the till app on this device: follow the device (System), or always Light or Dark. Remembered in the
 * browser; applied to <html data-theme> before the first paint (main.tsx) so the screen does not flash.
 */
export type ThemeChoice = 'system' | 'light' | 'dark'

export const THEMES: Record<ThemeChoice, string> = { system: 'System', light: 'Light', dark: 'Dark' }

const KEY = 'pos-theme'

export function getTheme(): ThemeChoice {
  try {
    const v = localStorage.getItem(KEY)
    return v === 'light' || v === 'dark' ? v : 'system'
  } catch {
    return 'system'
  }
}

export function applyTheme(choice: ThemeChoice, remember = true): void {
  const el = document.documentElement
  if (choice === 'system') delete el.dataset.theme
  else el.dataset.theme = choice
  if (!remember) return
  try {
    if (choice === 'system') localStorage.removeItem(KEY)
    else localStorage.setItem(KEY, choice)
  } catch { /* private window: the choice lasts for this visit */ }
}
