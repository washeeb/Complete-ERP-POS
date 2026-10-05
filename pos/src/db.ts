import Dexie, { type Table } from 'dexie'

/**
 * Till-local storage (IndexedDB). Phase 2 keeps the device identity, the signed-in session,
 * the receipt counter and sales not yet acknowledged by the server. Phase 3 adds the offline
 * catalog cache and the full sync queue on top of this.
 */
export type DeviceRecord = { id: 'device'; deviceId: string; token: string; privateKey: CryptoKey; enrolledAt: string }
export type SessionRecord = { id: 'session'; token: string; userName: string; expiresAt: string | null }
export type CounterRecord = { id: 'receipt_seq'; value: number }
export type PendingSale = { id: string; payload: unknown; createdAt: string; lastError: string | null }

class TillDb extends Dexie {
  kv!: Table<DeviceRecord | SessionRecord | CounterRecord, string>
  pendingSales!: Table<PendingSale, string>

  constructor() {
    super('synclytics-till')
    this.version(1).stores({ kv: 'id', pendingSales: 'id, createdAt' })
  }
}

export const db = new TillDb()

export async function getDevice() {
  return (await db.kv.get('device')) as DeviceRecord | undefined
}

export async function getSession() {
  return (await db.kv.get('session')) as SessionRecord | undefined
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
