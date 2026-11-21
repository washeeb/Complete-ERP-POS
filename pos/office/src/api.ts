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
export type CustomerRow = {
  id: string; code: string; name: string; contact: string | null; phone: string | null; type: string; price_list: string | null
  balance: number | null; credit_limit: number | null; hold: boolean; archived: boolean
}
export type CustomerList = {
  currency: string; exponent: number; total: number; owing: number; owed: number; credit: number; hold: number
  page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: CustomerRow[]
}
export type CustomerOptions = { types: Option[]; per: number[]; can_create: boolean; import: string | null }
export type PatientValues = { date_of_birth: string; sex: string; allergies: string[]; conditions: string; nhis_no: string; nhis_expires_on: string }
export type CustomerDetail = {
  currency: string; exponent: number; id: string; code: string; name: string; archived: boolean; hold: boolean; archive_note: string | null
  facts: Record<string, string>; notes: string | null
  account: { balance: number; overdue: number; limit: number | null; available: number | null; terms: string }
  open_items: { number: string; due: string | null; overdue: boolean; amount: number; open: number; url: string | null }[]
  patient: Record<string, string> | null
  actions: {
    edit: boolean
    pay: { branches: Option[]; default_branch: string | null; methods: Option[]; providers: Option[] } | null
    statement: string | null
    credit: { limit: string; terms: string; hold: boolean } | null
    opening: boolean
    patient: PatientValues | null
    follow_up: { types: Option[]; people: Option[] } | null
    new_deal: string | null
    archive: { blockers: string[] } | null
    restore: boolean
  }
}
export type CustomerValues = {
  type: string; name: string; industry_id: string; phone: string; email: string; contact_name: string; tax_no: string; price_list_id: string
  home_branch_id: string; address: string; tags: string[]; notes: string; consents: Record<string, boolean>; consent_source: string
  credit_limit: string; payment_terms_days: string
}
export type CustomerForm = {
  id: string | null; code: string | null; name: string | null; values: CustomerValues
  choices: { types: Option[]; industries: Option[]; price_lists: Option[]; branches: Option[]; consent_sources: Option[]; consent_channels: Option[] }
  sections: { industry: boolean; credit: boolean }
}
export type InvoiceRow = {
  id: string; number: string; customer: string; ref: string | null; issued_on: string; due_on: string; currency: string
  total: number; open: number; status: string; overdue: boolean
}
export type InvoiceList = {
  currency: string; exponent: number; total: number; sum: number; open: number; overdue: number
  page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: InvoiceRow[]
}
export type InvoiceOptions = { statuses: Option[]; per: number[] }
export type InvoiceDetail = {
  currency: string; exponent: number; id: string; number: string; status: string; status_label: string
  customer: { id: string; name: string; tax_no: string | null; open: string | null }
  ref: string | null; branch: string | null; issued_on: string; due_on: string; overdue: boolean; prices_include_tax: boolean
  net: number; tax: number; total: number; open: number
  tax_breakdown: { name: string; amount: number }[]
  lines: { sku: string; name: string; qty: string; unit_price: number; total: number }[]
  settled: { kind: string; number: string | null; on: string; amount: number }[]
  credit_notes: { number: string; on: string | null; reason: string; goods_returned: boolean; by: string; total: number }[]
  actions: { print: string; credit: { warehouses: Option[] } | null }
}
export type SupplierRow = {
  id: string; code: string; name: string; contact: string | null; phone: string | null; status: string; note: string | null; score: string | null
  terms: string; products: number; owed: number | null; archived: boolean; bank_unchecked: boolean
}
export type SupplierList = {
  currency: string; exponent: number; total: number; pending: number; blocked: number; owed: number | null
  page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: SupplierRow[]
}
export type SupplierOptions = { statuses: Option[]; per: number[]; can_create: boolean; sees_owed: boolean; import: string | null }
export type SupplierDoc = {
  id: string; kind: string; required: boolean; number: string | null; expires_on: string | null; expired: boolean; soon: boolean
  by: string; checked_by: string | null; file: boolean; can_verify: boolean; can_remove: boolean
}
export type Scorecard = {
  orders: number; on_time: number | null; fill: number | null; quality_issues: number | null; price_change: number | null; lead_days: number | null
  score: number | null; grade: string | null
}
export type SupplierDetail = {
  currency: string; exponent: number; id: string; code: string; name: string; status: string; status_label: string; blocked_reason: string | null
  gaps: string[]; approval_on: boolean; archived: boolean; archive_note: string | null; facts: Record<string, string>; notes: string | null; owed: number | null
  bank: { details: Record<string, string>; checked: string | null; unchecked: boolean }
  products: { label: string; sku: string | null; cost: number | null; min: string; multiple: string; preferred: boolean }[]
  documents: { required: string[]; rows: SupplierDoc[]; kinds: Option[]; can_add: boolean }
  scorecard: Scorecard
  actions: {
    edit: boolean; approve: boolean; block: boolean; unblock: boolean; verify_bank: { confirm: string } | null
    portal: { channels: Option[]; email: string | null; phone: string | null; has_link: boolean } | null
    opening: boolean; archive: { blockers: string[] } | null; restore: boolean
  }
}
export type SupplierProductLine = {
  product_uom_id: string; label: string | null; supplier_sku: string; last_cost: string; min_order_qty: string; order_multiple: string; is_preferred: boolean
}
export type SupplierValues = {
  name: string; industry_id: string; contact_name: string; phone: string; email: string; tax_no: string; payment_terms_days: string; lead_time_days: string
  return_days_before_expiry: string; address: string; notes: string; supply_kind: string; bank_name: string; bank_branch: string; bank_account_name: string
  bank_account_no: string; momo_number: string; products: SupplierProductLine[]
}
export type SupplierForm = {
  id: string | null; code: string | null; name: string | null; currency: string; values: SupplierValues
  choices: { industries: Option[]; supply_kinds: Option[] }; sections: { industry: boolean }; bank_checked: string | null
}
export type SupplierAction = 'approve' | 'block' | 'unblock' | 'verify_bank' | 'portal' | 'opening' | 'archive' | 'restore'
export type SupplierActionResult = { supplier: SupplierDetail; link?: string; open?: string | null; sent?: boolean }
export type PoRow = { id: string; number: string; supplier: string | null; to: string | null; currency: string; total: number; expected_on: string | null; status: string; created_at: string | null }
export type PoList = {
  currency: string; exponent: number; total: number; sum: number; waiting: number; open: number
  page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: PoRow[]
}
export type PoOptions = {
  statuses: Option[]; suppliers: Option[]; warehouses: Option[]; per: number[]; can_create: boolean
  import: { suppliers: Option[]; warehouses: Option[]; ai_unavailable: string | null } | null
}
export type PoReceiveLine = { id: string; item: string; batched: boolean; serialised: boolean; qty: string }
export type PoDetail = {
  currency: string; exponent: number; id: string; number: string; status: string; status_label: string
  supplier: { id: string; name: string | null; open: string | null }; warehouse: string | null
  net: number; tax: number; total: number; expected_on: string | null; created_by: string | null; created_at: string | null
  approved_by: string | null; rejected_reason: string | null; close_reason: string | null
  sent: { at: string; via: string; to: string; by: string | null } | false | null
  confirmed: { promised_on: string | null; at: string; reply: string | null } | false | null
  dispatched: { at: string; ref: string | null } | null
  department: string | null; agreement: { number: string | null; open: string } | null; budget_note: string | null; note: string | null
  lines: { sku: string | null; name: string; service: boolean; department: string | null; ordered: string; received: string; cost: number; total: number }[]
  actions: {
    print: string; edit: boolean; submit: boolean; approve: boolean; reject: boolean
    send: { again: boolean; channels: Option[]; email: string | null; phone: string | null } | null
    copy: boolean; confirm_delivered: { lines: { id: string; item: string; qty: string }[] } | null
    receive: { bins: Option[] | null; lines: PoReceiveLine[] } | null
    close_short: boolean; cancel: boolean; receipts: string | null
  }
}
export type PoLine = {
  kind: 'stock' | 'service'; product_uom_id: string; label: string | null; account_id: string; description: string; uom_code: string
  department_id: string; project_id: string; agreement_line_id: string; qty: string; unit_cost: string
}
export type PoValues = { supplier_id: string; warehouse_id: string; expected_on: string; department_id: string; note: string; lines: PoLine[] }
export type PoForm = {
  id: string | null; number: string | null; currency: string; values: PoValues
  choices: { suppliers: Option[]; warehouses: Option[]; departments: Option[]; accounts: Option[]; projects: Option[] | null }; can_add_supplier: boolean
}
export type PoAction = 'submit' | 'approve' | 'reject' | 'send' | 'copy' | 'confirm_delivered' | 'receive' | 'close_short' | 'cancel'
export type PoActionResult = { order: PoDetail; open?: string | null; copy?: { id: string; number: string }; receipt?: { number: string; held: boolean } }
export type GrnRow = { id: string; number: string; order: string | null; supplier: string | null; ref: string | null; warehouse: string | null; currency: string; value: number; received_at: string | null; received_by: string | null; status: string }
export type GrnList = { currency: string; exponent: number; total: number; value: number; held: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: GrnRow[] }
export type GrnOptions = { statuses: Option[]; warehouses: Option[]; per: number[] }
export type GrnDetail = {
  currency: string; exponent: number; id: string; number: string; status: string; status_label: string
  order: { number: string | null; open: string | null } | null; supplier: { name: string | null; open: string | null }; warehouse: string | null; ref: string | null
  received_by: string | null; received_at: string | null; approved_by: string | null; value: number; reversal: { reason: string | null; by: string | null } | null; note: string | null
  lines: { sku: string; name: string; qty: string; cost: number; value: number; bin: string | null; serials: string[] }[]
  product_ids: string[]
  actions: { print: string; accept: boolean; refuse: boolean; reverse: boolean; labels: boolean; waybill: { label: string; open: string } | null }
}
export type DpRow = { id: string; number: string; invoice_date: string | null; supplier: string | null; invoice_no: string; settlement: string; currency: string; total: number; status: string; by: string | null }
export type DpList = { currency: string; exponent: number; total: number; posted: number; waiting: number; drafts: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: DpRow[] }
export type DpOptions = { statuses: Option[]; settlements: Option[]; per: number[]; can_create: boolean }
export type DpDetail = {
  currency: string; exponent: number; id: string; number: string; status: string; status_label: string
  supplier: { name: string | null; open: string | null }; invoice_no: string; invoice_date: string | null; due_on: string | null
  settlement: string; settlement_label: string; net: number; tax: number; total: number; printed_total: number | null; prices_include_tax: boolean
  warehouse: string | null; method: string | null; reference: string | null; created_by: string | null; posted_by: string | null; reason: string | null; note: string | null
  goods_receipt: { number: string | null; open: string | null } | null; bill: string | null; payment: string | null; warnings: string[]
  lines: { product: string | null; qty: string; unit_price: number; batch: string | null; expires_on: string | null; net: number; tax: number; new_price: number | null; sell_unit: string | null; margin: number | null }[]
  pages: number; product_ids: string[]
  actions: { edit: boolean; correcting: boolean; submit: boolean; post: { what: string } | null; why_not: string | null; cancel: boolean; labels: boolean }
}
export type DpSellUnit = { id: string; label: string; factor: number }
export type DpLine = {
  product_uom_id: string; label: string | null; read_as: string | null; batched: boolean; factor: number; sell_units: DpSellUnit[]
  qty: string; unit_price: string; tax_group_id: string; batch_no: string; expires_on: string; supplier_sku: string; sell_uom_id: string; new_price: string
}
export type DpValues = {
  supplier_id: string; invoice_no: string; invoice_date: string; warehouse_id: string; prices_include_tax: boolean; printed_total: string; attachments: string[]
  settlement: string; method: string; account_id: string; reference: string; due_on: string; price_list_id: string; note: string; lines: DpLine[]
}
export type DpForm = {
  id: string | null; number: string | null; currency: string; correcting: boolean; values: DpValues
  choices: { suppliers: Option[]; warehouses: Option[]; settlements: Option[]; methods: Option[]; accounts: Option[]; tax_groups: Option[]; price_lists: Option[]; uoms: Option[]; industries: Option[] | null }
  short_dated_months: number; can_add_product: boolean; ai_unavailable: string | null
}
export type SiRow = {
  id: string; received_at: string | null; supplier: string | null; invoice_no: string; invoice_date: string | null; currency: string; amount: number
  order: { number: string; open: string | null } | null; sent_by: string | null; status: string; note: string | null
  actions: { file: string | null; read: boolean; enter: string | null; mark: boolean; reject: boolean }
}
export type SiList = { currency: string; exponent: number; total: number; amount: number; waiting: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: SiRow[] }
export type SiOptions = { statuses: Option[]; per: number[]; consignment: boolean }
export type ConsignmentOwed = {
  currency: string; exponent: number; bill?: string
  rows: { key: string; supplier: string; supplier_open: string | null; branch: string; qty: string; sold: number; owed: number }[]
}
export type RuleRow = { id: string; product: string | null; sku: string | null; store: string | null; on_hand: string; min: string; max: string; source: string; source_label: string; editable: boolean }
export type RuleList = { total: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; default_note: string; rows: RuleRow[] }
export type RuleOptions = { warehouses: Option[]; set_by: Option[]; per: number[]; can_set: boolean; can_order: boolean; can_suggested: boolean; stores: Option[] }
export type RuleUnits = { units: Option[]; buying: string | null }
export type RuleForm = RuleUnits & { product: string | null; store: string | null; product_id: string; warehouse_id: string; product_uom_id: string; min: string; max: string }
export type OrderRow = {
  id: string; product_id: string; name: string; sku: string; changed: boolean; on_hand: string; on_order: string; in_transit: string; min: string; max: string; source: string | null
  move: string | null; supplier_id: string | null; supplier: string | null; product_uom_id: string | null; uom_code: string | null; qty: string; order: string | null
  cost: string | null; unit_cost: number | null
}
export type OrderList = { currency: string; exponent: number; warehouse: string; can_levels: boolean; rows: OrderRow[] }
export type OrderChange = { supplier_id?: string | null; product_uom_id?: string | null; qty?: string | null; cost?: string | null }
export type SuggestedRow = { id: string; name: string; sku: string; store: string; avg_daily: string; lead_days: number; now: string; now_source: string | null; suggested: string }
export type EcRow = { id: string; number: string; spent_on: string | null; by: string | null; branch: string | null; description: string; for: string | null; amount: number; status: string; mine: boolean }
export type EcList = { currency: string; exponent: number; total: number; amount: number; waiting: number | null; to_pay: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: EcRow[] }
export type EcDetail = {
  currency: string; exponent: number; id: string; number: string; status: string; status_label: string; by: string | null; branch: string | null; spent_on: string | null
  for: string | null; amount: number; payee: string | null; description: string; receipt: string | null; decided_by: string | null; rejection_reason: string | null
  paid: { by: string; on: string | null; from: string | null } | null
  actions: { approve: boolean; reject: boolean; pay: { accounts: Option[]; default: string } | null }
}
export type EcForm = { currency: string; branches: Option[]; accounts: Option[]; projects: Option[] | null }
export type SbRow = { id: string; number: string; supplier: string | null; invoice_no: string; bill_date: string | null; due_on: string | null; total: number; variance: number; status: string }
export type SbList = { currency: string; exponent: number; total: number; posted: number; waiting: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: SbRow[] }
export type SbOptions = { statuses: Option[]; suppliers: Option[]; per: number[]; can_create: boolean; scan: { branches: Option[]; hq: string; ai_unavailable: string | null } | null }
export type SbDetail = {
  currency: string; exponent: number; id: string; number: string; status: string; status_label: string; supplier: { name: string | null; open: string | null }
  invoice_no: string; bill_date: string | null; due_on: string | null; net: number; tax: number; total: number; variance: number
  created_by: string | null; decided_by: string | null; variance_reason: string | null; note: string | null
  lines: { description: string; qty: string; price: number; order_price: number | null; tax: number; total: number }[]
  actions: { approve: boolean; reject: boolean }
}
export type SbStockLine = { goods_receipt_line_id: string; qty: string; unit_cost: string }
export type SbOrderLine = { purchase_order_line_id: string; qty: string; unit_cost: string }
export type SbExpenseLine = { account_id: string; description: string; amount: string; tax_group_id: string; department_id: string; project_id: string; maintenance_order_id: string }
export type SbValues = {
  supplier_id: string; supplier_invoice_no: string; branch_id: string; bill_date: string; due_on: string; note: string
  stock_lines: SbStockLine[]; order_lines: SbOrderLine[]; expense_lines: SbExpenseLine[]
}
export type SbForm = {
  currency: string; values: SbValues; notice: string | null; checks: string[]; scan: string | null; invoice: string | null; can_add_supplier: boolean
  choices: { suppliers: Option[]; branches: Option[]; accounts: Option[]; tax_groups: Option[]; departments: Option[]; projects: Option[] | null; work_orders: Option[] | null }
}
export type SbBillable = { grns: Option[]; stock: { id: string; label: string; qty: string; cost: string; grn: string | null }[]; orders: { id: string; label: string; qty: string; cost: string }[] }
export type SpRow = {
  id: string; number: string; paid_on: string | null; supplier: string | null; supplier_open: string | null; method: string; from: string | null; reference: string | null
  amount: number; wht: number; certificate_no: string | null; certificate: string | null; paid: number; by: string | null
}
export type SpList = { currency: string; exponent: number; total: number; settled: number; wht: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: SpRow[] }
export type PayForm = { suppliers: Option[]; accounts: Option[]; account: string; methods: Option[]; branches: Option[]; branch: string; wht: boolean }
export type SpOptions = { methods: Option[]; suppliers: Option[]; per: number[]; pay: PayForm | null; aging: boolean }
export type AgingRow = { id: string; name: string; code: string; open: string | null; current: number; d1_30: number; d31_60: number; d61_90: number; d90_plus: number; total: number }
export type Aging = { currency: string; exponent: number; rows: AgingRow[]; totals: Record<string, number>; pay: PayForm | null }
export type WhtRow = { id: string; date: string | null; certificate_no: string | null; certificate: string; number: string; supplier: string | null; tin: string | null; nature: string; base: number; rate: number; wht: number }
export type FrCell = string | null | { t: string; to?: string; tone?: 'good' | 'bad' | null; strong?: boolean; bar?: number }
export type FrTable = { title: string; columns: { label: string; end: boolean }[]; rows: { kind?: 'head' | 'sub' | 'total' | null; cells: FrCell[]; drill?: Record<string, string> | null }[]; foot: FrCell[]; hint?: string | null; empty?: string | null }
export type ShrinkView = { from: string; to: string; warehouse: string; warehouses: Option[]; total: string; entries: number; tables: FrTable[] }
export type SlowRow = { id: string; sku: string; name: string; on_hand: string; value: string; last_sold: string | null; idle_days: number
  move_to: { id: string; code: string; name: string; sold: string } | null; supplier: string | null }
export type SlowView = { warehouse: string; warehouses: Option[]; days: number; periods: Option[]; count: number; value: string; can_move: boolean; rows: SlowRow[] }
export type BatchRow = { batch_id: string; product: string; sku: string; batch_no: string; expires_on: string | null; expires: string | null; on_hand: string }
export type BatchesView = { warehouse: string; warehouses: Option[]; can_mark_down: boolean; can_correct: boolean; can_add: boolean
  suggestions: { batch_id: string; product: string; sku: string; batch_no: string; expires: string; days_left: number; on_hand: string; percent: string }[]
  markdowns: { id: string; product: string | null; batch_no: string | null; percent: number; price: string; until: string | null; code: string }[]
  labels: string | null; expired: BatchRow[]; batches: BatchRow[]; expiry_returns: string | null; sheet: string }
export type ReturnRow = { id: string; receipt_no: string; occurred_at: string | null; branch: string | null; by: string | null; approved_by: string | null
  reason: string | null; details: string | null; items: string; to: string; refund: number; currency: string; paid_by: string; open: string; slip: string }
export type ReturnsList = { currency: string; exponent: number; total: number; refunded: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: ReturnRow[] }
export type ReturnsOptions = { tellers: Option[]; branches: Option[]; per: number[]; summary: string; reasons: Option[]; products: Option[] }
export type JournalRow = { id: string; number: string; date: string | null; kind: string; kind_label: string; memo: string | null; amount: number; by: string | null }
export type JournalList = { currency: string; exponent: number; total: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: JournalRow[] }
export type JournalOptions = { kinds: Option[]; per: number[]; can_journal: boolean; can_post_now: boolean; accounts: Option[]; branches: Option[]; hq: string }
export type JournalDetail = JournalRow & { currency: string; exponent: number; reverses: { id: string; number: string } | null; reversed_by: { id: string; number: string } | null
  can_reverse: boolean; lines: { account: string | null; account_id: string; branch: string | null; memo: string | null; debit: number; credit: number }[]; debits: number; credits: number }
export type AccountRow = { id: string; code: string; name: string; type: string; type_label: string; header: boolean; parent_id: string | null; control: string | null
  purpose: string | null; system_key: string | null; description: string | null; balance: number | null; archived: boolean; archive_note: string | null
  can_edit: boolean; can_archive: boolean; can_restore: boolean; blockers: string[]; ledger: string | null }
export type AccountForm = { types: Option[]; headers: (Option & { type: string })[]; purposes: (Option & { type: string })[]
  next_by_type: Record<string, string | null>; next_by_header: Record<string, string | null>; can_manage: boolean }
export type PeriodRow = { id: string; month: string; period: number; status: string; closed_by: string | null; closed_at: string | null; can_close: boolean; blockers: string[]; can_reopen: boolean }
export type MonthEnd = { year: number; years: Option[]; can_setup: boolean; rows: PeriodRow[] }
export type BudgetView = { year: number; branch: string; branches: Option[]; can_save: boolean; months: string[]; rows: { id: string; label: string; type: string; amounts: string[] }[] }
export type BankRow = { id: string; name: string; kind: string; kind_label: string; provider: string | null; number: string | null; account_id: string; ledger: string | null
  balance: number; to_match: number; archived: boolean; can_archive: boolean; can_restore: boolean; blockers: string[]; mapping: Record<string, string | number> | null }
export type BankList = { currency: string; exponent: number; can_manage: boolean; can_reconcile: boolean; rows: BankRow[] }
export type BankForms = { kinds: Option[]; ledger_accounts: Option[]; money_accounts: Option[]; branches: Option[]; hq: string; date_formats: Option[]; post_accounts: Option[]; can_manage: boolean }
export type RecLine = { id: string; date: string; description?: string | null; reference?: string | null; number?: string; memo?: string | null; amount: number; suggested: boolean }
export type Reconcile = { bank: { id: string; name: string; ledger: string | null }; as_at: string; currency: string; exponent: number
  summary: { ledger: number; outstanding: number; unrecorded: number; expected_statement: number; statement: number | null; difference: number | null }
  suggestions: number; statement: RecLine[]; ledger: RecLine[]; matches: { id: string; at: string; by: string | null; amount: number }[] }
export type MoneyMoveRow = { id: string; number: string; date: string | null; from: string | null; to: string | null; amount: number; reference: string | null; note: string | null; by: string | null }
export type MoneyMoveList = { currency: string; exponent: number; total: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; can_move: boolean; rows: MoneyMoveRow[] }
export type AssetRow = { id: string; number: string; name: string; serial_no: string | null; kind: string | null; branch: string | null; acquired_on: string | null; cost: number; book: number; status: string }
export type AssetList = { currency: string; exponent: number; total: number; cost: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: AssetRow[] }
export type AssetOptions = { per: number[]; can_manage: boolean; can_classes: boolean; kinds: (Option & { life_months: number })[]; branches: Option[]; hq: string; money_accounts: Option[] }
export type AssetDetail = AssetRow & { currency: string; exponent: number; location: string | null; residual: number; life_months: number; opening_depreciation: number; book_now: number
  disposal: { date: string | null; reason: string; proceeds: number } | null; can_dispose: boolean; schedule: { month: string; amount: number }[] }
export type AssetKind = { id: string; code: string; name: string; life_months: number; cost_account_id: string; accumulated_account_id: string; expense_account_id: string
  cost_account: string | null; accumulated_account: string | null; expense_account: string | null }
export type AssetKinds = { can_manage: boolean; accounts: { asset: Option[]; expense: Option[] } | null; rows: AssetKind[] }
export type EvatRow = { id: string; date: string | null; number: string; type: string; type_label: string; mode: string; reference: string | null; status: string; status_label: string
  error: string | null; attempts: number; can_retry: boolean }
export type EvatList = { total: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; counts: Record<string, number>; rows: EvatRow[] }
export type EvatOptions = { per: number[]; mode: string; mode_label: string; can_batch: boolean; statuses: Record<string, string>; types: Record<string, string> }
export type EvatDetail = EvatRow & { sent: string | null; answer: string | null; batch: string | null; next_attempt: string | null }
export type AccountingExportOptions = { formats: Record<string, string>; from: string; to: string }
export type UserRow = { id: string; name: string; username: string; email: string; photo: string; support: boolean; level: number; mfa: boolean; signs_in: string; pin: boolean
  last_till: string | null; archived: boolean; active: boolean }
export type UserList = { total: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: UserRow[] }
export type UserOptions = { per: number[]; can_manage: boolean; can_grant: boolean; levels: Option[]; signin_methods: Option[]; password_only_max: number; card_layouts: Option[]
  currency: string; exponent: number; roles: Option[]; scopes: Option[]; places: Record<string, Option[]> }
export type UserGrant = { id: string; role: string | null; applies_to: string; limits: string; status: string; current: boolean; expires: string | null; by: string | null; reason: string | null; can_revoke: boolean }
export type UserDetail = UserRow & { phone: string | null; signin_method: string; card: boolean; me: boolean; can_edit: boolean; can_reset_mfa: boolean; can_archive: boolean
  archive_blockers: string[]; can_restore: boolean; grants: UserGrant[]; history: { at: string; by: string; name: [string, string] | null; username: [string, string] | null }[] }
/** A user change: done (message), or sent to the owner or SyncLytics support for approval (pending). */
export type UserDone = { message: string; pending?: boolean; user?: { id: string; open: string }; print?: string }
export type RoleRow = { id: string; name: string; description: string | null; level: number; permissions: number; technical: boolean; seeded: boolean; archived: boolean }
export type RoleList = { total: number; page: number; per: number; sort: string; dir: 'asc' | 'desc'; can_manage: boolean; can_requests: boolean; waiting: number; rows: RoleRow[] }
export type RoleForm = { levels: Option[]; areas: { area: string; permissions: { key: string; label: string; min_level: number; technical: boolean }[] }[] }
export type RoleDetail = RoleRow & { key: string; keys: string[]; holders: number; can_edit: boolean; can_archive: boolean; archive_blockers: string[]; can_restore: boolean }
export type AccessRequestRow = { id: string; status: string; status_label: string; kind: string; summary: string; by: string | null; asked: string | null; lapses: string | null
  decided_by: string | null; note: string | null; can_decide: boolean; can_withdraw: boolean }
export type AccessRequestList = { total: number; page: number; per: number; statuses: Record<string, string>; rows: AccessRequestRow[] }
export type WideRow = { id: string; person: string | null; user_id: string; role: string | null; applies_to: string; allowed: boolean }
export type WideList = { total: number; page: number; per: number; needs: number; branches: Option[]; industries: Option[]; rows: WideRow[] }
export type ReviewWho = { name: string; id: string; where: string; via: string }
export type AccessReviewData = { counts: { people: number; high: number; medium: number }; can_open: boolean
  risks: { severity: 'high' | 'medium'; title: string; why: string; fix: string; who: ReviewWho[] }[]
  notes: { tone: 'warning' | 'info'; id: string; text: string }[]
  people: { id: string; name: string; username: string; active: boolean; owner: boolean; roles: { role: string; where: string; until: string | null }[]
    risks: { severity: 'high' | 'medium'; title: string }[] }[] }
export type ActivityRow = { id: string; when: string | null; who: string | null; who_kind: string | null; what: string; record: string; details: string | null; computer: string | null
  changes: { field: string; value: string }[]; reason: string | null }
export type ActivityList = { total: number; page: number; per: number; rows: ActivityRow[] }
export type CardRow = { id: string; name: string; username: string; photo: string | null; roles: string[]; pin: boolean; card: boolean; card_on: string | null }
export type CardList = { total: number; page: number; per: number; layouts: Option[]; rows: CardRow[] }
export type SettingDef = { key: string; label: string; help: string; type: 'bool' | 'string' | 'choice' | 'int'; value: unknown; options: Option[] | null
  min: number | null; max: number | null; rows: number | null; may_change: boolean; who: string | null }
export type SettingsData = { groups: { group: string; settings: SettingDef[] }[] }
export type ModuleItem = { key: string; name: string; description: string; status_label: string; available: boolean; status: 'enabled' | 'reserved' | 'disabled' }
export type ModulesData = { branch_id: string | null; branches: Option[]; sections: { label: string; modules: ModuleItem[] }[] }
export type CredField = { key: string; label: string; secret: boolean; saved: boolean }
export type MessagingSetupData = {
  sms: { providers: Option[]; provider: string; sender_id: string | null; active: boolean; fields: Record<string, CredField[]>; tested: string | null }
  whatsapp: { active: boolean; exists: boolean; fields: CredField[]; webhook: { url: string; verify_token: string } | null; tested: string | null }
  email: { driver: string; from: string; log_only: boolean } }
export type TemplateRow = { id: string; key: string; name: string; placeholders: string | null; channel: string; channel_label: string; subject: string | null; body: string
  active: boolean; whatsapp_template: string | null; whatsapp_language: string | null; archived: boolean }
export type TemplateList = { channels: Option[]; can_edit: boolean; rows: TemplateRow[] }
export type MessageRow = { id: string; when: string | null; channel: string; to: string; reference: string | null; subject: string | null; body: string; status: string; status_label: string
  error: string | null; provider: string | null; provider_ref: string | null; attempts: number; attachment: string | null; can_retry: boolean }
export type MessageLog = { total: number; page: number; per: number; statuses: Option[]; channels: Option[]; rows: MessageRow[] }
export type DeviceRow = { id: string; name: string; kind: string; branch: string | null; till: string | null; status: string; code_expires: string | null; last_seen: string | null
  health: string | null; health_details: string | null; used_from: string | null; mac: string | null; archived: boolean; can_view: boolean; can_control: boolean
  view_url: string | null; control_url: string | null; can_fix: boolean; has_fixes: boolean; can_reissue: boolean; can_revoke: boolean; can_archive: boolean
  archive_blockers: string[]; can_restore: boolean }
export type DeviceList = { total: number; page: number; per: number; remote_link: string | null; remote_available: boolean; rows: DeviceRow[] }
export type DeviceOptions = { branches: Option[]; commands: Option[]; tills: Option[]; kinds: Option[] }
export type DeviceFix = { when: string | null; what: string; by: string | null; status: string; result: string | null }
export type ComputerRow = { id: string; label: string; name: string | null; machine: string | null; last_user: string | null; last_seen: string | null; ip: string | null
  lookup: string | null; browser: string | null; first_seen: string | null; activity: string }
export type ComputerList = { total: number; page: number; per: number; rows: ComputerRow[] }
export type RefKind = 'categories' | 'brands' | 'units' | 'tax-rates' | 'tax-groups' | 'price-lists'
export type RefRow = { id: string; name: string; products: number; archived: boolean; can_edit: boolean; can_move: boolean; can_delete: boolean; can_archive: boolean
  archive_blockers: string[]; can_restore: boolean; values: Record<string, unknown>; [extra: string]: unknown }
export type RefList = { kind: RefKind; can_create: boolean; archivable: boolean; rows: RefRow[] }
export type RefOptions = { move_to: Option[]; industries?: Option[]; parents?: Option[]; default_industry?: string | null; decimals?: Option[]; merge_into?: Option[]; rates?: Option[]; branches?: Option[] }
export type RuleValues = { scope: string; category_id: string | null; product_id: string | null; product?: string | null; price_list_id: string; method: string; value: string
  amount: string; tax_mode: string; round_minor: string; round_mode: string; on_change: string; is_active: boolean }
export type PricingRuleRow = { id: string; applies_to: string; price_list: string | null; rule: string; gives: string; rounding: string; on_change: string; active: boolean; archived: boolean
  can_edit: boolean; can_archive: boolean; can_restore: boolean; values: RuleValues }
export type PricingRuleList = { can_create: boolean; rows: PricingRuleRow[] }
export type PricingRuleOptions = { scopes: Option[]; methods: Option[]; tax_modes: Option[]; rounding: Option[]; on_change: Option[]; currency: string; price_lists: Option[]; default_list: string | null
  categories: Option[] }
export type SuggestionRow = { id: string; product: string | null; sku: string | null; unit: string | null; price_list: string | null; cost: number; now: number | null; new: number
  change: string; why: string | null; since: string | null }
export type SuggestionList = { total: number; page: number; per: number; currency: string; exponent: number; rows: SuggestionRow[] }
export type PromotionRow = { id: string; name: string; coupon: string | null; type: string; offer: string; when: string; running: boolean; archived: boolean
  can_edit: boolean; can_archive: boolean; can_restore: boolean }
export type PromotionList = { total: number; page: number; per: number; can_create: boolean; rows: PromotionRow[] }
export type PromotionOptions = { types: Option[]; currency: string; industries: Option[]; default_industry: string | null; categories: Option[]; branches: Option[]; price_lists: Option[] }
export type PromotionValues = { name: string; industry_id: string; is_active: boolean; type: string; coupon_code: string; percent: string; amount: string; min_spend: string
  buy_qty: string; get_qty: string; bundle_qty: string; product_ids: string[]; products?: Option[]; category_ids: string[]; starts_at: string; ends_at: string; days: string[]
  time_from: string; time_to: string; branch_ids: string[]; archived_branches?: Option[]; price_list_ids: string[] }
export type RbDataset = { id: string; label: string; dated: boolean; dims: Option[]; measures: Option[] }
export type RbOptions = { datasets: RbDataset[]; periods: Option[]; branches: Option[]; schedules: Option[]; people: Option[]; max_rows: number }
export type RbChoices = { dataset: string; dimensions: string[]; measures: string[]; period: string; from: string | null; to: string | null; branch_id: string | null
  filter_dim: string | null; filter_value: string | null; sort: string | null; direction: 'asc' | 'desc' }
export type RbResult = { title: string; period: string; truncated: boolean; table: FrTable; hint: string | null }
export type RbRecipient = { type: 'email' | 'whatsapp' | 'user'; to: string }
export type SavedReportRow = { id: string; name: string; dataset: string; by: string | null; mine: boolean; shared: boolean; schedule: string; schedule_label: string
  next: string | null; last: string | null; schedule_day: number | null; schedule_time: string | null; format: string; recipients: RbRecipient[]; open: string }
export type InsightsView = { tab: string; tabs: Option[]; title: string; warehouse: string; warehouses: Option[]; by_warehouse: boolean; tables: FrTable[] }
export type FrReport = { report: string; title: string; account: string | null; from: string; to: string; branch: string | null; industry: string | null; books: string | null
  tables: FrTable[]; notes: string[]; empty?: string; prompt?: string; warning?: string | null; cards?: { label: string; value: string; tone?: 'good' | 'bad' }[]
  share: { month: string; text: string; can: boolean } | null }
export type FrOptions = { reports: Option[]; accounts: Option[]; branches: Option[]; books: Option[]; industries: Option[] }
export type WhtMonth = { currency: string; exponent: number; month: string; months: Option[]; due: string; total: number; enabled: boolean; rows: WhtRow[] }
export type CustomerAction = 'pay' | 'credit' | 'opening' | 'patient' | 'follow_up' | 'archive' | 'restore'
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
  customers: (query: string, signal?: AbortSignal) => call<CustomerList>(`/office-api/customers?${query}`, { signal }),
  customersOptions: () => call<CustomerOptions>('/office-api/customers/options'),
  customer: (id: string) => call<CustomerDetail>(`/office-api/customers/${id}`),
  customerForm: (id?: string) => call<CustomerForm>(id ? `/office-api/customers/${id}/form` : '/office-api/customers/form'),
  saveCustomer: (id: string | null, values: CustomerValues) =>
    call<{ id: string }>(id ? `/office-api/customers/${id}` : '/office-api/customers', { method: 'POST', body: JSON.stringify({ values }) }),
  customerAction: (id: string, action: CustomerAction, body: Record<string, unknown> = {}) =>
    call<CustomerDetail>(`/office-api/customers/${id}/action`, { method: 'POST', body: JSON.stringify({ action, ...body }) }),
  suppliers: (query: string, signal?: AbortSignal) => call<SupplierList>(`/office-api/suppliers?${query}`, { signal }),
  suppliersOptions: () => call<SupplierOptions>('/office-api/suppliers/options'),
  supplier: (id: string) => call<SupplierDetail>(`/office-api/suppliers/${id}`),
  supplierForm: (id?: string) => call<SupplierForm>(id ? `/office-api/suppliers/${id}/form` : '/office-api/suppliers/form'),
  supplierUnits: (q: string, signal?: AbortSignal) => call<{ results: UnitOption[] }>(`/office-api/suppliers/units?q=${encodeURIComponent(q)}`, { signal }),
  /** Save; ApiError code "reconfirm" (with its url) = new bank details: confirm it is you first, then save again. */
  saveSupplier: (id: string | null, values: SupplierValues) =>
    call<{ id: string }>(id ? `/office-api/suppliers/${id}` : '/office-api/suppliers', { method: 'POST', body: JSON.stringify({ values }) }),
  supplierAction: (id: string, action: SupplierAction, body: Record<string, unknown> = {}) =>
    call<SupplierActionResult>(`/office-api/suppliers/${id}/action`, { method: 'POST', body: JSON.stringify({ action, ...body }) }),
  /** Add a document (with its scan or photo): sent as a form, not JSON. */
  addSupplierDocument: async (id: string, body: FormData) => {
    const res = await fetch(`/office-api/suppliers/${id}/documents`, { method: 'POST', body, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() } })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new ApiError(json.detail ?? json.message ?? 'The document could not be added.', res.status, json.error ?? 'error')
    return json as { supplier: SupplierDetail }
  },
  supplierDocument: (id: string, doc: string, action: 'verify' | 'remove', reason?: string) =>
    call<{ supplier: SupplierDetail }>(`/office-api/suppliers/${id}/documents/${doc}`, { method: 'POST', body: JSON.stringify({ action, reason }) }),
  supplierDocumentUrl: (id: string, doc: string) => `/office-api/suppliers/${id}/documents/${doc}/file`,
  purchaseOrders: (query: string, signal?: AbortSignal) => call<PoList>(`/office-api/purchase-orders?${query}`, { signal }),
  purchaseOrdersOptions: () => call<PoOptions>('/office-api/purchase-orders/options'),
  purchaseOrder: (id: string) => call<PoDetail>(`/office-api/purchase-orders/${id}`),
  purchaseOrderForm: (id?: string) => call<PoForm>(id ? `/office-api/purchase-orders/${id}/form` : '/office-api/purchase-orders/form'),
  poUnits: (q: string, signal?: AbortSignal) => call<{ results: UnitOption[] }>(`/office-api/purchase-orders/units?q=${encodeURIComponent(q)}`, { signal }),
  poLineInfo: (supplier: string, unit: string, warehouse: string) =>
    call<{ rule: string | null; available: string | null }>(`/office-api/purchase-orders/line-info?${new URLSearchParams({ supplier, unit, warehouse })}`),
  poPackCode: (code: string) => call<{ batch: string | null; expires_on: string | null }>(`/office-api/purchase-orders/pack-code?code=${encodeURIComponent(code)}`),
  poAddSupplier: (body: { name: string; phone?: string; email?: string; code?: string }) =>
    call<{ id: string; label: string; existed: boolean }>('/office-api/purchase-orders/add-supplier', { method: 'POST', body: JSON.stringify(body) }),
  savePurchaseOrder: (id: string | null, values: PoValues) =>
    call<{ id: string }>(id ? `/office-api/purchase-orders/${id}` : '/office-api/purchase-orders', { method: 'POST', body: JSON.stringify({ values }) }),
  purchaseOrderAction: (id: string, action: PoAction, body: Record<string, unknown> = {}) =>
    call<PoActionResult>(`/office-api/purchase-orders/${id}/action`, { method: 'POST', body: JSON.stringify({ action, ...body }) }),
  /** "Order from a file": sent as a form (the file), not JSON. */
  importPurchaseOrder: async (body: FormData) => {
    const res = await fetch('/office-api/purchase-orders/import', { method: 'POST', body, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() } })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new ApiError(json.detail ?? json.message ?? 'The file could not be read.', res.status, json.error ?? 'error')
    return json as { id: string; number: string; lines: number; skipped: string[] }
  },
  goodsReceipts: (query: string, signal?: AbortSignal) => call<GrnList>(`/office-api/goods-receipts?${query}`, { signal }),
  goodsReceiptsOptions: () => call<GrnOptions>('/office-api/goods-receipts/options'),
  goodsReceipt: (id: string) => call<GrnDetail>(`/office-api/goods-receipts/${id}`),
  goodsReceiptAction: (id: string, action: 'accept' | 'refuse' | 'reverse', reason?: string) =>
    call<GrnDetail>(`/office-api/goods-receipts/${id}/action`, { method: 'POST', body: JSON.stringify({ action, reason }) }),
  directPurchases: (query: string, signal?: AbortSignal) => call<DpList>(`/office-api/direct-purchases?${query}`, { signal }),
  directPurchasesOptions: () => call<DpOptions>('/office-api/direct-purchases/options'),
  directPurchase: (id: string) => call<DpDetail>(`/office-api/direct-purchases/${id}`),
  directPurchaseForm: (id?: string) => call<DpForm>(id ? `/office-api/direct-purchases/${id}/form` : '/office-api/direct-purchases/form'),
  dpUnits: (q: string, signal?: AbortSignal) => call<{ results: UnitOption[] }>(`/office-api/direct-purchases/units?q=${encodeURIComponent(q)}`, { signal }),
  dpUnit: (id: string) => call<DpLine>(`/office-api/direct-purchases/unit?id=${encodeURIComponent(id)}`),
  dpAddProduct: (body: Record<string, unknown>) => call<DpLine>('/office-api/direct-purchases/add-product', { method: 'POST', body: JSON.stringify(body) }),
  saveDirectPurchase: (id: string | null, values: DpValues) =>
    call<{ id: string }>(id ? `/office-api/direct-purchases/${id}` : '/office-api/direct-purchases', { method: 'POST', body: JSON.stringify({ values }) }),
  directPurchaseAction: (id: string, action: 'submit' | 'post' | 'cancel', reason?: string) =>
    call<DpDetail>(`/office-api/direct-purchases/${id}/action`, { method: 'POST', body: JSON.stringify({ action, reason }) }),
  dpPageUrl: (id: string, n: number) => `/office-api/direct-purchases/${id}/page/${n}`,
  /** Invoice pages: kept privately; "scan" also reads them with the AI and returns the filled form. Sent as a form, not JSON. */
  dpFiles: async <T,>(what: 'upload' | 'scan', files: File[]) => {
    const body = new FormData()
    files.forEach((f) => body.append('files[]', f))
    const res = await fetch(`/office-api/direct-purchases/${what}`, { method: 'POST', body, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() } })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new ApiError(json.detail ?? json.message ?? 'The pages could not be added.', res.status, json.error ?? 'error')
    return json as T
  },
  supplierInvoices: (query: string, signal?: AbortSignal) => call<SiList>(`/office-api/supplier-invoices?${query}`, { signal }),
  supplierInvoicesOptions: () => call<SiOptions>('/office-api/supplier-invoices/options'),
  supplierInvoiceBills: (id: string) => call<{ bills: Option[] }>(`/office-api/supplier-invoices/${id}/bills`),
  /** read: the AI fills a draft bill (open: where to check it) · mark (bill) · reject (reason) */
  supplierInvoiceAction: (id: string, action: 'read' | 'mark' | 'reject', body: Record<string, unknown> = {}) =>
    call<{ open?: string; row?: SiRow }>(`/office-api/supplier-invoices/${id}/action`, { method: 'POST', body: JSON.stringify({ action, ...body }) }),
  consignment: () => call<ConsignmentOwed>('/office-api/consignment'),
  settleConsignment: (who: string, statement: string) =>
    call<ConsignmentOwed>('/office-api/consignment/settle', { method: 'POST', body: JSON.stringify({ who, statement }) }),
  reorderRules: (query: string, signal?: AbortSignal) => call<RuleList>(`/office-api/reorder-rules?${query}`, { signal }),
  reorderRulesOptions: () => call<RuleOptions>('/office-api/reorder-rules/options'),
  reorderProducts: (q: string) => call<{ results: Option[] }>(`/office-api/reorder-rules/products?q=${encodeURIComponent(q)}`),
  reorderUnits: (product: string) => call<RuleUnits>(`/office-api/reorder-rules/units?product=${encodeURIComponent(product)}`),
  reorderRuleForm: (id: string) => call<RuleForm>(`/office-api/reorder-rules/${id}/form`),
  saveReorderRule: (body: { warehouse_id: string; product_id: string; product_uom_id: string; min: string; max: string }) =>
    call<{ saved: number }>('/office-api/reorder-rules', { method: 'POST', body: JSON.stringify(body) }),
  reorderUseDefault: (id: string) => call<{ done: boolean }>(`/office-api/reorder-rules/${id}/default`, { method: 'POST' }),
  whatToOrderOptions: () => call<{ warehouses: Option[]; suppliers: Option[] }>('/office-api/what-to-order/options'),
  whatToOrder: (warehouse: string, supplier: string, changes: Record<string, OrderChange>) =>
    call<OrderList>('/office-api/what-to-order', { method: 'POST', body: JSON.stringify({ warehouse: warehouse || null, supplier: supplier || null, changes }) }),
  whatToOrderLine: (product: string) => call<{ suppliers: Option[]; units: Option[]; supplier_units: Record<string, string> }>(`/office-api/what-to-order/line?product=${encodeURIComponent(product)}`),
  whatToOrderLevels: (body: { warehouse: string; product: string; min: string; max: string }) =>
    call<{ id: string }>('/office-api/what-to-order/levels', { method: 'POST', body: JSON.stringify(body) }),
  whatToOrderMoves: (warehouse: string, products: string[]) =>
    call<{ requests: string[] }>('/office-api/what-to-order/moves', { method: 'POST', body: JSON.stringify({ warehouse, products }) }),
  whatToOrderDrafts: (warehouse: string, products: string[], changes: Record<string, OrderChange>) =>
    call<{ orders: { id: string; number: string }[] }>('/office-api/what-to-order/drafts', { method: 'POST', body: JSON.stringify({ warehouse, products, changes }) }),
  suggestedLevels: (warehouse: string) => call<{ warehouses: Option[]; rows: SuggestedRow[] }>(`/office-api/suggested-levels?warehouse=${encodeURIComponent(warehouse)}`),
  recalculateLevels: () => call<{ suggested: number }>('/office-api/suggested-levels/recalculate', { method: 'POST' }),
  acceptLevels: (ids: string[]) => call<{ accepted: number }>('/office-api/suggested-levels/accept', { method: 'POST', body: JSON.stringify({ ids }) }),
  expenseClaims: (query: string, signal?: AbortSignal) => call<EcList>(`/office-api/expense-claims?${query}`, { signal }),
  expenseClaimsOptions: () => call<{ statuses: Option[]; per: number[]; can_create: boolean }>('/office-api/expense-claims/options'),
  expenseClaim: (id: string) => call<EcDetail>(`/office-api/expense-claims/${id}`),
  expenseClaimForm: () => call<EcForm>('/office-api/expense-claims/form'),
  /** New claim, sent as a form (the receipt photo goes with it). */
  saveExpenseClaim: async (body: FormData) => {
    const res = await fetch('/office-api/expense-claims', { method: 'POST', body, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() } })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new ApiError(json.detail ?? json.message ?? 'The claim could not be saved.', res.status, json.error ?? 'error')
    return json as { id: string }
  },
  /** approve · reject (reason) · pay (account_id, paid_on); ApiError code "reconfirm" (with its url) = confirm it is you first. */
  expenseClaimAction: (id: string, action: 'approve' | 'reject' | 'pay', body: Record<string, unknown> = {}) =>
    call<EcDetail>(`/office-api/expense-claims/${id}/action`, { method: 'POST', body: JSON.stringify({ action, ...body }) }),
  supplierBills: (query: string, signal?: AbortSignal) => call<SbList>(`/office-api/supplier-bills?${query}`, { signal }),
  supplierBillsOptions: () => call<SbOptions>('/office-api/supplier-bills/options'),
  supplierBill: (id: string) => call<SbDetail>(`/office-api/supplier-bills/${id}`),
  supplierBillForm: (scan: string | null, invoice: string | null) =>
    call<SbForm>(`/office-api/supplier-bills/form?${new URLSearchParams({ ...(scan ? { scan } : {}), ...(invoice ? { invoice } : {}) })}`),
  supplierBillable: (supplier: string) => call<SbBillable>(`/office-api/supplier-bills/billable?supplier=${encodeURIComponent(supplier)}`),
  sbAddSupplier: (body: { name: string; phone?: string; email?: string; code?: string }) =>
    call<{ id: string; label: string; existed: boolean }>('/office-api/supplier-bills/add-supplier', { method: 'POST', body: JSON.stringify(body) }),
  saveSupplierBill: (values: SbValues, scan: string | null, invoice: string | null) =>
    call<{ id: string }>('/office-api/supplier-bills', { method: 'POST', body: JSON.stringify({ values, scan, invoice }) }),
  supplierBillAction: (id: string, action: 'approve' | 'reject', reason?: string) =>
    call<SbDetail>(`/office-api/supplier-bills/${id}/action`, { method: 'POST', body: JSON.stringify({ action, reason }) }),
  /** Scan an invoice: the AI reads it into a draft bill (sent as a form, the file). */
  scanSupplierBill: async (branch: string, file: File) => {
    const body = new FormData()
    body.append('branch_id', branch)
    body.append('file', file)
    const res = await fetch('/office-api/supplier-bills/scan', { method: 'POST', body, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() } })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new ApiError(json.detail ?? json.message ?? 'The invoice could not be read.', res.status, json.error ?? 'error')
    return json as { scan: string }
  },
  supplierPayments: (query: string, signal?: AbortSignal) => call<SpList>(`/office-api/supplier-payments?${query}`, { signal }),
  supplierPaymentsOptions: () => call<SpOptions>('/office-api/supplier-payments/options'),
  agedPayables: () => call<Aging>('/office-api/aged-payables'),
  payPreview: (supplier: string, amount: string, withhold: boolean, wht: string) =>
    call<{ text: string }>(`/office-api/supplier-payments/preview?${new URLSearchParams({ supplier, amount, withhold: withhold ? '1' : '0', wht })}`),
  /** Pay a supplier; ApiError code "reconfirm" (with its url) = confirm it is you first, then pay again. */
  paySupplier: (body: Record<string, unknown>) => call<{ payment: SpRow }>('/office-api/supplier-payments', { method: 'POST', body: JSON.stringify(body) }),
  withholdingTax: (month: string) => call<WhtMonth>(`/office-api/withholding-tax?month=${encodeURIComponent(month)}`),
  financialReport: (query: string, signal?: AbortSignal) => call<FrReport>(`/office-api/financial-reports?${query}`, { signal }),
  insights: (query: string, signal?: AbortSignal) => call<InsightsView>(`/office-api/insights?${query}`, { signal }),
  builderOptions: () => call<RbOptions>('/office-api/report-builder/options'),
  runReport: (choices: RbChoices, drill: Record<string, string> | null) => call<RbResult>('/office-api/report-builder/run', { method: 'POST', body: JSON.stringify({ ...choices, drill }) }),
  savedReports: (q: string) => call<{ rows: SavedReportRow[] }>(`/office-api/saved-reports?q=${encodeURIComponent(q)}`),
  savedReport: (id: string) => call<{ report: SavedReportRow; choices: RbChoices }>(`/office-api/saved-reports/${id}`),
  saveReport: (body: Record<string, unknown>) => call<{ report: SavedReportRow }>('/office-api/saved-reports', { method: 'POST', body: JSON.stringify(body) }),
  deleteReport: (id: string) => call<{ deleted: boolean }>(`/office-api/saved-reports/${id}`, { method: 'DELETE' }),
  sendReport: (id: string) => call<{ message: string }>(`/office-api/saved-reports/${id}/send`, { method: 'POST' }),
  shrink: (query: string, signal?: AbortSignal) => call<ShrinkView>(`/office-api/shrink?${query}`, { signal }),
  slowStock: (query: string, signal?: AbortSignal) => call<SlowView>(`/office-api/slow-stock?${query}`, { signal }),
  moveSlowStock: (warehouse: string, product: string, to: string) =>
    call<{ transfer: { id: string; number: string; open: string } }>('/office-api/slow-stock/move', { method: 'POST', body: JSON.stringify({ warehouse, product, to }) }),
  batches: (warehouse: string) => call<BatchesView>(`/office-api/batches?warehouse=${encodeURIComponent(warehouse)}`),
  batchProducts: (q: string) => call<{ products: Option[] }>(`/office-api/batches/products?q=${encodeURIComponent(q)}`),
  productBatches: (warehouse: string, product: string) => call<{ batches: Option[] }>(`/office-api/batches/product-batches?${new URLSearchParams({ warehouse, product })}`),
  applyMarkdown: (warehouse: string, batch: string, percent: string) => call<{ message: string }>('/office-api/batches/markdowns', { method: 'POST', body: JSON.stringify({ warehouse, batch, percent }) }),
  withdrawMarkdown: (id: string) => call<{ message: string }>(`/office-api/batches/markdowns/${id}/withdraw`, { method: 'POST' }),
  correctExpiry: (batch: string, expires_on: string, reason: string) => call<{ message: string }>('/office-api/batches/correct', { method: 'POST', body: JSON.stringify({ batch, expires_on, reason }) }),
  addBatch: (body: Record<string, unknown>) => call<{ adjustment: { id: string; number: string; open: string } }>('/office-api/batches/add', { method: 'POST', body: JSON.stringify(body) }),
  returns: (query: string, signal?: AbortSignal) => call<ReturnsList>(`/office-api/returns?${query}`, { signal }),
  returnsOptions: () => call<ReturnsOptions>('/office-api/returns/options'),
  journals: (query: string, signal?: AbortSignal) => call<JournalList>(`/office-api/journals?${query}`, { signal }),
  journalOptions: () => call<JournalOptions>('/office-api/journals/options'),
  journal: (id: string) => call<{ journal: JournalDetail }>(`/office-api/journals/${id}`),
  /** New journal or opening balances; ApiError code "reconfirm" (with its url) = confirm it is you first. */
  postJournal: (body: Record<string, unknown>) => call<{ journal: { id: string; number: string; open: string } }>('/office-api/journals', { method: 'POST', body: JSON.stringify(body) }),
  reverseJournal: (id: string, date: string, reason: string) => call<{ journal: { id: string; number: string; open: string } }>(`/office-api/journals/${id}/reverse`, { method: 'POST', body: JSON.stringify({ date, reason }) }),
  postNow: () => call<{ posted: number; errors: string[]; message: string }>('/office-api/journals/post-now', { method: 'POST' }),
  accounts: (query: string, signal?: AbortSignal) => call<{ currency: string; exponent: number; rows: AccountRow[] }>(`/office-api/accounts?${query}`, { signal }),
  accountForm: () => call<AccountForm>('/office-api/accounts/form'),
  saveAccount: (id: string | null, body: Record<string, unknown>) => call<{ account: { id: string } }>(id ? `/office-api/accounts/${id}` : '/office-api/accounts', { method: 'POST', body: JSON.stringify(body) }),
  archiveAccount: (id: string, reason: string | null) => call<{ done: boolean }>(`/office-api/accounts/${id}/archive`, { method: 'POST', body: JSON.stringify(reason === null ? { restore: true } : { reason }) }),
  monthEnd: (year: string) => call<MonthEnd>(`/office-api/month-end?year=${encodeURIComponent(year)}`),
  setupYear: (year: number) => call<{ year: number }>('/office-api/month-end/year', { method: 'POST', body: JSON.stringify({ year }) }),
  closeMonth: (id: string) => call<{ message: string }>(`/office-api/month-end/${id}/close`, { method: 'POST' }),
  reopenMonth: (id: string, reason: string) => call<{ message: string }>(`/office-api/month-end/${id}/reopen`, { method: 'POST', body: JSON.stringify({ reason }) }),
  budget: (year: string, branch: string) => call<BudgetView>(`/office-api/budgets?${new URLSearchParams({ year, branch })}`),
  spread: (annual: string) => call<{ amounts: string[] }>(`/office-api/budgets/spread?annual=${encodeURIComponent(annual)}`),
  saveBudget: (year: number, branch: string, amounts: Record<string, string[]>) => call<{ saved: number; message: string }>('/office-api/budgets', { method: 'POST', body: JSON.stringify({ year, branch, amounts }) }),
  bankAccounts: (query: string, signal?: AbortSignal) => call<BankList>(`/office-api/bank-accounts?${query}`, { signal }),
  bankForms: () => call<BankForms>('/office-api/bank-accounts/forms'),
  saveBank: (id: string | null, body: Record<string, unknown>) => call<{ bank: { id: string } }>(id ? `/office-api/bank-accounts/${id}` : '/office-api/bank-accounts', { method: 'POST', body: JSON.stringify(body) }),
  archiveBank: (id: string, reason: string | null) => call<{ done: boolean }>(`/office-api/bank-accounts/${id}/archive`, { method: 'POST', body: JSON.stringify(reason === null ? { restore: true } : { reason }) }),
  importStatement: async (id: string, body: FormData) => {
    const res = await fetch(`/office-api/bank-accounts/${id}/import`, { method: 'POST', body, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() } })
    const json = await res.json().catch(() => ({}))
    if (!res.ok) throw new ApiError(json.detail ?? json.message ?? 'The statement could not be read.', res.status, json.error ?? 'error')
    return json as { added: number; skipped: number; message: string }
  },
  moveMoney: (body: Record<string, unknown>) => call<{ transfer: { id: string }; message: string }>('/office-api/money-moved', { method: 'POST', body: JSON.stringify(body) }),
  moneyMoved: (query: string, signal?: AbortSignal) => call<MoneyMoveList>(`/office-api/money-moved?${query}`, { signal }),
  reconcile: (id: string, asAt: string) => call<Reconcile>(`/office-api/bank-accounts/${id}/reconcile?as_at=${encodeURIComponent(asAt)}`),
  reconcileStep: (id: string, step: 'match' | 'auto' | 'earlier' | 'post' | 'ignore' | 'unmatch', body: Record<string, unknown> = {}) =>
    call<{ message: string }>(`/office-api/bank-accounts/${id}/reconcile/${step}`, { method: 'POST', body: JSON.stringify(body) }),
  fixedAssets: (query: string, signal?: AbortSignal) => call<AssetList>(`/office-api/fixed-assets?${query}`, { signal }),
  fixedAssetOptions: () => call<AssetOptions>('/office-api/fixed-assets/options'),
  fixedAsset: (id: string) => call<{ asset: AssetDetail }>(`/office-api/fixed-assets/${id}`),
  registerAsset: (body: Record<string, unknown>) => call<{ asset: { id: string; number: string; open: string } }>('/office-api/fixed-assets', { method: 'POST', body: JSON.stringify(body) }),
  disposeAsset: (id: string, body: Record<string, unknown>) => call<{ message: string }>(`/office-api/fixed-assets/${id}/dispose`, { method: 'POST', body: JSON.stringify(body) }),
  assetKinds: () => call<AssetKinds>('/office-api/asset-kinds'),
  saveAssetKind: (id: string | null, body: Record<string, unknown>) => call<{ class: { id: string } }>(id ? `/office-api/asset-kinds/${id}` : '/office-api/asset-kinds', { method: 'POST', body: JSON.stringify(body) }),
  graEvat: (query: string, signal?: AbortSignal) => call<EvatList>(`/office-api/gra-evat?${query}`, { signal }),
  graEvatOptions: () => call<EvatOptions>('/office-api/gra-evat/options'),
  graEvatReport: (id: string) => call<{ report: EvatDetail }>(`/office-api/gra-evat/${id}`),
  graEvatSend: (id: string) => call<{ report: EvatDetail; message: string }>(`/office-api/gra-evat/${id}/send`, { method: 'POST' }),
  graEvatSendWaiting: () => call<{ sent: number; message: string }>('/office-api/gra-evat/send-waiting', { method: 'POST' }),
  accountingExport: () => call<AccountingExportOptions>('/office-api/accounting-export'),
  accountingExportUrl: (format: string, from: string, to: string) => `/office-api/accounting-export/file?format=${encodeURIComponent(format)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
  users: (query: string, signal?: AbortSignal) => call<UserList>(`/office-api/users?${query}`, { signal }),
  userOptions: () => call<UserOptions>('/office-api/users/options'),
  user: (id: string) => call<{ user: UserDetail }>(`/office-api/users/${id}`),
  saveUser: (id: string | null, body: Record<string, unknown>) => call<UserDone>(id ? `/office-api/users/${id}` : '/office-api/users', { method: 'POST', body: JSON.stringify(body) }),
  userAction: (id: string, action: 'pin' | 'card' | 'card-lost' | 'reset-two-step' | 'archive' | 'restore' | 'access', body: Record<string, unknown> = {}) =>
    call<UserDone>(`/office-api/users/${id}/${action}`, { method: 'POST', body: JSON.stringify(body) }),
  revokeAccess: (id: string, grant: string, reason: string) => call<UserDone>(`/office-api/users/${id}/access/${grant}/revoke`, { method: 'POST', body: JSON.stringify({ reason }) }),
  roles: (query: string, signal?: AbortSignal) => call<RoleList>(`/office-api/roles?${query}`, { signal }),
  roleForm: () => call<RoleForm>('/office-api/roles/form'),
  role: (id: string) => call<{ role: RoleDetail }>(`/office-api/roles/${id}`),
  saveRole: (id: string | null, body: Record<string, unknown>) => call<UserDone & { role?: { id: string; open: string } }>(id ? `/office-api/roles/${id}` : '/office-api/roles', { method: 'POST', body: JSON.stringify(body) }),
  roleAction: (id: string, action: 'archive' | 'restore', body: Record<string, unknown> = {}) => call<UserDone>(`/office-api/roles/${id}/${action}`, { method: 'POST', body: JSON.stringify(body) }),
  accessRequests: (query: string, signal?: AbortSignal) => call<AccessRequestList>(`/office-api/access-requests?${query}`, { signal }),
  decideRequest: (id: string, step: 'approve' | 'reject' | 'withdraw', note: string | null = null) =>
    call<UserDone & { failed?: boolean }>(`/office-api/access-requests/${id}/${step}`, { method: 'POST', body: JSON.stringify({ note }) }),
  companyWide: (query: string, signal?: AbortSignal) => call<WideList>(`/office-api/company-wide-access?${query}`, { signal }),
  narrowAccess: (id: string, scope_type: string, scope_id: string) => call<UserDone>(`/office-api/company-wide-access/${id}/narrow`, { method: 'POST', body: JSON.stringify({ scope_type, scope_id }) }),
  accessReview: () => call<AccessReviewData>('/office-api/who-can-do-what'),
  itActivity: (query: string, signal?: AbortSignal) => call<ActivityList>(`/office-api/it-activity?${query}`, { signal }),
  staffCards: (query: string, signal?: AbortSignal) => call<CardList>(`/office-api/staff-cards?${query}`, { signal }),
  cardsAction: (action: 'print' | 'stop', body: Record<string, unknown>) => call<UserDone>(`/office-api/staff-cards/${action}`, { method: 'POST', body: JSON.stringify(body) }),
  settings: () => call<SettingsData>('/office-api/settings'),
  saveSettings: (values: Record<string, unknown>, reason: string | null) => call<UserDone & { saved: number }>('/office-api/settings', { method: 'POST', body: JSON.stringify({ values, reason }) }),
  modules: (branch: string | null) => call<ModulesData>(`/office-api/modules${branch ? `?branch=${encodeURIComponent(branch)}` : ''}`),
  saveModules: (branch_id: string, modules: Record<string, string>, reason: string | null) => call<UserDone>('/office-api/modules', { method: 'POST', body: JSON.stringify({ branch_id, modules, reason }) }),
  messagingSetup: () => call<MessagingSetupData>('/office-api/messaging'),
  saveProvider: (channel: 'sms' | 'whatsapp', body: Record<string, unknown>) => call<UserDone>(`/office-api/messaging/${channel}`, { method: 'POST', body: JSON.stringify(body) }),
  testMessage: (channel: 'sms' | 'whatsapp' | 'email', to: string) => call<UserDone & { sent: boolean }>(`/office-api/messaging/${channel}/test`, { method: 'POST', body: JSON.stringify({ to }) }),
  messageTemplates: (query: string, signal?: AbortSignal) => call<TemplateList>(`/office-api/message-templates?${query}`, { signal }),
  saveTemplate: (id: string, body: Record<string, unknown>) => call<UserDone>(`/office-api/message-templates/${id}`, { method: 'POST', body: JSON.stringify(body) }),
  templateArchive: (id: string, step: 'archive' | 'restore', reason: string | null = null) => call<UserDone>(`/office-api/message-templates/${id}/${step}`, { method: 'POST', body: JSON.stringify({ reason }) }),
  messageLog: (query: string, signal?: AbortSignal) => call<MessageLog>(`/office-api/message-log?${query}`, { signal }),
  retryMessage: (id: string) => call<UserDone & { row: MessageRow }>(`/office-api/message-log/${id}/retry`, { method: 'POST' }),
  devices: (query: string, signal?: AbortSignal) => call<DeviceList>(`/office-api/devices?${query}`, { signal }),
  deviceOptions: (branch: string | null) => call<DeviceOptions>(`/office-api/devices/options${branch ? `?branch=${encodeURIComponent(branch)}` : ''}`),
  registerDevice: (body: Record<string, unknown>) => call<UserDone & { code: string; expires: string | null }>('/office-api/devices', { method: 'POST', body: JSON.stringify(body) }),
  deviceAction: (id: string, action: 'new-code' | 'revoke' | 'fix' | 'archive' | 'restore', body: Record<string, unknown> = {}) =>
    call<UserDone & { code?: string; expires?: string | null }>(`/office-api/devices/${id}/${action}`, { method: 'POST', body: JSON.stringify(body) }),
  deviceFixes: (id: string) => call<{ rows: DeviceFix[] }>(`/office-api/devices/${id}/fixes`),
  computers: (query: string, signal?: AbortSignal) => call<ComputerList>(`/office-api/computers?${query}`, { signal }),
  nameComputer: (id: string, name: string) => call<UserDone>(`/office-api/computers/${id}/name`, { method: 'POST', body: JSON.stringify({ name }) }),
  refList: (kind: RefKind, query: string, signal?: AbortSignal) => call<RefList>(`/office-api/lists/${kind}?${query}`, { signal }),
  refOptions: (kind: RefKind) => call<RefOptions>(`/office-api/lists/${kind}/options`),
  refSave: (kind: RefKind, id: string | null, body: Record<string, unknown>) => call<UserDone & { id: string }>(`/office-api/lists/${kind}${id ? `/${id}` : ''}`, { method: 'POST', body: JSON.stringify(body) }),
  refAction: (kind: RefKind, id: string, action: 'delete' | 'move' | 'merge' | 'verify' | 'archive' | 'restore', body: Record<string, unknown> = {}) =>
    call<UserDone>(`/office-api/lists/${kind}/${id}/${action}`, { method: 'POST', body: JSON.stringify(body) }),
  pricingRules: (query: string, signal?: AbortSignal) => call<PricingRuleList>(`/office-api/pricing-rules?${query}`, { signal }),
  pricingOptions: () => call<PricingRuleOptions>('/office-api/pricing-rules/options'),
  pricingProducts: (q: string) => call<{ rows: Option[] }>(`/office-api/pricing-rules/products?q=${encodeURIComponent(q)}`),
  saveRule: (id: string | null, body: Record<string, unknown>) => call<UserDone & { id: string }>(id ? `/office-api/pricing-rules/${id}` : '/office-api/pricing-rules', { method: 'POST', body: JSON.stringify(body) }),
  applyRules: () => call<UserDone>('/office-api/pricing-rules/apply-all', { method: 'POST' }),
  ruleArchive: (id: string, step: 'archive' | 'restore', reason: string | null = null) => call<UserDone>(`/office-api/pricing-rules/${id}/${step}`, { method: 'POST', body: JSON.stringify({ reason }) }),
  pricesToReview: (query: string, signal?: AbortSignal) => call<SuggestionList>(`/office-api/prices-to-review?${query}`, { signal }),
  decidePrices: (step: 'approve' | 'keep', ids: string[]) => call<UserDone>(`/office-api/prices-to-review/${step}`, { method: 'POST', body: JSON.stringify({ ids }) }),
  promotions: (query: string, signal?: AbortSignal) => call<PromotionList>(`/office-api/promotions?${query}`, { signal }),
  promotionOptions: () => call<PromotionOptions>('/office-api/promotions/options'),
  promotionProducts: (q: string) => call<{ rows: Option[] }>(`/office-api/promotions/products?q=${encodeURIComponent(q)}`),
  promotion: (id: string) => call<{ id: string; values: PromotionValues }>(`/office-api/promotions/${id}`),
  savePromotion: (id: string | null, body: Record<string, unknown>) => call<UserDone & { id: string }>(id ? `/office-api/promotions/${id}` : '/office-api/promotions', { method: 'POST', body: JSON.stringify(body) }),
  promotionArchive: (id: string, step: 'archive' | 'restore', reason: string | null = null) => call<UserDone>(`/office-api/promotions/${id}/${step}`, { method: 'POST', body: JSON.stringify({ reason }) }),
  financialOptions: () => call<FrOptions>('/office-api/financial-reports/options'),
  shareHeadOffice: (to: string) => call<{ message: string }>('/office-api/financial-reports/share-head-office', { method: 'POST', body: JSON.stringify({ to }) }),
  withholdingCsvUrl: (month: string) => `/office-api/withholding-tax/csv?month=${encodeURIComponent(month)}`,
  invoices: (query: string, signal?: AbortSignal) => call<InvoiceList>(`/office-api/invoices?${query}`, { signal }),
  invoicesOptions: () => call<InvoiceOptions>('/office-api/invoices/options'),
  invoice: (id: string) => call<InvoiceDetail>(`/office-api/invoices/${id}`),
  /** Credit note; ApiError code "reconfirm" (with its url) = confirm it is you first, then try again. */
  creditInvoice: (id: string, reason: string, warehouse_id: string | null) =>
    call<InvoiceDetail>(`/office-api/invoices/${id}/credit`, { method: 'POST', body: JSON.stringify({ reason, warehouse_id }) }),
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
