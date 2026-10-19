import { clientHeader } from './clientInfo'
import { db, getDevice, getSession } from './db'
import type { OfflineGrant } from './db'
import type { Bootstrap, PostedSale, Product, PromotionRow, Returnable, Shift, TillCustomer, WmsBin, WmsBootstrap, WmsCount, WmsOrder, WmsTransfer, WmsUnit, Grocery, Pharmacy, Restaurant, DiningOrder, Floor, KitchenTicket, Reservation, RepairJob, SerialInfo, Fashion, Alteration, Services, Appointment, ServicePass, StaffMember, VanDay } from './types'

/** Error with the server's stable code ("price_changed", "no_shift"…) and a message safe to show. */
export class ApiError extends Error {
  code: string
  status: number

  constructor(code: string, message: string, status: number) {
    super(message)
    this.code = code
    this.status = status
  }
}

export const GATEWAY_DOWN = [502, 503, 504]
export const SIGNED_OUT = 'Your sign-in on this till has ended. Enter your username and PIN to carry on; the sale is kept.'

async function call<T>(path: string, init: RequestInit & { auth?: 'device' | 'session' | 'none' } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' }
  const auth = init.auth ?? 'session'
  if (auth === 'device') headers.Authorization = `Bearer ${(await getDevice())?.token ?? ''}`
  if (auth === 'session') headers.Authorization = `Bearer ${(await getSession())?.token ?? ''}`
  const client = await clientHeader() // the desktop till's computer name and network card, for the audit log
  if (client) headers['X-SES-Client'] = client

  let res: Response
  try {
    res = await fetch(`/api/v1/${path}`, { ...init, headers })
  } catch {
    throw new ApiError('network', 'Cannot reach the server. Check the network cable or Wi-Fi.', 0)
  }
  // A proxy in front of a stopped app server answers 502/503/504: for the till that is the server being down.
  if (GATEWAY_DOWN.includes(res.status)) throw new ApiError('network', 'The server is not answering.', res.status)
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401 && auth === 'session') await db.kv.delete('session')
    if (res.status === 401 && auth === 'session' && !body.error) throw new ApiError('signed_out', SIGNED_OUT, 401)
    const detail = body.detail ?? body.message ?? `Server error (${res.status})`
    throw new ApiError(body.error ?? (res.status === 422 ? 'validation' : 'server'), detail, res.status)
  }
  return body as T
}

export const api = {
  enroll: (code: string, publicKey: string) =>
    call<{ device_id: string; token: string; kind: string }>('devices/enroll', { method: 'POST', auth: 'none', body: JSON.stringify({ code, public_key: publicKey }) }),
  clock: (id: string, username: string, pin: string, kind: 'in' | 'out') =>
    call<{ status: string; name: string; kind: string; at: string }>('pos/clock', { method: 'POST', auth: 'device', body: JSON.stringify({ id, username, pin, kind }) }),
  login: (username: string, pin: string) =>
    call<{ token: string; expires_at: string | null; user: { id: string; name: string }; offline_grant: OfflineGrant | null }>('pos/login', { method: 'POST', auth: 'device', body: JSON.stringify({ username, pin }) }),
  logout: () => call<{ ok: boolean }>('pos/logout', { method: 'POST' }),
  sendReceipt: (saleId: string, channel: 'sms' | 'whatsapp' | 'email', to: string) =>
    call<{ status: string; detail: string }>(`pos/sales/${saleId}/send`, { method: 'POST', body: JSON.stringify({ channel, to }) }),
  reportProblem: (message: string, screen: string, context: Record<string, unknown>) =>
    call<{ number: string; sent: boolean }>('pos/problems', { method: 'POST', body: JSON.stringify({ message, screen, context }) }),
  bootstrap: () => call<Bootstrap>('pos/bootstrap'),
  /** null = unchanged since the given version (HTTP 304), so the cached copy is current. */
  catalog: async (version?: string | null) => {
    const headers: Record<string, string> = { Accept: 'application/json', Authorization: `Bearer ${(await getSession())?.token ?? ''}` }
    if (version) headers['If-None-Match'] = `"${version}"`
    let res: Response
    try {
      res = await fetch('/api/v1/pos/catalog', { headers })
    } catch {
      throw new ApiError('network', 'Cannot reach the server.', 0)
    }
    if (res.status === 304) return null
    if (GATEWAY_DOWN.includes(res.status)) throw new ApiError('network', 'The server is not answering.', res.status)
    const body = await res.json().catch(() => ({}))
    if (!res.ok) throw new ApiError(body.error ?? 'server', body.detail ?? `Server error (${res.status})`, res.status)
    return body as { version: string; generated_at: string; products: Product[]; promotions?: PromotionRow[]; utc_offset_minutes?: number; grocery?: Grocery | null; pharmacy?: Pharmacy | null; restaurant?: Restaurant | null; electronics?: boolean; fashion?: Fashion | null; services?: Services | null }
  },
  /** Customers for lookup (ETag); null = unchanged. */
  customers: async (version?: string | null) => {
    const headers: Record<string, string> = { Accept: 'application/json', Authorization: `Bearer ${(await getSession())?.token ?? ''}` }
    if (version) headers['If-None-Match'] = `"${version}"`
    const res = await fetch('/api/v1/pos/customers', { headers }).catch(() => null)
    if (!res || res.status === 304 || !res.ok) return null
    return (await res.json()) as { version: string; customers: TillCustomer[] }
  },
  openShift: (floatMinor: number, id: string, vaultSessionId?: string | null) =>
    call<{ shift: Shift }>('pos/shifts/open', { method: 'POST', body: JSON.stringify({ opening_float_minor: floatMinor, id, vault_session_id: vaultSessionId ?? null }) }),
  closeShift: (countedMinor: number, note: string, denoms?: Record<string, number> | null) =>
    call<{ shift: Shift }>('pos/shifts/close', { method: 'POST', body: JSON.stringify({ counted_cash_minor: countedMinor, note: note || null, denoms: denoms ?? null }) }),
  postSale: (payload: unknown) => call<{ sale: PostedSale; replayed: boolean }>('pos/sales', { method: 'POST', body: JSON.stringify(payload) }),
  lookupReceipt: (receiptNo: string) => call<{ sale: PostedSale; returnable: Returnable; customer_id: string | null }>(`pos/receipts?receipt=${encodeURIComponent(receiptNo)}`),
  postReturn: (payload: unknown) => call<{ sale: PostedSale; store_credit_code: string | null }>('pos/returns', { method: 'POST', body: JSON.stringify(payload) }),
  accountPayment: (body: { customer_id: string; amount_minor: number; method: 'cash' | 'momo' | 'card'; provider?: string; reference?: string }) =>
    call<{ number: string; amount_minor: number; balance_minor: number }>('pos/account-payments', { method: 'POST', body: JSON.stringify(body) }),
}

export type NewDiningItem = { id: string; product_id: string; product_uom_id: string; qty: string; modifier_option_ids: string[]; note?: string | null; course: number; seat?: number | null; hold: boolean }

/** Restaurant bills, shared by tills and waiter tablets (signed-in staff). */
export const dining = {
  floor: () => call<Floor>('pos/restaurant/floor'),
  order: (id: string) => call<{ order: DiningOrder }>(`pos/restaurant/orders/${id}`),
  open: (body: { id: string; kind: 'table' | 'tab' | 'counter'; dining_table_id?: string | null; tab_name?: string | null; covers?: number; customer_id?: string | null }) =>
    call<{ order: DiningOrder }>('pos/restaurant/orders', { method: 'POST', body: JSON.stringify(body) }),
  addItems: (id: string, items: NewDiningItem[]) => call<{ order: DiningOrder }>(`pos/restaurant/orders/${id}/items`, { method: 'POST', body: JSON.stringify({ items }) }),
  fire: (id: string, course: number | null) => call<{ order: DiningOrder }>(`pos/restaurant/orders/${id}/fire`, { method: 'POST', body: JSON.stringify({ course }) }),
  void: (id: string, itemIds: string[], reason: string, approval?: { username: string; pin: string } | null) =>
    call<{ order: DiningOrder }>(`pos/restaurant/orders/${id}/void`, { method: 'POST', body: JSON.stringify({ item_ids: itemIds, reason, ...(approval ? { approval } : {}) }) }),
  serve: (id: string, itemIds: string[]) => call<{ order: DiningOrder }>(`pos/restaurant/orders/${id}/serve`, { method: 'POST', body: JSON.stringify({ item_ids: itemIds }) }),
  move: (id: string, tableId: string) => call<{ order: DiningOrder }>(`pos/restaurant/orders/${id}/move`, { method: 'POST', body: JSON.stringify({ dining_table_id: tableId }) }),
  close: (id: string) => call<{ order: DiningOrder }>(`pos/restaurant/orders/${id}/close`, { method: 'POST' }),
  openDelivery: (body: { id: string; platform: string; external_ref: string }) =>
    call<{ order: DiningOrder }>('pos/restaurant/orders', { method: 'POST', body: JSON.stringify({ ...body, kind: 'delivery' }) }),
  reservations: (date?: string) => call<{ reservations: Reservation[] }>(`pos/restaurant/reservations${date ? `?date=${date}` : ''}`),
  book: (body: Partial<Reservation> & { guest_name?: string; starts_at?: string }, id?: string) =>
    call<{ reservation: Reservation }>(`pos/restaurant/reservations${id ? `/${id}` : ''}`, { method: 'POST', body: JSON.stringify(body) }),
  deposit: (id: string, body: { method: 'cash' | 'momo' | 'card'; amount_minor: number; provider?: string; reference?: string }) =>
    call<{ reservation: Reservation }>(`pos/restaurant/reservations/${id}/deposit`, { method: 'POST', body: JSON.stringify(body) }),
  refund: (id: string, body: { method: 'cash' | 'momo' | 'card'; provider?: string; reference?: string; approval?: { username: string; pin: string } | null }) =>
    call<{ reservation: Reservation }>(`pos/restaurant/reservations/${id}/refund`, { method: 'POST', body: JSON.stringify(body) }),
  seat: (id: string, tableId?: string | null) => call<{ order: DiningOrder }>(`pos/restaurant/reservations/${id}/seat`, { method: 'POST', body: JSON.stringify({ dining_table_id: tableId ?? null }) }),
  noShow: (id: string) => call<{ reservation: Reservation }>(`pos/restaurant/reservations/${id}/no-show`, { method: 'POST' }),
  cancelBooking: (id: string, reason: string) => call<{ reservation: Reservation }>(`pos/restaurant/reservations/${id}/cancel`, { method: 'POST', body: JSON.stringify({ reason }) }),
  waste: (body: WasteBody) => call<WasteResult>('pos/restaurant/waste', { method: 'POST', body: JSON.stringify(body) }),
}

export type WasteBody = { items: { product_id: string; product_uom_id: string; qty: string }[]; reason: string; approval?: { username: string; pin: string } | null }
export type WasteResult = { value_minor: number; currency: string; lines: number; approved_by: string | null }

/** Electronics: serial / warranty lookup and repair job cards. */
export const repairs = {
  serial: (s: string) => call<{ serials: SerialInfo[] }>(`pos/serials/${encodeURIComponent(s)}`),
  list: (status: 'open' | 'recent' = 'open') => call<{ jobs: RepairJob[] }>(`pos/repairs?status=${status}`),
  open: (body: { customer_id?: string | null; customer_name: string; phone?: string | null; serial?: string | null; device?: string | null; fault: string; accessories?: string | null }) =>
    call<{ job: RepairJob }>('pos/repairs', { method: 'POST', body: JSON.stringify(body) }),
  status: (id: string, status: string, note?: string | null) => call<{ job: RepairJob }>(`pos/repairs/${id}/status`, { method: 'POST', body: JSON.stringify({ status, note }) }),
  lines: (id: string, lines: { product_uom_id: string; qty: string }[]) => call<{ job: RepairJob }>(`pos/repairs/${id}/lines`, { method: 'POST', body: JSON.stringify({ lines }) }),
  handOver: (id: string) => call<{ job: RepairJob }>(`pos/repairs/${id}/hand-over`, { method: 'POST' }),
}

/** Fashion: alteration jobs. */
export const alterations = {
  list: () => call<{ alterations: Alteration[]; product_id: string | null }>('pos/alterations'),
  open: (body: { customer_id?: string | null; customer_name: string; phone?: string | null; garment: string; work: string; charge_minor: number; due_on?: string | null }) =>
    call<{ alteration: Alteration }>('pos/alterations', { method: 'POST', body: JSON.stringify(body) }),
  status: (id: string, status: string, tailor?: string | null) => call<{ alteration: Alteration }>(`pos/alterations/${id}/status`, { method: 'POST', body: JSON.stringify({ status, tailor }) }),
  handOver: (id: string) => call<{ alteration: Alteration }>(`pos/alterations/${id}/hand-over`, { method: 'POST' }),
}

/** Distribution: the van's day, visits and orders taken on the road. */
export type VisitBody = { id: string; customer_id: string; route_id?: string | null; outcome: string; note?: string | null; lat?: number | null; lng?: number | null
  accuracy_m?: number | null; sale_id?: string | null; customer_order_id?: string | null; occurred_at: string }
export type OrderBody = { id: string; customer_id: string; note?: string | null; lines: { product_uom_id: string; qty: string }[] }
export const van = {
  today: () => call<{ van: VanDay | null }>('pos/van/today'),
  visit: (body: VisitBody) => call<{ status: string }>('pos/van/visits', { method: 'POST', body: JSON.stringify(body) }),
  order: (body: OrderBody) => call<{ status: string; order: { id: string; number: string; status: string; total_minor: number; hold_reason: string | null } }>(
    'pos/van/orders', { method: 'POST', body: JSON.stringify(body) }),
}

/** Services: the day's appointments by staff member, booking and changing them, and a customer's prepaid passes. */
export type BookingBody = { employee_id?: string; product_id?: string; starts_at?: string; customer_id?: string | null; customer_name?: string; phone?: string | null; note?: string | null }
export const services = {
  day: (date?: string) => call<{ date: string; staff: StaffMember[]; appointments: Appointment[] }>(`pos/services/appointments${date ? `?date=${date}` : ''}`),
  book: (body: BookingBody, id?: string) => call<{ appointment: Appointment }>(`pos/services/appointments${id ? `/${id}` : ''}`, { method: 'POST', body: JSON.stringify(body) }),
  status: (id: string, status: 'booked' | 'arrived' | 'no_show' | 'cancelled', reason?: string) =>
    call<{ appointment: Appointment }>(`pos/services/appointments/${id}/status`, { method: 'POST', body: JSON.stringify({ status, reason }) }),
  passes: (customerId: string) => call<{ passes: ServicePass[] }>(`pos/services/passes?customer_id=${customerId}`),
}

/** Kitchen screen: the enrolled kitchen device itself (no person signs in). */
export const kds = {
  tickets: (station: string | null) => call<{ stations: Floor['stations']; tickets: KitchenTicket[]; server_time: string }>(`kds/tickets${station ? `?station=${encodeURIComponent(station)}` : ''}`, { auth: 'device' }),
  bump: (itemIds: string[], status: 'ready' | 'fired' | 'served') => call<{ changed: number }>('kds/bump', { method: 'POST', auth: 'device', body: JSON.stringify({ item_ids: itemIds, status }) }),
  products: () => call<{ products: WasteProduct[] }>('kds/products', { auth: 'device' }),
  waste: (body: WasteBody & { username: string; pin: string }) => call<WasteResult>('kds/waste', { method: 'POST', auth: 'device', body: JSON.stringify(body) }),
}

export type WasteProduct = { id: string; sku: string; name: string; units: { id: string; uom_code: string; decimals: number }[] }

/** Warehouse handheld: same server, its own endpoints (receive, put away, transfers, counts). */
export const wms = {
  bootstrap: () => call<WmsBootstrap>('wms/bootstrap'),
  logout: () => call<{ ok: boolean }>('wms/logout', { method: 'POST' }),
  lookup: (code: string, warehouseId: string) =>
    call<{ type: 'product'; unit: WmsUnit } | { type: 'bin'; bin: WmsBin }>(`wms/lookup?code=${encodeURIComponent(code)}&warehouse_id=${warehouseId}`),
  purchaseOrders: (warehouseId: string) => call<WmsOrder[]>(`wms/purchase-orders?warehouse_id=${warehouseId}`),
  receive: (body: { purchase_order_id: string; client_id: string; supplier_ref?: string; lines: { purchase_order_line_id: string; qty: string; bin_id?: string | null; batch_no?: string; expires_on?: string | null; serials?: string[] }[] }) =>
    call<{ id: string; number: string; status: 'posted' | 'held' }>('wms/receipts', { method: 'POST', body: JSON.stringify(body) }),
  binStock: (binId: string) => call<{ product_id: string; sku: string; name: string; on_hand: string }[]>(`wms/bin-stock?bin_id=${binId}`),
  putAway: (body: { warehouse_id: string; product_uom_id: string; qty: string; from_bin_id: string; to_bin_id: string }) =>
    call<{ ok: boolean }>('wms/putaway', { method: 'POST', body: JSON.stringify(body) }),
  transfers: (warehouseId: string) => call<WmsTransfer[]>(`wms/transfers?warehouse_id=${warehouseId}`),
  receiveTransfer: (id: string, lines: Record<string, string>, toBinId: string | null) =>
    call<{ number: string; status: string; has_discrepancy: boolean }>(`wms/transfers/${id}/receive`, { method: 'POST', body: JSON.stringify({ lines, to_bin_id: toBinId }) }),
  counts: (warehouseId: string) => call<WmsCount[]>(`wms/counts?warehouse_id=${warehouseId}`),
  startCount: (warehouseId: string, binId: string | null) =>
    call<{ id: string; number: string }>('wms/counts', { method: 'POST', body: JSON.stringify({ warehouse_id: warehouseId, bin_id: binId }) }),
  countLine: (id: string, productUomId: string, qty: string, binId: string | null) =>
    call<{ ok: boolean; counted_qty: string }>(`wms/counts/${id}/lines`, { method: 'POST', body: JSON.stringify({ product_uom_id: productUomId, qty, bin_id: binId }) }),
  submitCount: (id: string) => call<{ number: string; status: string }>(`wms/counts/${id}/submit`, { method: 'POST' }),
}
