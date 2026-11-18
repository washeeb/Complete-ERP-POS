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
const REBUILT: { admin: RegExp; app: (m: RegExpMatchArray, query: URLSearchParams) => string }[] = [
  { admin: /^\/admin\/sales\/?$/, app: () => `${APP}/sales` },
  { admin: new RegExp(`^/admin/sales/(${UUID})$`), app: (m) => `${APP}/sales/${m[1]}` },
  { admin: /^\/admin\/shifts\/?$/, app: () => `${APP}/shifts` },
  { admin: /^\/admin\/till-drawer-events\/?$/, app: () => `${APP}/drawer` },
  { admin: /^\/admin\/stock-levels\/?$/, app: (_, q) => `${APP}/stock${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/stock-movements\/?$/, app: () => `${APP}/stock/movements` },
  { admin: /^\/admin\/stock-transfers\/?$/, app: () => `${APP}/transfers` },
  { admin: /^\/admin\/stock-transfers\/create\/?$/, app: () => `${APP}/transfers/new` },
  { admin: new RegExp(`^/admin/stock-transfers/(${UUID})$`), app: (m) => `${APP}/transfers/${m[1]}` },
  { admin: new RegExp(`^/admin/stock-transfers/(${UUID})/edit$`), app: (m) => `${APP}/transfers/${m[1]}/edit` },
  { admin: /^\/admin\/stock-requests\/?$/, app: () => `${APP}/requests` },
  { admin: /^\/admin\/stock-requests\/create\/?$/, app: () => `${APP}/requests/new` },
  { admin: new RegExp(`^/admin/stock-requests/(${UUID})$`), app: (m) => `${APP}/requests/${m[1]}` },
  { admin: /^\/admin\/customers\/?$/, app: (_, q) => `${APP}/customers${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/customers\/create\/?$/, app: () => `${APP}/customers/new` },
  { admin: new RegExp(`^/admin/customers/(${UUID})$`), app: (m) => `${APP}/customers/${m[1]}` },
  { admin: new RegExp(`^/admin/customers/(${UUID})/edit$`), app: (m) => `${APP}/customers/${m[1]}/edit` },
  { admin: /^\/admin\/invoices\/?$/, app: (_, q) => `${APP}/invoices${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/invoices/(${UUID})$`), app: (m) => `${APP}/invoices/${m[1]}` },
  { admin: /^\/admin\/suppliers\/?$/, app: (_, q) => `${APP}/suppliers${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/suppliers\/create\/?$/, app: () => `${APP}/suppliers/new` },
  { admin: new RegExp(`^/admin/suppliers/(${UUID})/edit$`), app: (m) => `${APP}/suppliers/${m[1]}` },
  { admin: /^\/admin\/purchase-orders\/?$/, app: (_, q) => `${APP}/purchase-orders${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/purchase-orders\/create\/?$/, app: () => `${APP}/purchase-orders/new` },
  { admin: new RegExp(`^/admin/purchase-orders/(${UUID})$`), app: (m) => `${APP}/purchase-orders/${m[1]}` },
  { admin: new RegExp(`^/admin/purchase-orders/(${UUID})/edit$`), app: (m) => `${APP}/purchase-orders/${m[1]}/edit` },
  { admin: /^\/admin\/stock-counts\/?$/, app: () => `${APP}/counts` },
  { admin: new RegExp(`^/admin/stock-counts/(${UUID})$`), app: (m) => `${APP}/counts/${m[1]}` },
  { admin: /^\/admin\/stock-adjustments\/?$/, app: () => `${APP}/adjustments` },
  { admin: /^\/admin\/stock-adjustments\/create\/?$/, app: () => `${APP}/adjustments/new` },
  { admin: new RegExp(`^/admin/stock-adjustments/(${UUID})$`), app: (m) => `${APP}/adjustments/${m[1]}` },
  { admin: new RegExp(`^/admin/stock-adjustments/(${UUID})/edit$`), app: (m) => `${APP}/adjustments/${m[1]}/edit` },
  { admin: /^\/admin\/products\/create\/?$/, app: () => `${APP}/products/new` },
  { admin: new RegExp(`^/admin/products/(${UUID})/edit$`), app: (m) => `${APP}/products/${m[1]}/edit` },
  { admin: /^\/admin\/products\/?$/, app: (_, q) => `${APP}/products${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` }, // a search carries over
]

/** /admin/products?x=1 → /office/a/products?x=1; /admin → /office/ (home); a rebuilt page → its new address. Anything else is not ours: home. */
export function appPathFor(adminPath: string): string {
  const m = adminPath.match(/^\/admin(\/[^?#]*)?(\?[^#]*)?/)
  if (!m) return `${APP}/`
  const path = `/admin${m[1] ?? ''}`
  for (const r of REBUILT) {
    const hit = path.match(r.admin)
    if (hit) return r.app(hit, new URLSearchParams(m[2] ?? ''))
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
  | { page: 'drawer' } | { page: 'products' } | { page: 'product'; id: string } | { page: 'product-form'; id: string | null } | { page: 'stock' } | { page: 'movements' }
  | { page: 'adjustments' } | { page: 'adjustment'; id: string } | { page: 'adjustment-form'; id: string | null }
  | { page: 'counts' } | { page: 'count'; id: string }
  | { page: 'customers' } | { page: 'customer'; id: string } | { page: 'customer-form'; id: string | null }
  | { page: 'invoices' } | { page: 'invoice'; id: string }
  | { page: 'suppliers' } | { page: 'supplier'; id: string } | { page: 'supplier-form'; id: string | null }
  | { page: 'purchase-orders' } | { page: 'purchase-order'; id: string } | { page: 'purchase-order-form'; id: string | null }
  | { page: 'requests' } | { page: 'request'; id: string } | { page: 'request-form' }
  | { page: 'transfers' } | { page: 'transfer'; id: string } | { page: 'transfer-form'; id: string | null } | { page: 'classic'; admin: string }

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
  if (/^\/office\/products\/?$/.test(path)) return { page: 'products' }
  if (/^\/office\/stock\/?$/.test(path)) return { page: 'stock' }
  if (/^\/office\/stock\/movements\/?$/.test(path)) return { page: 'movements' }
  if (/^\/office\/transfers\/?$/.test(path)) return { page: 'transfers' }
  if (/^\/office\/transfers\/new\/?$/.test(path)) return { page: 'transfer-form', id: null }
  const tr = path.match(new RegExp(`^/office/transfers/(${UUID})(/edit)?/?$`))
  if (tr) return tr[2] ? { page: 'transfer-form', id: tr[1] } : { page: 'transfer', id: tr[1] }
  if (/^\/office\/customers\/?$/.test(path)) return { page: 'customers' }
  if (/^\/office\/customers\/new\/?$/.test(path)) return { page: 'customer-form', id: null }
  const cust = path.match(new RegExp(`^/office/customers/(${UUID})(/edit)?/?$`))
  if (cust) return cust[2] ? { page: 'customer-form', id: cust[1] } : { page: 'customer', id: cust[1] }
  if (/^\/office\/invoices\/?$/.test(path)) return { page: 'invoices' }
  const inv = path.match(new RegExp(`^/office/invoices/(${UUID})/?$`))
  if (inv) return { page: 'invoice', id: inv[1] }
  if (/^\/office\/suppliers\/?$/.test(path)) return { page: 'suppliers' }
  if (/^\/office\/suppliers\/new\/?$/.test(path)) return { page: 'supplier-form', id: null }
  const sup = path.match(new RegExp(`^/office/suppliers/(${UUID})(/edit)?/?$`))
  if (sup) return sup[2] ? { page: 'supplier-form', id: sup[1] } : { page: 'supplier', id: sup[1] }
  if (/^\/office\/purchase-orders\/?$/.test(path)) return { page: 'purchase-orders' }
  if (/^\/office\/purchase-orders\/new\/?$/.test(path)) return { page: 'purchase-order-form', id: null }
  const po = path.match(new RegExp(`^/office/purchase-orders/(${UUID})(/edit)?/?$`))
  if (po) return po[2] ? { page: 'purchase-order-form', id: po[1] } : { page: 'purchase-order', id: po[1] }
  if (/^\/office\/requests\/?$/.test(path)) return { page: 'requests' }
  if (/^\/office\/requests\/new\/?$/.test(path)) return { page: 'request-form' }
  const req = path.match(new RegExp(`^/office/requests/(${UUID})/?$`))
  if (req) return { page: 'request', id: req[1] }
  if (/^\/office\/counts\/?$/.test(path)) return { page: 'counts' }
  const count = path.match(new RegExp(`^/office/counts/(${UUID})/?$`))
  if (count) return { page: 'count', id: count[1] }
  if (/^\/office\/adjustments\/?$/.test(path)) return { page: 'adjustments' }
  if (/^\/office\/adjustments\/new\/?$/.test(path)) return { page: 'adjustment-form', id: null }
  const adj = path.match(new RegExp(`^/office/adjustments/(${UUID})(/edit)?/?$`))
  if (adj) return adj[2] ? { page: 'adjustment-form', id: adj[1] } : { page: 'adjustment', id: adj[1] }
  if (/^\/office\/products\/new\/?$/.test(path)) return { page: 'product-form', id: null }
  const edit = path.match(new RegExp(`^/office/products/(${UUID})/edit/?$`))
  if (edit) return { page: 'product-form', id: edit[1] }
  const product = path.match(new RegExp(`^/office/products/(${UUID})/?$`))
  if (product) return { page: 'product', id: product[1] }
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
    case 'products': case 'product': case 'product-form': return '/admin/products'
    case 'stock': return '/admin/stock-levels'
    case 'movements': return '/admin/stock-movements'
    case 'adjustments': case 'adjustment': case 'adjustment-form': return '/admin/stock-adjustments'
    case 'counts': case 'count': return '/admin/stock-counts'
    case 'transfers': case 'transfer': case 'transfer-form': return '/admin/stock-transfers'
    case 'requests': case 'request': case 'request-form': return '/admin/stock-requests'
    case 'customers': case 'customer': case 'customer-form': return '/admin/customers'
    case 'invoices': case 'invoice': return '/admin/invoices'
    case 'suppliers': case 'supplier': case 'supplier-form': return '/admin/suppliers'
    case 'purchase-orders': case 'purchase-order': case 'purchase-order-form': return '/admin/purchase-orders'
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
