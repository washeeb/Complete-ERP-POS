import { db, getDevice, getSession } from './db'
import type { OfflineGrant } from './db'
import type { Bootstrap, PostedSale, Product, PromotionRow, Returnable, Shift, TillCustomer, WmsBin, WmsBootstrap, WmsCount, WmsOrder, WmsTransfer, WmsUnit } from './types'

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

async function call<T>(path: string, init: RequestInit & { auth?: 'device' | 'session' | 'none' } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' }
  const auth = init.auth ?? 'session'
  if (auth === 'device') headers.Authorization = `Bearer ${(await getDevice())?.token ?? ''}`
  if (auth === 'session') headers.Authorization = `Bearer ${(await getSession())?.token ?? ''}`

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
    return body as { version: string; generated_at: string; products: Product[]; promotions?: PromotionRow[]; utc_offset_minutes?: number }
  },
  /** Customers for lookup (ETag); null = unchanged. */
  customers: async (version?: string | null) => {
    const headers: Record<string, string> = { Accept: 'application/json', Authorization: `Bearer ${(await getSession())?.token ?? ''}` }
    if (version) headers['If-None-Match'] = `"${version}"`
    const res = await fetch('/api/v1/pos/customers', { headers }).catch(() => null)
    if (!res || res.status === 304 || !res.ok) return null
    return (await res.json()) as { version: string; customers: TillCustomer[] }
  },
  openShift: (floatMinor: number, id: string) =>
    call<{ shift: Shift }>('pos/shifts/open', { method: 'POST', body: JSON.stringify({ opening_float_minor: floatMinor, id }) }),
  closeShift: (countedMinor: number, note: string) =>
    call<{ shift: Shift }>('pos/shifts/close', { method: 'POST', body: JSON.stringify({ counted_cash_minor: countedMinor, note: note || null }) }),
  postSale: (payload: unknown) => call<{ sale: PostedSale; replayed: boolean }>('pos/sales', { method: 'POST', body: JSON.stringify(payload) }),
  lookupReceipt: (receiptNo: string) => call<{ sale: PostedSale; returnable: Returnable; customer_id: string | null }>(`pos/receipts?receipt=${encodeURIComponent(receiptNo)}`),
  postReturn: (payload: unknown) => call<{ sale: PostedSale; store_credit_code: string | null }>('pos/returns', { method: 'POST', body: JSON.stringify(payload) }),
  accountPayment: (body: { customer_id: string; amount_minor: number; method: 'cash' | 'momo' | 'card'; provider?: string; reference?: string }) =>
    call<{ number: string; amount_minor: number; balance_minor: number }>('pos/account-payments', { method: 'POST', body: JSON.stringify(body) }),
}

/** Warehouse handheld: same server, its own endpoints (receive, put away, transfers, counts). */
export const wms = {
  bootstrap: () => call<WmsBootstrap>('wms/bootstrap'),
  logout: () => call<{ ok: boolean }>('wms/logout', { method: 'POST' }),
  lookup: (code: string, warehouseId: string) =>
    call<{ type: 'product'; unit: WmsUnit } | { type: 'bin'; bin: WmsBin }>(`wms/lookup?code=${encodeURIComponent(code)}&warehouse_id=${warehouseId}`),
  purchaseOrders: (warehouseId: string) => call<WmsOrder[]>(`wms/purchase-orders?warehouse_id=${warehouseId}`),
  receive: (body: { purchase_order_id: string; client_id: string; supplier_ref?: string; lines: { purchase_order_line_id: string; qty: string; bin_id?: string | null }[] }) =>
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
