import type { Menu, MenuItem, MenuTab } from './api'

/**
 * Addresses. The new back office lives at /office; a page not yet rebuilt is the current back office's page (/admin/…)
 * opened inside it, at /office/a/… so the address bar, Back and bookmarks work as for any page.
 */
export const APP = '/office'

/**
 * Pages already rebuilt in the new back office (screen by screen, owner decision 2026-11-16): a link to the current
 * back office's page opens the new one instead. Each still opens the old way at /office/a/… ("classic version").
 */
const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
const REBUILT: { admin: RegExp; app: (m: RegExpMatchArray) => string }[] = [
  { admin: /^\/admin\/sales\/?$/, app: () => `${APP}/sales` },
  { admin: new RegExp(`^/admin/sales/(${UUID})$`), app: (m) => `${APP}/sales/${m[1]}` },
  { admin: /^\/admin\/shifts\/?$/, app: () => `${APP}/shifts` },
  { admin: /^\/admin\/till-drawer-events\/?$/, app: () => `${APP}/drawer` },
]

/** /admin/products?x=1 → /office/a/products?x=1; /admin → /office/ (home); a rebuilt page → its new address. Anything else is not ours: home. */
export function appPathFor(adminPath: string): string {
  const m = adminPath.match(/^\/admin(\/[^?#]*)?(\?[^#]*)?/)
  if (!m) return `${APP}/`
  const path = `/admin${m[1] ?? ''}`
  for (const r of REBUILT) {
    const hit = path.match(r.admin)
    if (hit) return r.app(hit)
  }
  const rest = (m[1] ?? '').replace(/\/$/, '')
  return rest === '' ? `${APP}/${m[2] ?? ''}` : `${APP}/a${rest}${m[2] ?? ''}`
}

/** The old version of any page, framed (for "Open the classic version"), rebuilt or not. */
export function classicPathFor(adminPath: string): string {
  return `${APP}/a${adminPath.replace(/^\/admin/, '') || '/'}`
}

/** The inverse: which current back-office page an /office address shows (null = a page of the new back office itself). */
export function adminPathFor(appPath: string): string | null {
  const m = appPath.match(/^\/office\/a(\/[^?#]*)(\?[^#]*)?/)
  return m ? `/admin${m[1]}${m[2] ?? ''}` : null
}

/** The new back office's own pages. */
export type Route = { page: 'home' } | { page: 'todo' } | { page: 'sales' } | { page: 'sale'; id: string } | { page: 'shifts' } | { page: 'shift'; id: string }
  | { page: 'drawer' } | { page: 'classic'; admin: string }

export function routeOf(path: string, search = ''): Route {
  const admin = adminPathFor(path + search)
  if (admin) return { page: 'classic', admin }
  if (/^\/office\/todo\/?$/.test(path)) return { page: 'todo' }
  if (/^\/office\/sales\/?$/.test(path)) return { page: 'sales' }
  const sale = path.match(new RegExp(`^/office/sales/(${UUID})/?$`))
  if (sale) return { page: 'sale', id: sale[1] }
  if (/^\/office\/shifts\/?$/.test(path)) return { page: 'shifts' }
  const shift = path.match(new RegExp(`^/office/shifts/(${UUID})/?$`))
  if (shift) return { page: 'shift', id: shift[1] }
  if (/^\/office\/drawer\/?$/.test(path)) return { page: 'drawer' }
  return { page: 'home' }
}

/** The current back office's page a route stands for (to mark its menu area, and for the favourites star). */
export function adminEquivalent(route: Route): string | null {
  switch (route.page) {
    case 'classic': return route.admin
    case 'sales': return '/admin/sales'
    case 'sale': return `/admin/sales/${route.id}`
    case 'shifts': case 'shift': return '/admin/shifts'
    case 'drawer': return '/admin/till-drawer-events'
    default: return null
  }
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
