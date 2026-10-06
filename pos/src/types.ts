import type { TaxLine, TaxRate } from './money'

export type Unit = { id: string; uom_code: string; uom_name: string; decimals: number; factor: string; is_base: boolean; barcodes: string[] }
export type Price = { price_list_id: string; product_uom_id: string; min_qty: string; amount_minor: number; starts_at: string | null; ends_at: string | null }
export type Product = { id: string; sku: string; name: string; track_stock: boolean; on_hand: string | null; tax: TaxRate[]; units: Unit[]; prices: Price[] }
export type PriceList = { id: string; code: string; name: string; prices_include_tax: boolean; is_default: boolean }

export type Shift = {
  id: string; status: 'open' | 'closed'; opened_at: string; opened_by: string; opening_float_minor: number
  expected_cash_minor: number | null; counted_cash_minor: number | null; over_short_minor: number | null
}

export type Bootstrap = {
  company: { name: string; currency: string; exponent: number; timezone: string; legal_name: string | null; tin: string | null; vat_no: string | null }
  branch: { id: string; code: string; name: string; address: string | null; phone: string | null }
  till: { id: string; code: string; name: string; warehouse: string } | null
  device: { id: string; name: string; receipt_series: string | null }
  user: { id: string; name: string }
  price_lists: PriceList[]
  receipt_footer: string
  shift: Shift | null
  last_device_seq: number
  catalog_version: string
  realtime?: Realtime | null
  server_time: string
}

export type Realtime = { key: string; host: string; port: number; scheme: 'http' | 'https'; channels: (string | null)[] }

export type Payment = { method: 'cash' | 'momo' | 'card'; provider?: string; amount_minor: number; tendered_minor: number; reference?: string; payer_phone?: string }

export type PostedSale = {
  id: string; receipt_no: string; device_seq: number; status: string; occurred_at: string; cashier: string; currency: string
  prices_include_tax: boolean; net_minor: number; tax_minor: number; total_minor: number; paid_minor: number; change_minor: number
  tax_breakdown: TaxLine[]
  lines: { line_no: number; sku: string; name: string; uom: string; qty: string; unit_price_minor: number; line_total_minor: number }[]
  payments: { method: string; provider: string | null; amount_minor: number; tendered_minor: number; reference: string | null; status: string }[]
}
