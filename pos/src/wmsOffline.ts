import { v7 as uuidv7 } from 'uuid'
import { ApiError } from './api'
import { db, getSession, type WmsOp } from './db'
import { enqueue, grantFor, sendQueue, type QueueResult } from './offline'
import type { WmsBin, WmsCatalog, WmsUnit } from './types'

/**
 * Offline handhelds (owner request 2026-10-23). The handheld keeps working when its server cannot be reached:
 * - lists (orders to receive, transfers, open counts) and the scan catalogue come from the server when it answers and
 *   from the handheld's own copy when it does not;
 * - scans are recognised on the handheld (barcodes, SKUs, bin labels);
 * - every action (receive, move, receive a transfer, count) is queued like a till's sale — signed by the handheld and
 *   carrying the person's offline sign-in — and sent at once, or as soon as the server is back. The server does each one
 *   once, even if it arrives twice.
 */

export type Loaded<T> = { data: T; offline: boolean; savedAt: string | null }

/** From the server when it answers (and keep a copy), else the handheld's copy. */
export async function cachedGet<T>(key: string, fetch: () => Promise<T>): Promise<Loaded<T>> {
  try {
    const data = await fetch()
    const savedAt = new Date().toISOString()
    await db.cache.put({ id: `wms:${key}`, data, savedAt })
    return { data, offline: false, savedAt }
  } catch (e) {
    if (!(e instanceof ApiError && (e.code === 'network' || e.code === 'signed_out'))) throw e
    const copy = (await db.cache.get(`wms:${key}`)) as { data: T; savedAt: string } | undefined
    if (!copy) throw new ApiError('network', 'The server cannot be reached and this handheld has no copy yet. Connect it once to the network.', 0)
    return { data: copy.data, offline: true, savedAt: copy.savedAt }
  }
}

/** Change the handheld's copy after an action, so the lists match what was just done (until the server answers again). */
export async function updateCopy<T>(key: string, change: (data: T) => T): Promise<void> {
  const copy = (await db.cache.get(`wms:${key}`)) as { data: T; savedAt: string } | undefined
  if (copy) await db.cache.put({ id: `wms:${key}`, data: change(copy.data), savedAt: copy.savedAt })
}

/** A scan recognised on the handheld: a bin label, a barcode or a SKU (as the server would). */
export function lookupLocal(catalog: WmsCatalog, code: string): { type: 'bin'; bin: WmsBin } | { type: 'product'; unit: WmsUnit } | null {
  const c = code.trim()
  const bin = catalog.bins.find((b) => b.code === c.toUpperCase())
  if (bin) return { type: 'bin', bin }
  const hit = catalog.units.find((u) => u.barcodes.includes(c)) ?? catalog.units.find((u) => u.is_base && u.sku === c.toUpperCase())
  if (!hit) return null
  const units = catalog.units.filter((u) => u.product_id === hit.product_id).map((u) => ({ product_uom_id: u.product_uom_id, uom_code: u.uom_code, factor: u.factor }))
  return { type: 'product', unit: { product_uom_id: hit.product_uom_id, product_id: hit.product_id, sku: hit.sku, name: hit.name, uom_code: hit.uom_code, factor: hit.factor, decimals: hit.decimals, units } }
}

export type ActResult = { state: 'done' | 'queued' | 'refused'; number?: string; detail?: string }

/**
 * Do a warehouse action: queued first (so nothing is lost if the network drops mid-way), then sent at once. "queued"
 * means it is saved on the handheld and goes when the server is back.
 */
export async function act(type: WmsOp, payload: Record<string, unknown>, label: string, id: string = uuidv7()): Promise<ActResult> {
  const session = await getSession()
  const grant = session ? await grantFor(session.userId) : undefined
  if (!grant) throw new Error('Sign in again on this handheld while it is connected, so it can keep working offline.')
  await enqueue({ id, type, payload, grant, label })
  let mine: QueueResult | undefined
  await sendQueue((r) => { if (r.id === id) mine = r })
  if (!mine) return { state: 'queued' }
  if (mine.status === 'accepted' || mine.status === 'duplicate') return { state: 'done', number: mine.number, detail: mine.detail }
  return { state: 'refused', detail: mine.detail }
}

const WMS_OPS: WmsOp[] = ['wms_receive', 'wms_putaway', 'wms_transfer', 'wms_count_start', 'wms_count_line', 'wms_count_submit']

/** What this handheld still has to send, and what the server refused (with its reason). */
export async function waiting(): Promise<{ pending: number; refused: { seq: number; label: string; error: string }[] }> {
  const items = (await db.queue.toArray()).filter((q) => (WMS_OPS as string[]).includes(q.type))
  return {
    pending: items.filter((q) => q.status === 'pending').length,
    refused: items.filter((q) => q.status === 'review').map((q) => ({ seq: q.seq ?? 0, label: q.label ?? q.type, error: q.lastError ?? '' })),
  }
}

/** A refused action the person no longer wants (e.g. counted again correctly): removed from this handheld. */
export async function discard(seq: number): Promise<void> {
  await db.queue.delete(seq)
}
