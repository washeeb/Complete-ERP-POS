/**
 * "Report a problem" from the till (Phase 14 pilot support). The till remembers its last few errors so the report
 * can carry them to support together with the cashier's own words, the screen and the connection state.
 */

const MAX = 10
let recent: { at: string; message: string }[] = []

/** Remember an error (newest last, at most MAX, each shortened). */
export function rememberError(message: string, at: Date = new Date()): void {
  const text = String(message || '').replace(/\s+/g, ' ').trim().slice(0, 300)
  if (!text) return
  recent = [...recent, { at: at.toISOString(), message: text }].slice(-MAX)
}

export function recentErrors(): { at: string; message: string }[] {
  return [...recent]
}

export function clearErrors(): void {
  recent = []
}

/** What goes with a report, besides the cashier's words. */
export function problemContext(extra: { screen: string; online: boolean; queued?: number; device?: string | null }): Record<string, unknown> {
  return {
    app: import.meta.env.VITE_APP_VERSION ?? 'dev',
    screen: extra.screen,
    online: extra.online,
    queued_sales: extra.queued ?? 0,
    browser: typeof navigator !== 'undefined' ? navigator.userAgent.slice(0, 300) : '',
    screen_size: typeof window !== 'undefined' ? `${window.innerWidth}×${window.innerHeight}` : '',
    desktop_app: typeof window !== 'undefined' && '__TAURI__' in window,
    errors: recentErrors(),
  }
}

/** Catch what the till did not handle itself, so it can be reported. */
export function watchErrors(): void {
  if (typeof window === 'undefined') return
  window.addEventListener('error', (e) => rememberError(e.message || 'Script error'))
  window.addEventListener('unhandledrejection', (e) => rememberError(e.reason instanceof Error ? e.reason.message : String(e.reason)))
}
