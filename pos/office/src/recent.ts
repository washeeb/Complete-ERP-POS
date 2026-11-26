import { appPathFor } from './paths'

/** The pages opened lately, newest first (kept in this browser only: a convenience, not a record). */
const KEY = 'zx-recent'

export type Page = { url: string; label: string }

/** Kept as the new back office's address (part 42); one of the current back office's becomes its new page. */
export function rememberPage(url: string, label: string) {
  const to = appPathFor(url)
  if (!to.startsWith('/office/') || to === '/office/' || !label) return
  try {
    const list = recentPages()
    localStorage.setItem(KEY, JSON.stringify([{ url: to, label }, ...list.filter((x) => x.url.split('?')[0] !== to.split('?')[0])].slice(0, 12)))
  } catch { /* private window or storage off: no list, nothing breaks */ }
}

/** The star on the menu is filled when the page on screen is a favourite. */
export function isFavourite(favourites: Page[], current: Page | null): boolean {
  return !!current && favourites.some((f) => appPathFor(f.url).split('?')[0] === appPathFor(current.url).split('?')[0])
}

/** Pages remembered before part 42 (/admin/…) are listed at their new address. */
export function recentPages(): Page[] {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) ?? '[]')
    return Array.isArray(v) ? v.filter((x) => typeof x?.url === 'string' && /^\/(admin|office)(\/|$)/.test(x.url) && typeof x?.label === 'string')
      .map((x) => ({ url: appPathFor(x.url), label: x.label as string })) : []
  } catch {
    return []
  }
}
