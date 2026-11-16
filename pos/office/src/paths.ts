import type { Menu, MenuItem, MenuTab } from './api'

/**
 * Addresses. The new back office lives at /office; a page not yet rebuilt is the current back office's page (/admin/…)
 * opened inside it, at /office/a/… so the address bar, Back and bookmarks work as for any page.
 */
export const APP = '/office'

/** /admin/products?x=1 → /office/a/products?x=1; /admin → /office/ (home). Anything else is not ours: home. */
export function appPathFor(adminPath: string): string {
  const m = adminPath.match(/^\/admin(\/[^?#]*)?(\?[^#]*)?/)
  if (!m) return `${APP}/`
  const rest = (m[1] ?? '').replace(/\/$/, '')
  return rest === '' ? `${APP}/${m[2] ?? ''}` : `${APP}/a${rest}${m[2] ?? ''}`
}

/** The inverse: which current back-office page an /office address shows (null = a page of the new back office itself). */
export function adminPathFor(appPath: string): string | null {
  const m = appPath.match(/^\/office\/a(\/[^?#]*)(\?[^#]*)?/)
  return m ? `/admin${m[1]}${m[2] ?? ''}` : null
}

/** The new back office's own pages. */
export type Route = { page: 'home' } | { page: 'todo' } | { page: 'classic'; admin: string }

export function routeOf(path: string, search = ''): Route {
  const admin = adminPathFor(path + search)
  if (admin) return { page: 'classic', admin }
  if (/^\/office\/todo\/?$/.test(path)) return { page: 'todo' }
  return { page: 'home' }
}

/** Every page in the menu, flat, with where it sits ("Sales › Till"), for search and for showing where you are. */
export function flatMenu(menu: Menu): (MenuItem & { where: string; tab: string })[] {
  return menu.tabs.flatMap((t: MenuTab) => t.sections.flatMap((s) => s.groups.flatMap((g) => g.items.map((i) => ({
    ...i, tab: t.label, where: [t.label, s.label !== t.label ? s.label : null, g.label].filter(Boolean).join(' › '),
  })))))
}

/** Menu pages whose name (or place) holds every word typed, best first: names starting with the words before the rest. */
export function findPages(menu: Menu, query: string, limit = 8) {
  const words = query.toLowerCase().split(/\s+/).filter(Boolean)
  if (!words.length) return []
  return flatMenu(menu)
    .filter((i) => words.every((w) => `${i.label} ${i.where}`.toLowerCase().includes(w)))
    .sort((a, b) => Number(!a.label.toLowerCase().startsWith(words[0])) - Number(!b.label.toLowerCase().startsWith(words[0])) || a.label.localeCompare(b.label))
    .slice(0, limit)
}

/** The menu area (tab) holding a page, to mark it as the current one. */
export function tabOf(menu: Menu, adminPath: string | null): string | null {
  if (!adminPath) return null
  const path = adminPath.split('?')[0]
  let best: { tab: string; len: number } | null = null
  for (const i of flatMenu(menu)) {
    const p = i.url.split('?')[0]
    if ((path === p || path.startsWith(`${p}/`)) && (!best || p.length > best.len)) best = { tab: i.tab, len: p.length }
  }
  return best?.tab ?? null
}
