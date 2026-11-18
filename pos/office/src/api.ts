/**
 * The new back office talks to the server it is served from, with the person's back-office sign-in (the session cookie).
 * Signed out: back to the sign-in page, which returns here afterwards.
 */

export type MenuItem = { label: string; url: string; icon: string | null; badge: string | null }
export type MenuGroup = { label: string | null; items: MenuItem[] }
export type MenuSection = { label: string; groups: MenuGroup[] }
export type MenuTab = { label: string; icon: string; sections: MenuSection[] }
export type Menu = { home: string; tabs: MenuTab[]; create: { label: string; items: { label: string; url: string }[] }[] }
export type Prefs = { home: string; rows: number; theme: 'system' | 'light' | 'dark'; date: string; density: string; office_look: 'top' | 'side' }
export type Me = {
  user: { id: string; name: string; role: string; photo: string | null; initials: string }
  company: { name: string; color: string; on_color: string; menu_color: string | null; logo: string | null; logo_dark: string | null; icon: string | null }
  prefs: Prefs
  favourites: { url: string; label: string }[]
  classic: string
}
export type SearchResult = { group: string; title: string; url: string; details: string }
export type TodoItem = {
  id: string; title: string; detail: string; who: string | null; where: string | null; amount: number | null; percent: number | null
  at: string; expires_at: string | null; lines: string[]; url: string
}
export type TodoGroup = { key: string; label: string; hint: string; count: number; url: string | null; inline: boolean; items: TodoItem[] }
export type Todo = { currency: string; exponent: number; groups: TodoGroup[]; total: number }
export type SaleRow = { id: string; receipt_no: string; occurred_at: string; kind: string; branch: string | null; till: string | null; cashier: string | null; total: number; paid_by: string[] }
export type SalesList = {
  currency: string; exponent: number; total: number; sum: number; mix: { method: string; amount: number }[]
  page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: SaleRow[]
}
export type Option = { id: string; label: string }
export type SalesOptions = { branches: Option[]; tellers: Option[]; methods: Option[]; per: number[] }
export type SaleDetail = {
  currency: string; exponent: number; id: string; receipt_no: string; occurred_at: string; kind: string; return_reason: string | null
  cashier: string | null; where: string; net: number; tax: number; total: number; change: number; discount: number; discount_note: string | null; points: number
  customer: { id: string | null; walk_in_name: string | null; walk_in_phone: string | null; details: { label: string; value: string }[]; comment: string | null; comment_on_receipt: boolean }
  lines: { name: string; qty: string; unit_price: number; tax: number; total: number; dosage: string | null }[]
  tax_breakdown: { name: string; amount: number }[]
  payments: { method: string; amount: number; reference: string | null }[]
  actions: { print: string; preview: string; save_customer: boolean }
}
export type ShiftRow = {
  id: string; where: string; status: 'open' | 'closed'; shift_name: string | null; opened_by: string | null; sellers: string | null
  opened_at: string | null; closed_at: string | null; left_open: string | null; vault: string | null; vault_waiting: boolean
  sales: number; takings: number; float: number; expected: number | null; counted: number | null; corrected: boolean; over_short: number | null
  drawer: string | null; checks: string; note: string | null
}
export type ShiftsList = { currency: string; exponent: number; total: number; open: number; over_short: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: ShiftRow[] }
export type ReportSheet = { title: string; headings: string[]; rows: string[][]; numeric?: number[]; foot?: string[] }
export type ShiftDetail = ShiftRow & {
  currency: string; exponent: number; branch: string
  tiles: { label: string; value: string; tone?: 'good' | 'warn' | 'bad' | null }[]
  facts: Record<string, string>
  sheets: ReportSheet[]
  expected_now: number | null
  denominations: { key: string; label: string; value: number; kind: 'note' | 'coin' }[]
  vault_recounts: number
  actions: { report: boolean; close: boolean; correct: boolean; count_vault: boolean }
}
export type ShiftsOptions = { branches: Option[]; tellers: Option[]; people: Option[]; kinds: Option[]; per: number[] }
export type DrawerRow = {
  id: string; occurred_at: string | null; where: string; kind: string; kind_label: string; amount: number | null; spent_on: string | null
  reason: string | null; payee: string | null; by: string | null; approved_by: string | null; receipt: string | null; offline: boolean; shift_id: string
}
export type DrawerList = { currency: string; exponent: number; total: number; page: number; per: number; dir: 'asc' | 'desc'
  kinds: { id: string; label: string; count: number; amount: number }[]; rows: DrawerRow[] }
export type ProductRow = {
  id: string; sku: string; name: string; category: string | null; location: string | null; schedule: string | null; industry: string | null
  active: boolean; batches: boolean; price: number | null; on_hand: string | null; unit: string | null; tracked: boolean
}
export type ProductsList = {
  currency: string; exponent: number; price_list: string | null; stock_shown: boolean; total: number; active: number; out: number | null
  page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: ProductRow[]
}
export type ProductsOptions = {
  industries: Option[]; categories: Option[]; schedules: Option[]; per: number[]
  can: { manage: boolean; prices: boolean; labels: boolean; cost_run: boolean; schedule: boolean; move_industry: boolean; stock: boolean }
  links: { create?: string; import?: string; update?: string; import_prices?: string }
  batches_off: number; batches_note: string
  labels: { kinds: Option[]; layouts: (Option & { sheet: boolean })[]; price_lists: Option[] }
  brands: Option[]; tax_groups: Option[]; uoms: Option[]; industry_choices: Option[]; medicine_schedules: Option[]
}
export type ProductDetail = {
  currency: string; exponent: number; id: string; sku: string; name: string; active: boolean; schedule: string | null; description: string | null
  unit: string | null; tracked: boolean; facts: Record<string, string>; medicine: Record<string, string>
  units: { unit: string; factor: string; base: boolean; sold: boolean; bought: boolean; barcodes: string[] }[]
  prices: { list: string | null; unit: string | null; from: string; amount: number; starts: string | null; ends: string | null }[]
  pricing_note: string | null; below_cost: boolean
  stock_shown: boolean; stock: { where: string; on_hand: string; low: boolean }[]; stock_total: string | null
  batches: { batch: string; expires: string | null; on_hand: string; expired: boolean }[]
  movements: { at: string | null; type: string; qty: string; in: boolean; where: string | null; reason: string | null; by: string | null }[]
  actions: { edit: string | null; labels: boolean; change_unit: boolean }
  change_unit_note: string | null
}
export type UnitRow = { uom_id: string; factor: string; is_sellable: boolean; is_purchasable: boolean; barcodes: string[] }
export type PriceRow = { price_list_id: string; uom_id: string; min_qty: string; amount: string; starts_at: string; ends_at: string }
export type ProductValues = {
  name: string; industry_id: string; location: string; category_id: string; brand_id: string; tax_group_id: string; base_uom_id: string; buying_uom_id: string
  track_stock: boolean; is_active: boolean; track_batches: boolean; plu: string; description: string
  drug_schedule: string; generic_name: string; strength: string; dosage_form: string; fda_reg_no: string; active_ingredients: string[]; dosage: string; nhis_price: string; daily_use: string
  track_serials: boolean; warranty_months: string; is_container: boolean; deposit_product_id: string; deposit_per_unit: string; consignor_id: string; consignment_bp: string
  reading_kind: string; service_minutes: string; commission_bp: string; licence_keys: boolean
  pack: { items: { product_ids: string[]; sessions: string; label: string }[]; valid_days: string }
  station: string; modifier_group_ids: string[]; recipe: { product_uom_id: string; qty: string; label: string | null }[]
  base_barcodes: string[]; units: UnitRow[]; prices: PriceRow[]; reason: string
}
export type ProductFormData = {
  id: string | null; sku: string | null; name: string | null; currency: string; values: ProductValues
  sections: { industry: boolean; medicine: boolean; serials: boolean; containers: boolean; consignment: boolean; health: boolean; services: boolean; restaurant: boolean; prices: boolean }
  base_unit_locked: boolean; pricing_note: string | null; below_cost: boolean; can: { quick_add: boolean; describe: boolean }; ai_unavailable: string | null
  choices: Record<'industries' | 'categories' | 'brands' | 'tax_groups' | 'uoms' | 'price_lists' | 'schedules' | 'containers' | 'suppliers' | 'readings' | 'services' | 'stations' | 'modifier_groups', Option[]>
}
export type StockRow = { id: string; product_id: string; sku: string | null; name: string | null; warehouse: string | null; on_hand: string; unit: string | null; updated_at: string | null }
export type StockList = { total: number; stocked: number; empty: number; negative: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: StockRow[] }
export type StockOptions = {
  warehouses: Option[]; categories: Option[]; per: number[]; can_adjust: boolean; adjust_warehouses: Option[]; reasons: Option[]; links: { import?: string }
}
export type StockFind = { id: string; label: string; batches: boolean; units: Option[] }
export type MovementRow = {
  id: string; at: string | null; warehouse: string | null; product_id: string; product: string | null; sku: string | null; type: string
  entered: string; qty: string; unit: string | null; in: boolean; reason: string | null; by: string | null
}
export type MovementsList = { total: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; product: string | null; rows: MovementRow[] }
export type MovementsOptions = { types: Option[]; warehouses: Option[]; categories: Option[]; brands: Option[]; people: Option[]; per: number[] }
/** A product unit for a document line ("SKU · Name — CTN × 24"), and what a warehouse holds of it. */
export type UnitOption = { id: string; product_id: string; label: string; batches: boolean; serialised: boolean }
export type TransferRow = {
  id: string; number: string; from: string | null; to: string | null; status: string; status_label: string; difference: boolean; lines: number
  dispatched_at: string | null; created_at: string | null; by: string | null
}
export type TransferList = { total: number; in_transit: number; to_receive: number; drafts: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: TransferRow[] }
export type TransferOptions = { statuses: Option[]; warehouses: Option[]; per: number[]; can_create: boolean }
export type TransferDetail = {
  id: string; number: string; status: string; status_label: string; facts: Record<string, string>; note: string | null; difference: boolean | null
  lines: { id: string; sku: string; name: string; unit: string; sent: string; received: string | null; serials: string[] }[]
  bins: Option[]
  actions: { edit: boolean; dispatch: boolean; receive: boolean; cancel: boolean; print: string; waybill: string; waybill_label: string }
}
export type TransferLine = { product_uom_id: string; label: string | null; product_id: string; qty: string; serials: string[]; serialised: boolean }
export type TransferForm = {
  id: string | null; number: string | null
  values: { from_warehouse_id: string; to_warehouse_id: string; note: string; lines: TransferLine[] }
  choices: { from: Option[]; to: Option[] }
}
export type RequestRow = { id: string; number: string; for: string | null; asked_of: string | null; cross: boolean; status: string; status_label: string; lines: number; by: string | null; created_at: string | null }
export type RequestList = { total: number; open: number; sent: number; waiting: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: RequestRow[] }
export type RequestOptions = { statuses: Option[]; warehouses: Option[]; per: number[]; can_create: boolean }
export type RequestDetail = {
  id: string; number: string; status: string; status_label: string; waiting_for: 'requester' | 'supplier' | 'supply' | null; cross: boolean
  facts: Record<string, string>; note: string | null; transfer: { id: string; number: string; status_label: string } | null
  lines: { id: string; sku: string; name: string; unit: string; qty: string; sent: string | null }[]
  actions: { approve: boolean; approve_text: string; supply: boolean; reject: boolean; cancel: boolean; print: string }
}
export type RequestLine = { product_uom_id: string; label: string | null; qty: string }
export type RequestForm = {
  values: { to_warehouse_id: string; from_warehouse_id: string; note: string; lines: RequestLine[] }
  choices: { to: Option[]; from: Option[] }
}
export type Held = { qty: number; unit: string } | null
export type CountRow = {
  id: string; number: string; warehouse: string | null; bin: string | null; status: string; status_label: string; blind: boolean
  started_at: string | null; started_by: string | null; lines: number; difference: number | null
}
export type CountList = { currency: string; exponent: number; total: number; counting: number; waiting: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: CountRow[] }
export type CountOptions = {
  statuses: Option[]; warehouses: Option[]; per: number[]; can_start: boolean; start_warehouses: Option[]; bins: Record<string, Option[]>
}
export type CountDetail = {
  currency: string; exponent: number; id: string; number: string; status: string; status_label: string; blind: boolean
  facts: Record<string, string>; note: string | null; shows_expected: boolean; counted: number; bins: Option[]
  lines: { sku: string | null; product: string | null; unit: string | null; bin: string | null; counted: string | null; by: string | null; expected: string | null; difference: string | null }[]
  actions: { count: boolean; submit: boolean; post: boolean; cancel: boolean; import: string | null }
  posted_lines?: number | null
}
export type UnitStock = { qty: number; unit: string; factor: number; cost: number | null; batch: number | null }
export type AdjRow = {
  id: string; number: string; created_at: string | null; warehouse: string | null; reason: string; disposal: boolean; reference: string | null
  lines: number; value: number | null; status: string; status_label: string; by: string | null
}
export type AdjList = { currency: string; exponent: number; total: number; waiting: number; drafts: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: AdjRow[] }
export type AdjOptions = {
  statuses: Option[]; kinds: Option[]; reasons: Option[]; warehouses: Option[]; per: number[]; can_create: boolean; links: { import?: string; invoice?: string }
}
export type AdjDetail = {
  currency: string; exponent: number; id: string; number: string; status: string; status_label: string; kind: string
  facts: Record<string, string>; note: string | null
  disposal: { method: string | null; disposed_on: string | null; witness: string | null; certificate: string | null } | null
  lines: { headings: string[]; rows: string[][]; numeric: number[] }; value: number; value_note: string; over_limit: string | null
  photos: { line: number; url: string }[]
  actions: { edit: boolean; submit: boolean; post: string | null; reopen: boolean; cancel: boolean; import: string | null; disposal_record: string | null }
}
export type AdjLine = {
  product_uom_id: string; label: string | null; mode: string; qty: string; unit_cost: string; batch_no: string; expires_on: string; reason_code: string; note: string
  serials: string[]; photo_path: string; photo_url: string | null; batches: boolean; serialised: boolean
}
export type AdjForm = {
  id: string | null; number: string | null; currency: string; exponent: number
  values: { warehouse_id: string; reason_code: string; reference: string; note: string; lines: AdjLine[] }
  choices: { warehouses: Option[]; reasons: Option[]; modes: Option[] }; photos: boolean; photos_required: boolean; approval_limit: number
}
/** Which products an action is for: the ticked ones, or every product the list's search and filters pick. */
export type Pick = { ids: string[] } | { match: Record<string, string | string[]> }
export type BulkResult = { done: number; untouched?: number; skipped: string[] }
export type Period = 'today' | 'week' | 'month' | 'quarter' | 'year'
export type Kpi = { key: string; label: string; format: 'money' | 'count'; current: number; previous: number | null; change: number | null; spark: number[]; url: string }
export type HomeData = {
  currency: string; exponent: number
  period: { key: Period; label: string; previous: string; options: Record<Period, string> }
  reminders: { label: string; count: number; url: string; urgent: boolean }[]
  kpis: Kpi[]
  top_items: { id: string; name: string; qty: number; total: number }[]
  trend: { months: string[]; series: { key: string; label: string; values: number[] }[] }
  layout: import('./home/layout').HomeLayout | null
}

export class ApiError extends Error {
  status: number
  code: string
  /** Where to go to fix it, when the server says (e.g. "Confirm it's you"). */
  url: string | null

  constructor(message: string, status: number, code = 'error', url: string | null = null) {
    super(message)
    this.status = status
    this.code = code
    this.url = url
  }
}

function xsrf(): string {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : ''
}

async function call<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(path, {
    ...init,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf(), ...(init.headers ?? {}) },
  })
  if (res.status === 401 || res.status === 419) {
    location.assign(`/office-signin?to=${encodeURIComponent(location.pathname + location.search)}`) // signs in, then back here
    throw new ApiError('Signed out', res.status, 'signed_out')
  }
  const body = await res.json().catch(() => ({}))
  if (res.status === 429) throw new ApiError('Too many changes in a minute. Wait a moment, then try again.', 429, 'too_many')
  if (!res.ok) throw new ApiError(body.detail ?? body.message ?? 'Something went wrong. Try again.', res.status, body.error ?? 'error', typeof body.url === 'string' ? body.url : null)
  return body as T
}

export const api = {
  me: () => call<Me>('/office-api/me'),
  menu: () => call<Menu>('/office-api/menu'),
  search: (q: string, signal?: AbortSignal) => call<{ results: SearchResult[] }>(`/office-api/search?q=${encodeURIComponent(q)}`, { signal }),
  home: (period: Period, top: Period) => call<HomeData>(`/office-api/home?period=${period}&top=${top}`),
  saveHome: (layout: import('./home/layout').HomeLayout | null) =>
    call<{ layout: import('./home/layout').HomeLayout | null }>('/office-api/home-layout', { method: 'POST', body: JSON.stringify({ layout }) }),
  sales: (query: string, signal?: AbortSignal) => call<SalesList>(`/office-api/sales?${query}`, { signal }),
  salesOptions: () => call<SalesOptions>('/office-api/sales/options'),
  sale: (id: string) => call<SaleDetail>(`/office-api/sales/${id}`),
  saveSaleCustomer: (id: string, name: string, phone: string) =>
    call<{ customer: { id: string; name: string } }>(`/office-api/sales/${id}/save-customer`, { method: 'POST', body: JSON.stringify({ name, phone }) }),
  shifts: (query: string, signal?: AbortSignal) => call<ShiftsList>(`/office-api/shifts?${query}`, { signal }),
  shiftsOptions: () => call<ShiftsOptions>('/office-api/shifts/options'),
  shift: (id: string) => call<ShiftDetail>(`/office-api/shifts/${id}`),
  shiftReportUrl: (id: string) => `/office-api/shifts/${id}/report`,
  closeShift: (id: string, counted: string, reason: string) => call<ShiftDetail>(`/office-api/shifts/${id}/close`, { method: 'POST', body: JSON.stringify({ counted, reason }) }),
  correctShift: (id: string, counted: string, reason: string) => call<ShiftDetail>(`/office-api/shifts/${id}/correct`, { method: 'POST', body: JSON.stringify({ counted, reason }) }),
  /** The manager's blind vault count: "recount" = differs from the teller's the first time, count again; "checked" = booked. */
  countVault: (id: string, body: { denoms?: Record<string, number>; amount?: string }) =>
    call<{ status: 'recount' | 'checked'; shift: ShiftDetail }>(`/office-api/shifts/${id}/count-vault`, { method: 'POST', body: JSON.stringify(body) }),
  drawer: (query: string, signal?: AbortSignal) => call<DrawerList>(`/office-api/drawer?${query}`, { signal }),
  products: (query: string, signal?: AbortSignal) => call<ProductsList>(`/office-api/products?${query}`, { signal }),
  productsOptions: () => call<ProductsOptions>('/office-api/products/options'),
  product: (id: string) => call<ProductDetail>(`/office-api/products/${id}`),
  /** Labels for the products, ready to print: the address opens them in a new tab. */
  productLabels: (pick: Pick, form: { kind: string; layout: string; price_list_id: string | null; copies: number; skip: number; give: boolean }) =>
    call<{ url: string; count: number; given: number }>('/office-api/products/labels', { method: 'POST', body: JSON.stringify({ ...pick, ...form }) }),
  /** move (field, to) · schedule · batches · industry · unit (mode, uom_id, per, reason) */
  productsBulk: (pick: Pick, action: string, form: Record<string, string | null> = {}) =>
    call<BulkResult>('/office-api/products/bulk', { method: 'POST', body: JSON.stringify({ ...pick, action, ...form }) }),
  /** Export prices / Work out cost from selling price: their own pages, given the products; returns where to go. */
  productsHandOff: (pick: Pick, to: 'prices' | 'cost_run') =>
    call<{ url: string }>('/office-api/products/hand-off', { method: 'POST', body: JSON.stringify({ ...pick, to }) }),
  /** The product form: a new product (no id) or an existing one. */
  productForm: (id?: string) => call<ProductFormData>(id ? `/office-api/products/${id}/form` : '/office-api/products/form'),
  saveProduct: (id: string | null, values: ProductValues) =>
    call<{ id: string }>(id ? `/office-api/products/${id}` : '/office-api/products', { method: 'POST', body: JSON.stringify({ values }) }),
  /** Add a missing category, brand or unit from the form; one already there under that name (or code) comes back instead. */
  quickAdd: (kind: 'category' | 'brand' | 'uom', form: { name: string; code?: string; decimals?: number }) =>
    call<{ id: string; label: string; existed: boolean }>('/office-api/products/quick-add', { method: 'POST', body: JSON.stringify({ kind, ...form }) }),
  ingredients: (q: string, signal?: AbortSignal) => call<{ results: Option[] }>(`/office-api/products/ingredients?q=${encodeURIComponent(q)}`, { signal }),
  describeProduct: (id: string, tone: string) => call<{ description: string }>(`/office-api/products/${id}/describe`, { method: 'POST', body: JSON.stringify({ tone }) }),
  stock: (query: string, signal?: AbortSignal) => call<StockList>(`/office-api/stock?${query}`, { signal }),
  stockOptions: () => call<StockOptions>('/office-api/stock/options'),
  stockFind: (q: string, signal?: AbortSignal) => call<{ results: StockFind[] }>(`/office-api/stock/find?q=${encodeURIComponent(q)}`, { signal }),
  /** The stock sheet opens as a download (PDF or CSV). */
  stockSheetUrl: (p: { warehouse: string; category: string; show_qty: boolean; format: 'pdf' | 'csv'; zero: boolean }) =>
    `/office-api/stock/sheet?${new URLSearchParams({ warehouse: p.warehouse, category: p.category, show_qty: p.show_qty ? '1' : '0', format: p.format, zero: p.zero ? '1' : '0' })}`,
  adjustStock: (body: Record<string, string | null>) => call<{ id: string; qty: string }>('/office-api/stock/adjust', { method: 'POST', body: JSON.stringify(body) }),
  movements: (query: string, signal?: AbortSignal) => call<MovementsList>(`/office-api/stock/movements?${query}`, { signal }),
  movementsOptions: () => call<MovementsOptions>('/office-api/stock/movements/options'),
  stockUnits: (q: string, signal?: AbortSignal) => call<{ results: UnitOption[] }>(`/office-api/stock/units?q=${encodeURIComponent(q)}`, { signal }),
  unitStock: (unit: string, warehouse: string, batch: string, signal?: AbortSignal) =>
    call<{ stock: UnitStock | null }>(`/office-api/stock/unit-stock?${new URLSearchParams({ unit, warehouse, batch })}`, { signal }),
  /** A photo for a stock line: kept privately; the returned path is saved with the line. */
  stockPhoto: async (file: File) => {
    const body = new FormData()
    body.append('photo', file)
    const res = await fetch('/office-api/stock/photos', { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new ApiError(json.message ?? json.detail ?? 'The photo could not be added.', res.status)
    return json as { path: string }
  },
  adjustments: (query: string, signal?: AbortSignal) => call<AdjList>(`/office-api/adjustments?${query}`, { signal }),
  adjustmentsOptions: () => call<AdjOptions>('/office-api/adjustments/options'),
  adjustment: (id: string) => call<AdjDetail>(`/office-api/adjustments/${id}`),
  adjustmentForm: (id?: string) => call<AdjForm>(id ? `/office-api/adjustments/${id}/form` : '/office-api/adjustments/form'),
  saveAdjustment: (id: string | null, values: AdjForm['values']) =>
    call<{ id: string }>(id ? `/office-api/adjustments/${id}` : '/office-api/adjustments', { method: 'POST', body: JSON.stringify({ values }) }),
  /** submit · post · reopen · cancel (the last two with a reason). */
  adjustmentAction: (id: string, action: string, reason?: string) =>
    call<AdjDetail>(`/office-api/adjustments/${id}/action`, { method: 'POST', body: JSON.stringify({ action, reason: reason ?? null }) }),
  adjustmentVoucherUrl: (id: string, format: 'pdf' | 'csv') => `/office-api/adjustments/${id}/voucher?format=${format}`,
  transfers: (query: string, signal?: AbortSignal) => call<TransferList>(`/office-api/transfers?${query}`, { signal }),
  transfersOptions: () => call<TransferOptions>('/office-api/transfers/options'),
  transfer: (id: string) => call<TransferDetail>(`/office-api/transfers/${id}`),
  transferForm: (id?: string) => call<TransferForm>(id ? `/office-api/transfers/${id}/form` : '/office-api/transfers/form'),
  saveTransfer: (id: string | null, values: TransferForm['values']) =>
    call<{ id: string }>(id ? `/office-api/transfers/${id}` : '/office-api/transfers', { method: 'POST', body: JSON.stringify({ values }) }),
  /** dispatch · receive (what arrived per line, the bin, a note) · cancel (with a reason). */
  transferAction: (id: string, action: string, extra: { reason?: string; received?: Record<string, string>; to_bin_id?: string | null; note?: string | null } = {}) =>
    call<TransferDetail>(`/office-api/transfers/${id}/action`, { method: 'POST', body: JSON.stringify({ action, ...extra }) }),
  requests: (query: string, signal?: AbortSignal) => call<RequestList>(`/office-api/requests?${query}`, { signal }),
  requestsOptions: () => call<RequestOptions>('/office-api/requests/options'),
  request: (id: string) => call<RequestDetail>(`/office-api/requests/${id}`),
  requestForm: () => call<RequestForm>('/office-api/requests/form'),
  saveRequest: (values: RequestForm['values']) => call<{ id: string }>('/office-api/requests', { method: 'POST', body: JSON.stringify({ values }) }),
  /** What the asking warehouse and the asked one hold of a unit. */
  requestStock: (unit: string, forWh: string, fromWh: string, signal?: AbortSignal) =>
    call<{ mine: Held; theirs: Held }>(`/office-api/requests/stock?unit=${unit}&for=${forWh}&from=${fromWh}`, { signal }),
  /** Between industries: the supplier's own products, to send for a line. */
  requestProducts: (id: string, q: string, signal?: AbortSignal) => call<Option[]>(`/office-api/requests/${id}/products?q=${encodeURIComponent(q)}`, { signal }),
  /** approve · supply (per line: what is sent, and the supplier's own product) · reject · cancel (with a reason). */
  requestAction: (id: string, action: string, extra: { reason?: string; lines?: Record<string, { qty: string; send_product_id: string | null }> } = {}) =>
    call<RequestDetail>(`/office-api/requests/${id}/action`, { method: 'POST', body: JSON.stringify({ action, ...extra }) }),
  counts: (query: string, signal?: AbortSignal) => call<CountList>(`/office-api/counts?${query}`, { signal }),
  countsOptions: () => call<CountOptions>('/office-api/counts/options'),
  count: (id: string) => call<CountDetail>(`/office-api/counts/${id}`),
  startCount: (body: { warehouse_id: string; bin_id: string | null; blind: boolean; note: string | null }) =>
    call<{ id: string }>('/office-api/counts', { method: 'POST', body: JSON.stringify(body) }),
  /** Counted quantities; a product counted again (same bin) replaces its earlier count. */
  recordCount: (id: string, items: { product_uom_id: string; qty: string }[], bin: string | null) =>
    call<CountDetail>(`/office-api/counts/${id}/record`, { method: 'POST', body: JSON.stringify({ items, bin_id: bin }) }),
  /** submit · post · cancel (with a reason). */
  countAction: (id: string, action: string, reason?: string) =>
    call<CountDetail>(`/office-api/counts/${id}/action`, { method: 'POST', body: JSON.stringify({ action, reason: reason ?? null }) }),
  countSheetUrl: (id: string, format: 'pdf' | 'csv') => `/office-api/counts/${id}/sheet?format=${format}`,
  batchesAll: () => call<{ done: number; untouched: number; skipped: string[] }>('/office-api/products/batches-all', { method: 'POST', body: '{}' }),
  todo: () => call<Todo>('/office-api/todo'),
  todoCount: () => call<{ count: number }>('/office-api/todo-count'),
  /** Approve or decline a till request; ApiError code "reconfirm" (with its url) = confirm it is you first. */
  decideTill: (id: string, yes: boolean, note?: string) =>
    call<{ status: string; decided_by: string | null }>(`/office-api/till-requests/${id}/decide`, { method: 'POST', body: JSON.stringify({ yes, note: note ?? null }) }),
  /** Add a page to the person's favourites, or take it off (the same list as the classic back office's star). */
  toggleFavourite: (url: string, label: string) =>
    call<{ favourites: { url: string; label: string }[] }>('/admin-ui/favourite', { method: 'POST', body: JSON.stringify({ url, label }) }),
  setLook: (look: Prefs['office_look']) => call<{ prefs: Prefs }>('/office-api/prefs', { method: 'POST', body: JSON.stringify({ office_look: look }) }),
  /** Sign out of the back office (the same sign-in as the current one), then the sign-in page. */
  signOut: async () => {
    await fetch('/admin/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'text/html' } }).catch(() => undefined)
    location.assign('/admin/login')
  },
}
