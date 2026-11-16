/** The pages opened lately, newest first (kept in this browser only: a convenience, not a record). */
const KEY = 'zx-recent'

export type Page = { url: string; label: string }

export function rememberPage(url: string, label: string) {
  if (!url.startsWith('/admin') || !label) return
  try {
    const list = recentPages()
    localStorage.setItem(KEY, JSON.stringify([{ url, label }, ...list.filter((x) => x.url.split('?')[0] !== url.split('?')[0])].slice(0, 12)))
  } catch { /* private window or storage off: no list, nothing breaks */ }
}

/** The star on the menu is filled when the page on screen is a favourite. */
export function isFavourite(favourites: Page[], current: Page | null): boolean {
  return !!current && favourites.some((f) => f.url.split('?')[0] === current.url.split('?')[0])
}

export function recentPages(): Page[] {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) ?? '[]')
    return Array.isArray(v) ? v.filter((x) => typeof x?.url === 'string' && x.url.startsWith('/admin') && typeof x?.label === 'string') : []
  } catch {
    return []
  }
}
