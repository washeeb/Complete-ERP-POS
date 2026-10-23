import { GATEWAY_DOWN } from './api'
import { db, getDevice, type OfflineGrant, type QueueItem } from './db'

const b64 = (bytes: ArrayBuffer | Uint8Array) => {
  let s = ''
  new Uint8Array(bytes).forEach((b) => (s += String.fromCharCode(b)))
  return btoa(s)
}
const unb64 = (s: string) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0))

/** Same PBKDF2 the server used when it issued the grant; constant-time-ish compare. */
export async function pinMatches(grant: OfflineGrant, pin: string): Promise<boolean> {
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits'])
  const bits = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', hash: 'SHA-256', salt: unb64(grant.pin.salt), iterations: grant.pin.iterations }, key, 256)
  const a = new Uint8Array(bits)
  const b = unb64(grant.pin.hash)
  if (a.length !== b.length) return false
  let diff = 0
  for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i]
  return diff === 0
}

export async function saveGrant(grant: OfflineGrant) {
  await db.grants.put({ ...grant, usernameKey: grant.username.toLowerCase() })
}

/** Offline sign-in: the PIN unlocks this person's grant for this till, until it expires. */
export async function offlineSignIn(username: string, pin: string): Promise<OfflineGrant> {
  const grant = asSigned(await db.grants.get(username.trim().toLowerCase()))
  const device = await getDevice()
  if (!grant || grant.device_id !== device?.deviceId) {
    throw new Error('You have not signed in on this till while it was online. Sign in once with the server available.')
  }
  if (Date.parse(grant.expires_at) < Date.now()) {
    throw new Error('Your offline sign-in on this till has expired. Sign in again when the server is available.')
  }
  if (!(await pinMatches(grant, pin))) throw new Error('Wrong username or PIN.')
  return grant
}

/** The grant exactly as the server signed it (our local lookup key removed — any extra field breaks the signature). */
export function asSigned(rec: (OfflineGrant & { usernameKey?: string }) | undefined): OfflineGrant | undefined {
  if (!rec) return undefined
  const { usernameKey: _ignored, ...grant } = rec
  void _ignored
  return grant as OfflineGrant
}

export async function grantFor(userId: string): Promise<OfflineGrant | undefined> {
  return asSigned(await db.grants.where('user_id').equals(userId).first())
}

/** Sign exactly these bytes with the till's non-extractable device key (raw r||s, as the server expects). */
export async function signBody(body: string): Promise<string> {
  const device = await getDevice()
  if (!device) throw new Error('This till is not enrolled.')
  const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, device.privateKey, new TextEncoder().encode(body))
  return b64(sig)
}

export async function enqueue(item: Omit<QueueItem, 'status' | 'attempts' | 'lastError' | 'createdAt'>) {
  await db.queue.add({ ...item, status: 'pending', attempts: 0, lastError: null, createdAt: new Date().toISOString() })
}

export type SendResult = { sent: number; refused: number; error: string | null }

/** One answer per queued item, as the server gave it (the handheld shows the receipt or count number). */
export type QueueResult = { id: string; status: string; detail?: string; number?: string }

/**
 * Send queued work in order, in batches. Accepted/duplicate items are removed; refused items stay
 * (status "review") for a supervisor; network failures leave everything for the next attempt.
 */
export async function sendQueue(onResult?: (r: QueueResult) => void): Promise<SendResult> {
  const device = await getDevice()
  if (!device) return { sent: 0, refused: 0, error: null }
  let sent = 0
  let refused = 0
  for (;;) {
    const batch = await db.queue.where('status').equals('pending').limit(50).sortBy('seq')
    if (!batch.length) return { sent, refused, error: null }
    const body = JSON.stringify({ events: batch.map((q) => ({ id: q.id, type: q.type, payload: q.payload, grant: asSigned(q.grant) })) })
    let res: Response
    try {
      res = await fetch('/api/v1/pos/sync', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${device.token}`, 'X-Device-Signature': await signBody(body) },
        body,
      })
    } catch {
      return { sent, refused, error: 'Server not reachable — will retry.' }
    }
    if (GATEWAY_DOWN.includes(res.status)) return { sent, refused, error: 'Server not answering — will retry.' }
    const json = await res.json().catch(() => ({}))
    if (!res.ok) return { sent, refused, error: json.detail ?? `Server answered ${res.status}` }
    for (const r of json.results as QueueResult[]) {
      onResult?.(r)
      const item = batch.find((q) => q.id === r.id)
      if (!item?.seq) continue
      if (r.status === 'accepted' || r.status === 'duplicate') {
        await db.queue.delete(item.seq)
        sent++
      } else {
        await db.queue.update(item.seq, { status: 'review', lastError: r.detail ?? r.status, attempts: item.attempts + 1 })
        refused++
      }
    }
  }
}

/** Everything not yet acknowledged, signed by this till — for loading in the back office if the till must be reinstalled. */
export async function exportQueueFile(): Promise<Blob> {
  const device = await getDevice()
  const items = await db.queue.orderBy('seq').toArray()
  const body = JSON.stringify({ events: items.map((q) => ({ id: q.id, type: q.type, payload: q.payload, grant: asSigned(q.grant) })) })
  const file = { format: 'till-queue-1', device_id: device?.deviceId, exported_at: new Date().toISOString(), body, signature: await signBody(body) }
  return new Blob([JSON.stringify(file)], { type: 'application/json' })
}

/** After a supervisor fixed the cause (e.g. re-enabled a person), send refused items again. */
export async function retryRefused(): Promise<number> {
  const refused = await db.queue.where('status').equals('review').toArray()
  for (const q of refused) if (q.seq) await db.queue.update(q.seq, { status: 'pending', lastError: null })
  return refused.length
}