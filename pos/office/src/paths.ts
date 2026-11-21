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
  { admin: /^\/admin\/customer-orders\/?$/, app: (_, q) => `${APP}/customer-orders${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/customer-orders\/create\/?$/, app: () => `${APP}/customer-orders/new` },
  { admin: new RegExp(`^/admin/customer-orders/(${UUID})$`), app: (m) => `${APP}/customer-orders/${m[1]}` },
  { admin: new RegExp(`^/admin/customer-orders/(${UUID})/edit$`), app: (m) => `${APP}/customer-orders/${m[1]}/edit` },
  { admin: /^\/admin\/customer-payments\/?$/, app: (_, q) => `${APP}/customer-payments${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/aged-receivables\/?$/, app: () => `${APP}/aged-receivables` },
  { admin: /^\/admin\/till-requests\/?$/, app: (_, q) => `${APP}/till-requests${q.get('id') ? `?id=${encodeURIComponent(q.get('id')!)}` : ''}` },
  { admin: /^\/admin\/office-returns\/?$/, app: () => `${APP}/office-returns` },
  { admin: new RegExp(`^/admin/office-returns/(${UUID})$`), app: (m) => `${APP}/office-returns/${m[1]}` },
  { admin: /^\/admin\/review-items\/?$/, app: () => `${APP}/review-queue` },
  { admin: /^\/admin\/stored-values\/?$/, app: () => `${APP}/gift-cards` },
  { admin: /^\/admin\/supplier-returns\/?$/, app: (_, q) => `${APP}/supplier-returns${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/supplier-returns/(${UUID})$`), app: (m) => `${APP}/supplier-returns/${m[1]}` },
  { admin: /^\/admin\/waybills\/?$/, app: (_, q) => `${APP}/waybills${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/waybills\/create\/?$/, app: (_, q) => `${APP}/waybills/new${q.get('kind') ? `?kind=${encodeURIComponent(q.get('kind')!)}&source=${encodeURIComponent(q.get('source') ?? '')}` : ''}` },
  { admin: new RegExp(`^/admin/waybills/(${UUID})$`), app: (m) => `${APP}/waybills/${m[1]}` },
  { admin: /^\/admin\/bins\/?$/, app: () => `${APP}/bins` },
  { admin: /^\/admin\/stock\/my-day\/?$/, app: () => `${APP}/my-day` },
  { admin: /^\/admin\/purchase-requests\/?$/, app: (_, q) => `${APP}/purchase-requests${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/purchase-requests\/create\/?$/, app: () => `${APP}/purchase-requests/new` },
  { admin: new RegExp(`^/admin/purchase-requests/(${UUID})$`), app: (m) => `${APP}/purchase-requests/${m[1]}` },
  { admin: /^\/admin\/rfqs\/?$/, app: (_, q) => `${APP}/rfqs${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/rfqs\/create\/?$/, app: () => `${APP}/rfqs/new` },
  { admin: new RegExp(`^/admin/rfqs/(${UUID})$`), app: (m) => `${APP}/rfqs/${m[1]}` },
  { admin: /^\/admin\/purchase-agreements\/?$/, app: (_, q) => `${APP}/agreements${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/purchase-agreements\/create\/?$/, app: () => `${APP}/agreements/new` },
  { admin: new RegExp(`^/admin/purchase-agreements/(${UUID})$`), app: (m) => `${APP}/agreements/${m[1]}` },
  { admin: new RegExp(`^/admin/purchase-agreements/(${UUID})/edit$`), app: (m) => `${APP}/agreements/${m[1]}/edit` },
  { admin: /^\/admin\/landed-costs\/?$/, app: (_, q) => `${APP}/landed-costs${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/stock-cost-runs\/?$/, app: (_, q) => `${APP}/cost-runs${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/stock-cost-runs\/create\/?$/, app: () => `${APP}/cost-runs/new` },
  { admin: new RegExp(`^/admin/stock-cost-runs/(${UUID})$`), app: (m) => `${APP}/cost-runs/${m[1]}` },
  { admin: /^\/admin\/estimated-costs\/?$/, app: () => `${APP}/estimated-costs` },
  { admin: /^\/admin\/spend-analytics\/?$/, app: () => `${APP}/spend-analytics` },
  { admin: /^\/admin\/purchase-orders\/?$/, app: (_, q) => `${APP}/purchase-orders${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/purchase-orders\/create\/?$/, app: () => `${APP}/purchase-orders/new` },
  { admin: new RegExp(`^/admin/purchase-orders/(${UUID})$`), app: (m) => `${APP}/purchase-orders/${m[1]}` },
  { admin: new RegExp(`^/admin/purchase-orders/(${UUID})/edit$`), app: (m) => `${APP}/purchase-orders/${m[1]}/edit` },
  { admin: /^\/admin\/goods-receipts\/?$/, app: (_, q) => `${APP}/goods-received${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/goods-receipts/(${UUID})$`), app: (m) => `${APP}/goods-received/${m[1]}` },
  { admin: /^\/admin\/direct-purchases\/?$/, app: (_, q) => `${APP}/direct-purchases${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/direct-purchases\/create\/?$/, app: () => `${APP}/direct-purchases/new` },
  { admin: new RegExp(`^/admin/direct-purchases/(${UUID})$`), app: (m) => `${APP}/direct-purchases/${m[1]}` },
  { admin: new RegExp(`^/admin/direct-purchases/(${UUID})/edit$`), app: (m) => `${APP}/direct-purchases/${m[1]}/edit` },
  { admin: /^\/admin\/supplier-invoices\/?$/, app: () => `${APP}/supplier-invoices` },
  { admin: /^\/admin\/consignment-to-pay\/?$/, app: () => `${APP}/consignment-to-pay` },
  { admin: /^\/admin\/reorder-rules\/?$/, app: (_, q) => `${APP}/reorder-levels${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/reorder-suggestions\/?$/, app: () => `${APP}/what-to-order` },
  { admin: /^\/admin\/suggested-levels\/?$/, app: () => `${APP}/suggested-levels` },
  { admin: /^\/admin\/expense-claims\/?$/, app: (_, q) => `${APP}/expense-claims${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/expense-claims\/create\/?$/, app: () => `${APP}/expense-claims/new` },
  { admin: new RegExp(`^/admin/expense-claims/(${UUID})$`), app: (m) => `${APP}/expense-claims/${m[1]}` },
  { admin: /^\/admin\/supplier-bills\/?$/, app: (_, q) => `${APP}/supplier-bills${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/supplier-bills\/create\/?$/, app: (_, q) => `${APP}/supplier-bills/new${q.get('scan') ? `?scan=${encodeURIComponent(q.get('scan')!)}` : q.get('invoice') ? `?invoice=${encodeURIComponent(q.get('invoice')!)}` : ''}` },
  { admin: new RegExp(`^/admin/supplier-bills/(${UUID})$`), app: (m) => `${APP}/supplier-bills/${m[1]}` },
  { admin: /^\/admin\/supplier-payments\/?$/, app: (_, q) => `${APP}/supplier-payments${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/aged-payables\/?$/, app: () => `${APP}/aged-payables` },
  { admin: /^\/admin\/withholding-tax-return\/?$/, app: () => `${APP}/withholding-tax` },
  { admin: /^\/admin\/report-builder\/?$/, app: (_, q) => `${APP}/report-builder${q.get('report') ? `?report=${encodeURIComponent(q.get('report')!)}` : ''}` },
  { admin: /^\/admin\/saved-reports\/?$/, app: () => `${APP}/saved-reports` },
  { admin: /^\/admin\/fixed-assets\/?$/, app: (_, q) => `${APP}/fixed-assets${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/fixed-assets\/create\/?$/, app: () => `${APP}/fixed-assets?register=1` },
  { admin: new RegExp(`^/admin/fixed-assets/(${UUID})$`), app: (m) => `${APP}/fixed-assets/${m[1]}` },
  { admin: /^\/admin\/asset-classes\/?$/, app: () => `${APP}/asset-kinds` },
  { admin: /^\/admin\/accounting-export\/?$/, app: () => `${APP}/accounting-export` },
  { admin: /^\/admin\/company-wide-access\/?$/, app: () => `${APP}/company-wide-access` },
  { admin: /^\/admin\/access-review\/?$/, app: () => `${APP}/who-can-do-what` },
  { admin: /^\/admin\/it-activity\/?$/, app: () => `${APP}/it-activity` },
  { admin: /^\/admin\/staff-cards\/?$/, app: () => `${APP}/staff-cards` },
  { admin: /^\/admin\/manage-settings\/?$/, app: () => `${APP}/settings` },
  { admin: /^\/admin\/manage-modules\/?$/, app: () => `${APP}/modules` },
  { admin: /^\/admin\/messaging\/?$/, app: () => `${APP}/messaging` },
  { admin: /^\/admin\/message-templates\/?$/, app: () => `${APP}/message-templates` },
  { admin: /^\/admin\/message-log\/?$/, app: (_, q) => `${APP}/message-log${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/devices\/?$/, app: (_, q) => `${APP}/devices${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/devices\/create\/?$/, app: () => `${APP}/devices?register=1` },
  { admin: /^\/admin\/computers\/?$/, app: () => `${APP}/computers` },
  { admin: /^\/admin\/categories\/?$/, app: (_, q) => `${APP}/categories${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/brands\/?$/, app: (_, q) => `${APP}/brands${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/uoms\/?$/, app: (_, q) => `${APP}/units${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/tax-rates\/?$/, app: (_, q) => `${APP}/tax-rates${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/tax-groups\/?$/, app: (_, q) => `${APP}/tax-groups${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/price-lists\/?$/, app: (_, q) => `${APP}/price-lists${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/nhis-claims\/?$/, app: () => `${APP}/nhis-claims` },
  { admin: new RegExp(`^/admin/nhis-claims/(${UUID})$`), app: (m) => `${APP}/nhis-claims/${m[1]}` },
  { admin: /^\/admin\/medicine-signoffs\/?$/, app: () => `${APP}/medicine-signoffs` },
  { admin: /^\/admin\/prescriptions\/?$/, app: () => `${APP}/prescriptions` },
  { admin: new RegExp(`^/admin/prescriptions/(${UUID})$`), app: (m) => `${APP}/prescriptions?open=${m[1]}` },
  { admin: /^\/admin\/refill-reminders\/?$/, app: () => `${APP}/refill-reminders` },
  { admin: /^\/admin\/drug-interactions\/?$/, app: () => `${APP}/drug-interactions` },
  { admin: /^\/admin\/controlled-drug-register\/?$/, app: () => `${APP}/controlled-drug-register` },
  { admin: /^\/admin\/price-list\/?$/, app: (_, q) => `${APP}/price-list${q.get('only') === '1' ? '?only=1' : ''}` },
  { admin: /^\/admin\/shared-products\/?$/, app: () => `${APP}/shared-products` },
  { admin: /^\/admin\/promotions\/?$/, app: () => `${APP}/promotions` },
  { admin: /^\/admin\/promotions\/create\/?$/, app: () => `${APP}/promotions?new=1` },
  { admin: new RegExp(`^/admin/promotions/(${UUID})/edit$`), app: (m) => `${APP}/promotions?edit=${m[1]}` },
  { admin: /^\/admin\/pricing-rules\/?$/, app: () => `${APP}/pricing-rules` },
  { admin: /^\/admin\/prices-to-review\/?$/, app: () => `${APP}/prices-to-review` },
  { admin: /^\/admin\/roles\/?$/, app: (_, q) => `${APP}/roles${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/roles\/create\/?$/, app: () => `${APP}/roles/new` },
  { admin: new RegExp(`^/admin/roles/(${UUID})(/edit)?$`), app: (m) => `${APP}/roles/${m[1]}` },
  { admin: /^\/admin\/access-requests\/?$/, app: () => `${APP}/access-requests` },
  { admin: /^\/admin\/users\/?$/, app: (_, q) => `${APP}/users${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/users\/create\/?$/, app: () => `${APP}/users?add=1` },
  { admin: new RegExp(`^/admin/users/(${UUID})(/edit)?$`), app: (m) => `${APP}/users/${m[1]}` },
  { admin: /^\/admin\/gra-evat\/?$/, app: (_, q) => `${APP}/gra-evat${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/bank-accounts\/?$/, app: () => `${APP}/bank-accounts` },
  { admin: /^\/admin\/bank-reconciliation\/?$/, app: (_, q) => (q.get('bank') ? `${APP}/bank-accounts/${encodeURIComponent(q.get('bank')!)}/reconcile` : `${APP}/bank-accounts`) },
  { admin: /^\/admin\/money-transfers\/?$/, app: () => `${APP}/money-moved` },
  { admin: /^\/admin\/accounts\/?$/, app: (_, q) => `${APP}/accounts${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/fiscal-periods\/?$/, app: () => `${APP}/month-end` },
  { admin: /^\/admin\/budgets\/?$/, app: (_, q) => `${APP}/budgets${q.toString() ? `?${q}` : ''}` },
  { admin: /^\/admin\/journal-entries\/?$/, app: (_, q) => `${APP}/journals${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: new RegExp(`^/admin/journal-entries/(${UUID})$`), app: (m) => `${APP}/journals/${m[1]}` },
  { admin: /^\/admin\/returns\/?$/, app: (_, q) => `${APP}/returns${q.get('tableSearch') ? `?q=${encodeURIComponent(q.get('tableSearch')!)}` : ''}` },
  { admin: /^\/admin\/(grocery|pharmacy)\/markdowns\/?$/, app: (m, q) => `${APP}/${m[1]}/markdowns${q.get('warehouse') ? `?warehouse=${encodeURIComponent(q.get('warehouse')!)}` : ''}` },
  { admin: /^\/admin\/expiry-returns\/?$/, app: () => `${APP}/expiry` },
  { admin: /^\/admin\/cash\/close-day\/?$/, app: (_, q) => `${APP}/close-day${q.toString() ? `?${q.toString()}` : ''}` },
  { admin: /^\/admin\/cash-desk\/?$/, app: () => `${APP}/cash-desk` },
  { admin: /^\/admin\/income-control\/?$/, app: () => `${APP}/income-control` },
  { admin: /^\/admin\/cash-setup\/?$/, app: () => `${APP}/cash-setup` },
  { admin: /^\/admin\/batches-expiry\/?$/, app: (_, q) => `${APP}/batches${q.get('warehouse') ? `?warehouse=${encodeURIComponent(q.get('warehouse')!)}` : ''}` },
  { admin: /^\/admin\/shrink-report\/?$/, app: (_, q) => `${APP}/shrink${q.toString() ? `?${q}` : ''}` },
  { admin: /^\/admin\/stock\/slow-stock\/?$/, app: (_, q) => `${APP}/slow-stock${q.toString() ? `?${q}` : ''}` },
  { admin: /^\/admin\/insights\/?$/, app: (_, q) => `${APP}/insights${q.toString() ? `?${q}` : ''}` }, // the tab and warehouse carry over
  { admin: /^\/admin\/financial-reports\/?$/, app: (_, q) => `${APP}/financial-reports${q.toString() ? `?${q}` : ''}` }, // the report and dates carry over
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
  | { page: 'customer-orders' } | { page: 'customer-order'; id: string } | { page: 'customer-order-form'; id: string | null } | { page: 'customer-payments' } | { page: 'aged-receivables' }
  | { page: 'till-requests' } | { page: 'office-returns' } | { page: 'office-return'; id: string } | { page: 'review-queue' } | { page: 'gift-cards' }
  | { page: 'supplier-returns' } | { page: 'supplier-return'; id: string } | { page: 'waybills' } | { page: 'waybill'; id: string } | { page: 'waybill-form' } | { page: 'bins' } | { page: 'my-day' }
  | { page: 'purchase-requests' } | { page: 'purchase-request'; id: string } | { page: 'purchase-request-form' }
  | { page: 'rfqs' } | { page: 'rfq'; id: string } | { page: 'rfq-form' }
  | { page: 'agreements' } | { page: 'agreement'; id: string } | { page: 'agreement-form'; id: string | null }
  | { page: 'landed-costs' } | { page: 'cost-runs' } | { page: 'cost-run'; id: string } | { page: 'cost-run-form' } | { page: 'estimated-costs' } | { page: 'spend-analytics' }
  | { page: 'goods-received' } | { page: 'goods-receipt'; id: string }
  | { page: 'direct-purchases' } | { page: 'direct-purchase'; id: string } | { page: 'direct-purchase-form'; id: string | null }
  | { page: 'supplier-invoices' } | { page: 'consignment-to-pay' } | { page: 'reorder-levels' } | { page: 'what-to-order' } | { page: 'suggested-levels' }
  | { page: 'expense-claims' } | { page: 'expense-claim'; id: string } | { page: 'expense-claim-form' }
  | { page: 'supplier-bills' } | { page: 'supplier-bill'; id: string } | { page: 'supplier-bill-form' } | { page: 'supplier-payments' } | { page: 'aged-payables' } | { page: 'withholding-tax' } | { page: 'financial-reports' } | { page: 'insights' } | { page: 'report-builder' } | { page: 'saved-reports' } | { page: 'shrink' } | { page: 'slow-stock' } | { page: 'batches' } | { page: 'expiry' } | { page: 'close-day' } | { page: 'cash-desk' } | { page: 'income-control' } | { page: 'cash-setup' } | { page: 'markdowns'; industry: 'grocery' | 'pharmacy' } | { page: 'returns' } | { page: 'journals' } | { page: 'accounts' } | { page: 'month-end' } | { page: 'budgets' } | { page: 'bank-accounts' } | { page: 'reconcile'; id: string } | { page: 'money-moved' } | { page: 'fixed-assets' } | { page: 'fixed-asset'; id: string } | { page: 'asset-kinds' } | { page: 'accounting-export' } | { page: 'gra-evat' } | { page: 'gra-evat-report'; id: string } | { page: 'users' } | { page: 'user'; id: string } | { page: 'roles' } | { page: 'role'; id: string | null } | { page: 'access-requests' } | { page: 'settings' } | { page: 'modules' } | { page: 'messaging' } | { page: 'message-templates' } | { page: 'message-log' } | { page: 'devices' } | { page: 'computers' } | { page: 'pricing-rules' } | { page: 'promotions' } | { page: 'price-list' } | { page: 'prescriptions' } | { page: 'nhis-claims' } | { page: 'nhis-claim'; id: string } | { page: 'medicine-signoffs' } | { page: 'refill-reminders' } | { page: 'drug-interactions' } | { page: 'controlled-drug-register' } | { page: 'shared-products' } | { page: 'prices-to-review' } | { page: 'reference-list'; kind: 'categories' | 'brands' | 'units' | 'tax-rates' | 'tax-groups' | 'price-lists' } | { page: 'company-wide-access' } | { page: 'who-can-do-what' } | { page: 'it-activity' } | { page: 'staff-cards' } | { page: 'journal'; id: string }
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
  if (/^\/office\/customer-orders\/?$/.test(path)) return { page: 'customer-orders' }
  if (/^\/office\/customer-orders\/new\/?$/.test(path)) return { page: 'customer-order-form', id: null }
  const co = path.match(new RegExp(`^/office/customer-orders/(${UUID})(/edit)?/?$`))
  if (co) return co[2] ? { page: 'customer-order-form', id: co[1] } : { page: 'customer-order', id: co[1] }
  if (/^\/office\/customer-payments\/?$/.test(path)) return { page: 'customer-payments' }
  if (/^\/office\/aged-receivables\/?$/.test(path)) return { page: 'aged-receivables' }
  if (/^\/office\/till-requests\/?$/.test(path)) return { page: 'till-requests' }
  if (/^\/office\/office-returns\/?$/.test(path)) return { page: 'office-returns' }
  const oret = path.match(new RegExp(`^/office/office-returns/(${UUID})/?$`))
  if (oret) return { page: 'office-return', id: oret[1] }
  if (/^\/office\/review-queue\/?$/.test(path)) return { page: 'review-queue' }
  if (/^\/office\/gift-cards\/?$/.test(path)) return { page: 'gift-cards' }
  if (/^\/office\/supplier-returns\/?$/.test(path)) return { page: 'supplier-returns' }
  const sret = path.match(new RegExp(`^/office/supplier-returns/(${UUID})/?$`))
  if (sret) return { page: 'supplier-return', id: sret[1] }
  if (/^\/office\/waybills\/?$/.test(path)) return { page: 'waybills' }
  if (/^\/office\/waybills\/new\/?$/.test(path)) return { page: 'waybill-form' }
  const wb = path.match(new RegExp(`^/office/waybills/(${UUID})/?$`))
  if (wb) return { page: 'waybill', id: wb[1] }
  if (/^\/office\/bins\/?$/.test(path)) return { page: 'bins' }
  if (/^\/office\/my-day\/?$/.test(path)) return { page: 'my-day' }
  if (/^\/office\/purchase-requests\/?$/.test(path)) return { page: 'purchase-requests' }
  if (/^\/office\/purchase-requests\/new\/?$/.test(path)) return { page: 'purchase-request-form' }
  const preq = path.match(new RegExp(`^/office/purchase-requests/(${UUID})/?$`))
  if (preq) return { page: 'purchase-request', id: preq[1] }
  if (/^\/office\/rfqs\/?$/.test(path)) return { page: 'rfqs' }
  if (/^\/office\/rfqs\/new\/?$/.test(path)) return { page: 'rfq-form' }
  const rfq = path.match(new RegExp(`^/office/rfqs/(${UUID})/?$`))
  if (rfq) return { page: 'rfq', id: rfq[1] }
  if (/^\/office\/agreements\/?$/.test(path)) return { page: 'agreements' }
  if (/^\/office\/agreements\/new\/?$/.test(path)) return { page: 'agreement-form', id: null }
  const agr = path.match(new RegExp(`^/office/agreements/(${UUID})(/edit)?/?$`))
  if (agr) return agr[2] ? { page: 'agreement-form', id: agr[1] } : { page: 'agreement', id: agr[1] }
  if (/^\/office\/landed-costs\/?$/.test(path)) return { page: 'landed-costs' }
  if (/^\/office\/cost-runs\/?$/.test(path)) return { page: 'cost-runs' }
  if (/^\/office\/cost-runs\/new\/?$/.test(path)) return { page: 'cost-run-form' }
  const crun = path.match(new RegExp(`^/office/cost-runs/(${UUID})/?$`))
  if (crun) return { page: 'cost-run', id: crun[1] }
  if (/^\/office\/estimated-costs\/?$/.test(path)) return { page: 'estimated-costs' }
  if (/^\/office\/spend-analytics\/?$/.test(path)) return { page: 'spend-analytics' }
  if (/^\/office\/purchase-orders\/?$/.test(path)) return { page: 'purchase-orders' }
  if (/^\/office\/purchase-orders\/new\/?$/.test(path)) return { page: 'purchase-order-form', id: null }
  const po = path.match(new RegExp(`^/office/purchase-orders/(${UUID})(/edit)?/?$`))
  if (po) return po[2] ? { page: 'purchase-order-form', id: po[1] } : { page: 'purchase-order', id: po[1] }
  if (/^\/office\/goods-received\/?$/.test(path)) return { page: 'goods-received' }
  const grn = path.match(new RegExp(`^/office/goods-received/(${UUID})/?$`))
  if (grn) return { page: 'goods-receipt', id: grn[1] }
  if (/^\/office\/direct-purchases\/?$/.test(path)) return { page: 'direct-purchases' }
  if (/^\/office\/direct-purchases\/new\/?$/.test(path)) return { page: 'direct-purchase-form', id: null }
  const dp = path.match(new RegExp(`^/office/direct-purchases/(${UUID})(/edit)?/?$`))
  if (dp) return dp[2] ? { page: 'direct-purchase-form', id: dp[1] } : { page: 'direct-purchase', id: dp[1] }
  if (/^\/office\/supplier-invoices\/?$/.test(path)) return { page: 'supplier-invoices' }
  if (/^\/office\/consignment-to-pay\/?$/.test(path)) return { page: 'consignment-to-pay' }
  if (/^\/office\/reorder-levels\/?$/.test(path)) return { page: 'reorder-levels' }
  if (/^\/office\/what-to-order\/?$/.test(path)) return { page: 'what-to-order' }
  if (/^\/office\/suggested-levels\/?$/.test(path)) return { page: 'suggested-levels' }
  if (/^\/office\/expense-claims\/?$/.test(path)) return { page: 'expense-claims' }
  if (/^\/office\/expense-claims\/new\/?$/.test(path)) return { page: 'expense-claim-form' }
  const ec = path.match(new RegExp(`^/office/expense-claims/(${UUID})/?$`))
  if (ec) return { page: 'expense-claim', id: ec[1] }
  if (/^\/office\/supplier-bills\/?$/.test(path)) return { page: 'supplier-bills' }
  if (/^\/office\/supplier-bills\/new\/?$/.test(path)) return { page: 'supplier-bill-form' }
  const sb = path.match(new RegExp(`^/office/supplier-bills/(${UUID})/?$`))
  if (sb) return { page: 'supplier-bill', id: sb[1] }
  if (/^\/office\/supplier-payments\/?$/.test(path)) return { page: 'supplier-payments' }
  if (/^\/office\/aged-payables\/?$/.test(path)) return { page: 'aged-payables' }
  if (/^\/office\/withholding-tax\/?$/.test(path)) return { page: 'withholding-tax' }
  if (/^\/office\/financial-reports\/?$/.test(path)) return { page: 'financial-reports' }
  if (/^\/office\/insights\/?$/.test(path)) return { page: 'insights' }
  if (/^\/office\/report-builder\/?$/.test(path)) return { page: 'report-builder' }
  if (/^\/office\/saved-reports\/?$/.test(path)) return { page: 'saved-reports' }
  if (/^\/office\/shrink\/?$/.test(path)) return { page: 'shrink' }
  if (/^\/office\/slow-stock\/?$/.test(path)) return { page: 'slow-stock' }
  if (/^\/office\/batches\/?$/.test(path)) return { page: 'batches' }
  if (/^\/office\/expiry\/?$/.test(path)) return { page: 'expiry' }
  if (/^\/office\/close-day\/?$/.test(path)) return { page: 'close-day' }
  if (/^\/office\/cash-desk\/?$/.test(path)) return { page: 'cash-desk' }
  if (/^\/office\/income-control\/?$/.test(path)) return { page: 'income-control' }
  if (/^\/office\/cash-setup\/?$/.test(path)) return { page: 'cash-setup' }
  const markdowns = path.match(/^\/office\/(grocery|pharmacy)\/markdowns\/?$/)
  if (markdowns) return { page: 'markdowns', industry: markdowns[1] as 'grocery' | 'pharmacy' }
  if (/^\/office\/returns\/?$/.test(path)) return { page: 'returns' }
  if (/^\/office\/journals\/?$/.test(path)) return { page: 'journals' }
  if (/^\/office\/accounts\/?$/.test(path)) return { page: 'accounts' }
  if (/^\/office\/month-end\/?$/.test(path)) return { page: 'month-end' }
  if (/^\/office\/budgets\/?$/.test(path)) return { page: 'budgets' }
  if (/^\/office\/bank-accounts\/?$/.test(path)) return { page: 'bank-accounts' }
  const rec = path.match(new RegExp(`^/office/bank-accounts/(${UUID})/reconcile/?$`))
  if (rec) return { page: 'reconcile', id: rec[1] }
  if (/^\/office\/money-moved\/?$/.test(path)) return { page: 'money-moved' }
  if (/^\/office\/fixed-assets\/?$/.test(path)) return { page: 'fixed-assets' }
  const fa = path.match(new RegExp(`^/office/fixed-assets/(${UUID})/?$`))
  if (fa) return { page: 'fixed-asset', id: fa[1] }
  if (/^\/office\/asset-kinds\/?$/.test(path)) return { page: 'asset-kinds' }
  if (/^\/office\/accounting-export\/?$/.test(path)) return { page: 'accounting-export' }
  if (/^\/office\/gra-evat\/?$/.test(path)) return { page: 'gra-evat' }
  if (/^\/office\/users\/?$/.test(path)) return { page: 'users' }
  if (/^\/office\/roles\/?$/.test(path)) return { page: 'roles' }
  if (/^\/office\/roles\/new\/?$/.test(path)) return { page: 'role', id: null }
  const ro = path.match(new RegExp(`^/office/roles/(${UUID})/?$`))
  if (ro) return { page: 'role', id: ro[1] }
  if (/^\/office\/access-requests\/?$/.test(path)) return { page: 'access-requests' }
  if (/^\/office\/settings\/?$/.test(path)) return { page: 'settings' }
  if (/^\/office\/modules\/?$/.test(path)) return { page: 'modules' }
  if (/^\/office\/messaging\/?$/.test(path)) return { page: 'messaging' }
  if (/^\/office\/message-templates\/?$/.test(path)) return { page: 'message-templates' }
  if (/^\/office\/message-log\/?$/.test(path)) return { page: 'message-log' }
  if (/^\/office\/devices\/?$/.test(path)) return { page: 'devices' }
  if (/^\/office\/computers\/?$/.test(path)) return { page: 'computers' }
  if (/^\/office\/pricing-rules\/?$/.test(path)) return { page: 'pricing-rules' }
  if (/^\/office\/promotions\/?$/.test(path)) return { page: 'promotions' }
  if (/^\/office\/price-list\/?$/.test(path)) return { page: 'price-list' }
  if (/^\/office\/prescriptions\/?$/.test(path)) return { page: 'prescriptions' }
  if (/^\/office\/nhis-claims\/?$/.test(path)) return { page: 'nhis-claims' }
  const nhisClaim = path.match(new RegExp(`^/office/nhis-claims/(${UUID})/?$`))
  if (nhisClaim) return { page: 'nhis-claim', id: nhisClaim[1] }
  if (/^\/office\/medicine-signoffs\/?$/.test(path)) return { page: 'medicine-signoffs' }
  if (/^\/office\/refill-reminders\/?$/.test(path)) return { page: 'refill-reminders' }
  if (/^\/office\/drug-interactions\/?$/.test(path)) return { page: 'drug-interactions' }
  if (/^\/office\/controlled-drug-register\/?$/.test(path)) return { page: 'controlled-drug-register' }
  if (/^\/office\/shared-products\/?$/.test(path)) return { page: 'shared-products' }
  if (/^\/office\/prices-to-review\/?$/.test(path)) return { page: 'prices-to-review' }
  const ref = path.match(/^\/office\/(categories|brands|units|tax-rates|tax-groups|price-lists)\/?$/)
  if (ref) return { page: 'reference-list', kind: ref[1] as 'categories' }
  if (/^\/office\/company-wide-access\/?$/.test(path)) return { page: 'company-wide-access' }
  if (/^\/office\/who-can-do-what\/?$/.test(path)) return { page: 'who-can-do-what' }
  if (/^\/office\/it-activity\/?$/.test(path)) return { page: 'it-activity' }
  if (/^\/office\/staff-cards\/?$/.test(path)) return { page: 'staff-cards' }
  const us = path.match(new RegExp(`^/office/users/(${UUID})/?$`))
  if (us) return { page: 'user', id: us[1] }
  const ev = path.match(new RegExp(`^/office/gra-evat/(${UUID})/?$`))
  if (ev) return { page: 'gra-evat-report', id: ev[1] }
  const jr = path.match(new RegExp(`^/office/journals/(${UUID})/?$`))
  if (jr) return { page: 'journal', id: jr[1] }
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
    case 'customer-orders': return '/admin/customer-orders'
    case 'customer-order': return `/admin/customer-orders/${route.id}`
    case 'customer-order-form': return route.id ? `/admin/customer-orders/${route.id}/edit` : '/admin/customer-orders/create'
    case 'customer-payments': return '/admin/customer-payments'
    case 'aged-receivables': return '/admin/aged-receivables'
    case 'till-requests': return '/admin/till-requests'
    case 'office-returns': return '/admin/office-returns'
    case 'office-return': return `/admin/office-returns/${route.id}`
    case 'review-queue': return '/admin/review-items'
    case 'gift-cards': return '/admin/stored-values'
    case 'supplier-returns': return '/admin/supplier-returns'
    case 'supplier-return': return `/admin/supplier-returns/${route.id}`
    case 'waybills': return '/admin/waybills'
    case 'waybill': return `/admin/waybills/${route.id}`
    case 'waybill-form': return '/admin/waybills/create'
    case 'bins': return '/admin/bins'
    case 'my-day': return '/admin/stock/my-day'
    case 'purchase-requests': return '/admin/purchase-requests'
    case 'purchase-request': return `/admin/purchase-requests/${route.id}`
    case 'purchase-request-form': return '/admin/purchase-requests/create'
    case 'rfqs': return '/admin/rfqs'
    case 'rfq': return `/admin/rfqs/${route.id}`
    case 'rfq-form': return '/admin/rfqs/create'
    case 'agreements': return '/admin/purchase-agreements'
    case 'agreement': return `/admin/purchase-agreements/${route.id}`
    case 'agreement-form': return route.id ? `/admin/purchase-agreements/${route.id}/edit` : '/admin/purchase-agreements/create'
    case 'landed-costs': return '/admin/landed-costs'
    case 'cost-runs': return '/admin/stock-cost-runs'
    case 'cost-run': return `/admin/stock-cost-runs/${route.id}`
    case 'cost-run-form': return '/admin/stock-cost-runs/create'
    case 'estimated-costs': return '/admin/estimated-costs'
    case 'spend-analytics': return '/admin/spend-analytics'
    case 'goods-received': case 'goods-receipt': return '/admin/goods-receipts'
    case 'direct-purchases': case 'direct-purchase': case 'direct-purchase-form': return '/admin/direct-purchases'
    case 'supplier-invoices': return '/admin/supplier-invoices'
    case 'consignment-to-pay': return '/admin/consignment-to-pay'
    case 'reorder-levels': return '/admin/reorder-rules'
    case 'what-to-order': return '/admin/reorder-suggestions'
    case 'suggested-levels': return '/admin/suggested-levels'
    case 'expense-claims': case 'expense-claim': case 'expense-claim-form': return '/admin/expense-claims'
    case 'supplier-bills': case 'supplier-bill': case 'supplier-bill-form': return '/admin/supplier-bills'
    case 'supplier-payments': return '/admin/supplier-payments'
    case 'aged-payables': return '/admin/aged-payables'
    case 'withholding-tax': return '/admin/withholding-tax-return'
    case 'financial-reports': return '/admin/financial-reports'
    case 'insights': return '/admin/insights'
    case 'report-builder': return '/admin/report-builder'
    case 'saved-reports': return '/admin/saved-reports'
    case 'shrink': return '/admin/shrink-report'
    case 'slow-stock': return '/admin/stock/slow-stock'
    case 'batches': return '/admin/batches-expiry'
    case 'expiry': return '/admin/expiry-returns'
    case 'close-day': return '/admin/cash/close-day'
    case 'cash-desk': return '/admin/cash-desk'
    case 'income-control': return '/admin/income-control'
    case 'cash-setup': return '/admin/cash-setup'
    case 'markdowns': return `/admin/${route.industry}/markdowns`
    case 'returns': return '/admin/returns'
    case 'journals': case 'journal': return '/admin/journal-entries'
    case 'accounts': return '/admin/accounts'
    case 'month-end': return '/admin/fiscal-periods'
    case 'budgets': return '/admin/budgets'
    case 'bank-accounts': case 'reconcile': return '/admin/bank-accounts'
    case 'money-moved': return '/admin/money-transfers'
    case 'fixed-assets': case 'fixed-asset': return '/admin/fixed-assets'
    case 'asset-kinds': return '/admin/asset-classes'
    case 'accounting-export': return '/admin/accounting-export'
    case 'gra-evat': case 'gra-evat-report': return '/admin/gra-evat'
    case 'users': case 'user': return '/admin/users'
    case 'roles': case 'role': return '/admin/roles'
    case 'access-requests': return '/admin/access-requests'
    case 'settings': return '/admin/manage-settings'
    case 'modules': return '/admin/manage-modules'
    case 'messaging': return '/admin/messaging'
    case 'message-templates': return '/admin/message-templates'
    case 'message-log': return '/admin/message-log'
    case 'devices': return '/admin/devices'
    case 'computers': return '/admin/computers'
    case 'pricing-rules': return '/admin/pricing-rules'
    case 'promotions': return '/admin/promotions'
    case 'price-list': return '/admin/price-list'
    case 'prescriptions': return '/admin/prescriptions'
    case 'nhis-claims': return '/admin/nhis-claims'
    case 'nhis-claim': return `/admin/nhis-claims/${route.id}`
    case 'medicine-signoffs': return '/admin/medicine-signoffs'
    case 'refill-reminders': return '/admin/refill-reminders'
    case 'drug-interactions': return '/admin/drug-interactions'
    case 'controlled-drug-register': return '/admin/controlled-drug-register'
    case 'shared-products': return '/admin/shared-products'
    case 'prices-to-review': return '/admin/prices-to-review'
    case 'reference-list': return `/admin/${route.kind === 'units' ? 'uoms' : route.kind}`
    case 'company-wide-access': return '/admin/company-wide-access'
    case 'who-can-do-what': return '/admin/access-review'
    case 'it-activity': return '/admin/it-activity'
    case 'staff-cards': return '/admin/staff-cards'
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
