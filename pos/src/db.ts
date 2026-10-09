import Dexie, { type Table } from 'dexie'
import type { Bootstrap, Product, PromotionRow, TillCustomer, Grocery, Pharmacy, Restaurant, Fashion, Services } from './types'

/**
 * Till-local storage (IndexedDB). The till must keep working when the server is unreachable:
 * it keeps the device identity, a cached catalog, the offline grants of people who signed in
 * here, the receipt counter, the current shift, and an ordered queue of work not yet sent.
 * Nothing in the queue is ever deleted until the server acknowledges it.
 */
export type DeviceRecord = { id: 'device'; deviceId: string; token: string; privateKey: CryptoKey; enrolledAt: string; kind?: string }
export type SessionRecord = {
  id: 'session'
  token: string | null // null = signed in offline with a grant
  userId: string
  userName: string
  expiresAt: string | null
}
export type CounterRecord = { id: 'receipt_seq'; value: number }
export type LocalShift = { id: 'shift'; shiftId: string; openedAt: string; floatMinor: number; cashMinor: number; queued: boolean }
export type CacheRecord =
  | { id: 'bootstrap'; data: Bootstrap; savedAt: string }
  | { id: 'catalog'; version: string; products: Product[]; promotions?: PromotionRow[]; utc_offset_minutes?: number; grocery?: Grocery | null; pharmacy?: Pharmacy | null; restaurant?: Restaurant | null; electronics?: boolean; fashion?: Fashion | null; services?: Services | null; savedAt: string }
  | { id: 'customers'; version: string; customers: TillCustomer[]; savedAt: string }
  | { id: `brand:${string}`; url: string; dataUrl: string; savedAt: string }

export type OfflineGrant = {
  v: number; id: string; device_id: string; user_id: string; user_name: string; username: string; issued_at: string; expires_at: string
  pin: { alg: string; iterations: number; salt: string; hash: string }; sig: string
  [k: string]: unknown
}

export type QueueItem = {
  seq?: number
  id: string
  type: 'shift_open' | 'sale' | 'shift_close' | 'clock' | 'visit' | 'order'
  payload: Record<string, unknown>
  grant: OfflineGrant
  status: 'pending' | 'review'
  attempts: number
  lastError: string | null
  createdAt: string
  receiptNo?: string
}

class TillDb extends Dexie {
  kv!: Table<DeviceRecord | SessionRecord | CounterRecord | LocalShift, string>
  cache!: Table<CacheRecord, string>
  grants!: Table<OfflineGrant & { usernameKey: string }, string>
  queue!: Table<QueueItem, number>
  pendingSales!: Table<{ id: string; payload: unknown; createdAt: string; lastError: string | null }, string>

  constructor() {
    super('synclytics-till')
    this.version(1).stores({ kv: 'id', pendingSales: 'id, createdAt' })
    this.version(2).stores({ kv: 'id', pendingSales: 'id, createdAt', cache: 'id', grants: 'usernameKey, user_id', queue: '++seq, id, status' })
  }
}

export const db = new TillDb()

export async function getDevice() {
  return (await db.kv.get('device')) as DeviceRecord | undefined
}

export async function getSession() {
  return (await db.kv.get('session')) as SessionRecord | undefined
}

export async function getLocalShift() {
  return (await db.kv.get('shift')) as LocalShift | undefined
}

/** Next receipt number for this device: never reused, never lower than what the server has seen. */
export async function nextSeq(serverLast: number): Promise<number> {
  return db.transaction('rw', db.kv, async () => {
    const cur = ((await db.kv.get('receipt_seq')) as CounterRecord | undefined)?.value ?? 0
    const next = Math.max(cur, serverLast) + 1
    await db.kv.put({ id: 'receipt_seq', value: next })
    return next
  })
}

export async function queueCounts() {
  const all = await db.queue.toArray()
  return { pending: all.filter((q) => q.status === 'pending').length, review: all.filter((q) => q.status === 'review').length, total: all.length }
}
