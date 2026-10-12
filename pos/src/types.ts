import type { TaxLine, TaxRate } from './money'

export type Unit = { id: string; uom_code: string; uom_name: string; decimals: number; factor: string; is_base: boolean; barcodes: string[] }
export type Price = { price_list_id: string; product_uom_id: string; min_qty: string; amount_minor: number; starts_at: string | null; ends_at: string | null }
export type Markdown = { id: string; code: string; product_id: string; product_uom_id: string; price_minor: number; percent_bp: number; valid_until: string }
export type Grocery = { scale_prefixes: string[]; markdown_prefix: string; markdowns: Markdown[] }
export type Drug = { schedule: 'otc' | 'pom' | 'controlled'; generic_name: string | null; strength: string | null; dosage_form: string | null; active_ingredients: string[] }
export type Interaction = { ingredient_a: string; ingredient_b: string; severity: 'minor' | 'moderate' | 'major'; note: string }
export type Pharmacy = { interactions: Interaction[] }
// Restaurant
export type ModifierGroup = { id: string; name: string; min: number; max: number; options: { id: string; name: string; product_id: string | null }[] }
export type Restaurant = { modifier_groups: ModifierGroup[]; service_charge: { product_id: string; bp: number } | null; tips: boolean }
export type DiningItem = {
  id: string; product_id: string; product_uom_id: string; name: string; qty: string; modifiers: { group: string; option_id: string; name: string; product_id: string | null }[]
  note: string | null; course: number; seat: number | null; station: string | null; status: 'held' | 'fired' | 'ready' | 'served' | 'void'; paid: boolean
  added_by: string; fired_at: string | null; ready_at: string | null; void_reason: string | null
}
export type DiningOrder = {
  id: string; number: string; kind: 'table' | 'tab' | 'counter' | 'delivery'; label: string; dining_table_id: string | null; tab_name: string | null; covers: number
  customer_id: string | null; waiter: string; status: 'open' | 'closed' | 'void'; note: string | null; opened_at: string; version: number; items: DiningItem[]
  platform?: string | null; external_ref?: string | null; reservation?: { number: string; deposit_left_minor: number } | null
}
// Fashion
export type Style = { id: string; code: string; name: string; sizes: string[]; colours: string[]; season: string | null; collection: string | null }
export type Fashion = { styles: Style[]; alteration_product_id: string | null }
export type Alteration = {
  id: string; number: string; customer_id: string | null; customer_name: string; phone: string | null; garment: string; work: string; charge_minor: number
  status: string; status_label: string; due_on: string | null; tailor_name: string | null; received_at: string
}
// Services
export type StaffMember = { id: string; name: string; job_title: string | null }
export type Services = { staff: StaffMember[]; slot_minutes: number; day_start: string; day_end: string }
export type Appointment = {
  id: string; number: string; customer_id: string | null; customer_name: string; phone: string | null; employee_id: string; employee_name: string | null
  product_id: string; service: string | null; starts_at: string; ends_at: string; status: 'booked' | 'arrived' | 'done' | 'no_show' | 'cancelled'; status_label: string; note: string | null
}
export type PassItem = { label: string; product_ids: string[]; sessions: number | null; remaining: number | null }
export type ServicePass = { id: string; number: string; name: string; expires_on: string | null; items: PassItem[] }
// Electronics
export type RepairJob = {
  id: string; number: string; customer_id: string | null; customer_name: string; phone: string | null; device: string; serial: string | null; fault: string
  accessories: string | null; status: string; status_label: string; in_warranty: boolean; estimate_minor: number | null; promised_on: string | null; received_at: string
  rma_reference: string | null; log: { at: string; by: string; status: string; note: string | null }[]
  lines: { product_id: string; product_uom_id: string; name: string; qty: string; unit_price_minor: number }[]
}
export type SerialInfo = {
  serial: string; product_id: string; product: string | null; status: string; status_label: string; in_stock_here: boolean; sold_at: string | null
  receipt_no: string | null; customer: string | null; warranty_until: string | null; in_warranty: boolean
}
export type TradeInItem = { product_id: string; name: string; serial: string | null; condition: string | null; value_minor: number }
export type Reservation = {
  id: string; number: string; guest_name: string; phone: string | null; party_size: number; starts_at: string; duration_minutes: number
  dining_table_id: string | null; customer_id: string | null; note: string | null; status: 'booked' | 'seated' | 'no_show' | 'cancelled'
  deposit_minor: number; deposit_left_minor: number; deposit_status: string; dining_order_id: string | null
}
export type DiningTable = { id: string; code: string; area: string | null; seats: number; pos_x: number; pos_y: number }
export type Floor = { tables: DiningTable[]; stations: { code: string; name: string; auto_print: boolean }[]; orders: DiningOrder[]; server_time: string
  reservations?: Reservation[]; hold_minutes?: number }
export type KitchenTicket = { order_id: string; number: string; label: string; kind: string; waiter: string; paid: boolean; items: DiningItem[] }
export type Product = { id: string; sku: string; name: string; category_id?: string | null; plu?: string | null; drug?: Drug | null; station?: string | null; modifier_group_ids?: string[]; track_serials?: boolean; warranty_months?: number | null; style_id?: string | null; size?: string | null; colour?: string | null; service_minutes?: number | null; is_pack?: boolean; licence_keys?: boolean; is_container?: boolean; deposit_product_id?: string | null; deposit_per_unit?: string | null; track_stock: boolean; on_hand: string | null; tax: TaxRate[]; units: Unit[]; prices: Price[] }
export type PriceList = { id: string; code: string; name: string; prices_include_tax: boolean; is_default: boolean }

export type Shift = {
  id: string; status: 'open' | 'closed'; opened_at: string; opened_by: string; opening_float_minor: number
  expected_cash_minor: number | null; counted_cash_minor: number | null; over_short_minor: number | null
}

// Distribution: the van behind this till, today's route and the rep's month
export type VanDay = {
  van: { id: string; code: string; name: string; vehicle_reg: string | null }
  routes: { id: string; code: string; name: string }[]
  stops: { route_id: string; customer_id: string; sequence: number }[]
  weekday: number
  month: { target_minor: number | null; sold_minor: number; visit_target: number | null }
}
export type VisitOutcome = 'sold' | 'order' | 'no_order' | 'closed'

/** A note or coin to count at close (key keeps a 2-cedi note apart from the 2-cedi coin). */
export type Denomination = { key: string; label: string; value: number }

/** A vault (cash tray with its own float) the cashier issued to this teller for one shift. */
export type VaultIssue = { id: string; vault: string; float_minor: number; status: 'issued' | 'open'; issued_by: string; business_date: string }

export type CashInfo = { vaults: boolean; sessions: VaultIssue[]; denominations: Denomination[] }

export type Bootstrap = {
  company: { name: string; currency: string; exponent: number; timezone: string; legal_name: string | null; tin: string | null; vat_no: string | null; practice?: boolean }
  branch: { id: string; code: string; name: string; address: string | null; phone: string | null }
  till: { id: string; code: string; name: string; warehouse: string } | null
  device: { id: string; name: string; receipt_series: string | null }
  user: { id: string; name: string }
  price_lists: PriceList[]
  receipt_footer: string
  /** The company's brand (Company → Branding); missing on servers older than 0.26. */
  brand?: { color: string; on_color: string; logo_url: string | null; logo_dark_url: string | null; icon_url: string; receipt_logo: boolean; powered_by: string | null; product: string }
  send_receipt?: ('sms' | 'whatsapp' | 'email')[] // channels the business has switched on for digital receipts
  license?: { state: string; read_only: boolean; message: string | null; ref: string }
  shift: Shift | null
  /** Cash control: vaults issued to this teller at this till, and the notes and coins to count (missing on servers before 0.30). */
  cash?: CashInfo
  last_device_seq: number
  catalog_version: string
  realtime?: Realtime | null
  van?: VanDay | null
  server_time: string
}

export type Realtime = { key: string; host: string; port: number; scheme: 'http' | 'https'; channels: (string | null)[] }

export type Payment = { method: 'cash' | 'momo' | 'card' | 'account' | 'points' | 'gift_card' | 'store_credit' | 'deposit' | 'aggregator' | 'trade_in'; provider?: string; amount_minor: number; tendered_minor: number; reference?: string; payer_phone?: string }

export type PostedSale = {
  id: string; receipt_no: string; device_seq: number; status: string; occurred_at: string; cashier: string; currency: string
  prices_include_tax: boolean; net_minor: number; tax_minor: number; total_minor: number; paid_minor: number; change_minor: number
  tax_breakdown: TaxLine[]
  lines: { line_no: number; sku: string; name: string; uom: string; qty: string; unit_price_minor: number; line_total_minor: number; discount_minor?: number; promotions?: { name: string; saving_minor: number }[]; note?: string | null; serials?: string[]; warranty_until?: string | null; id?: string; staff_id?: string | null; service_pass_id?: string | null }[]
  tip_minor?: number; order_number?: string | null
  licence_keys?: { sale_line_id: string; key: string }[] // Services: software keys handed out, printed on the receipt
  fiscal?: { status: string; code: string | null; qr: string | null; mode: string } | null // GRA E-VAT
  kitchen?: { name: string; qty: string; note: string | null; station: string }[] // offline counter sale: ticket the till prints itself
  kind?: 'sale' | 'return'; discount_minor?: number; points_earned?: number
  payments: { method: string; provider: string | null; amount_minor: number; tendered_minor: number; reference: string | null; status: string }[]
}

// Warehouse handheld
export type WmsBin = { id: string; code: string; name: string | null; kind?: string }
export type WmsWarehouse = { id: string; code: string; name: string; use_bins: boolean; can: string[]; bins: WmsBin[] }
export type WmsBootstrap = { user: { id: string; name: string }; device: { id: string; name: string; kind: string }; branch: { id: string; code: string; name: string }; warehouses: WmsWarehouse[] }
export type WmsUnit = { product_uom_id: string; product_id: string; sku: string; name: string; uom_code: string; factor: string; decimals: number; units: { product_uom_id: string; uom_code: string; factor: string }[] }
export type WmsOrderLine = { id: string; product_uom_id: string; sku: string; name: string; uom_code: string; ordered: string; received: string; barcodes: string[]; track_batches?: boolean; track_serials?: boolean }
export type WmsOrder = { id: string; number: string; supplier: string; expected_on: string | null; lines: WmsOrderLine[] }
export type WmsTransfer = { id: string; number: string; from: string; dispatched_at: string | null; lines: { id: string; sku: string; name: string; uom_code: string; qty: string; product_uom_id: string; barcodes: string[] }[] }
export type WmsCount = { id: string; number: string; bin: string | null; bin_id: string | null; blind: boolean; counted: number; lines: number }

// Customer lookup at the till (credit figures are a snapshot; the server re-checks online credit sales)
export type TillCustomer = { id: string; code: string; name: string; phone: string | null; price_list_id: string | null; credit_limit_minor: number | null; credit_hold: boolean; balance_minor: number; available_minor: number | null; overdue_minor: number; points?: number
  allergies?: string[]; nhis_no?: string | null; nhis_expires_on?: string | null } // patient details: pharmacy staff only

// Promotions as the till caches them (the engine input plus when/where they run)
export type PromotionRow = import('./promotions').Promotion & {
  starts_at: string | null; ends_at: string | null; days: number[]; time_from: string | null; time_to: string | null; branch_ids: string[]; price_list_ids: string[]
}
export type Returnable = {
  within_period: boolean
  lines: { id: string; product_id: string; product_uom_id: string; name: string; sku: string; uom: string; qty: string; line_total_minor: number; returnable_qty: string }[]
  tenders: { method: string; paid_minor: number; refundable_minor: number; reference: string | null }[]
}
