import { db, getDevice, getSession } from './db'
import type { Bootstrap, PostedSale, Product, Shift } from './types'

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
    call<{ device_id: string; token: string }>('devices/enroll', { method: 'POST', auth: 'none', body: JSON.stringify({ code, public_key: publicKey }) }),
  login: (username: string, pin: string) =>
    call<{ token: string; expires_at: string | null; user: { name: string } }>('pos/login', { method: 'POST', auth: 'device', body: JSON.stringify({ username, pin }) }),
  logout: () => call<{ ok: boolean }>('pos/logout', { method: 'POST' }),
  bootstrap: () => call<Bootstrap>('pos/bootstrap'),
  catalog: () => call<{ generated_at: string; products: Product[] }>('pos/catalog'),
  openShift: (floatMinor: number) => call<{ shift: Shift }>('pos/shifts/open', { method: 'POST', body: JSON.stringify({ opening_float_minor: floatMinor }) }),
  closeShift: (countedMinor: number, note: string) =>
    call<{ shift: Shift }>('pos/shifts/close', { method: 'POST', body: JSON.stringify({ counted_cash_minor: countedMinor, note: note || null }) }),
  postSale: (payload: unknown) => call<{ sale: PostedSale; replayed: boolean }>('pos/sales', { method: 'POST', body: JSON.stringify(payload) }),
}
