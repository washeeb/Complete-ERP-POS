import type { TillApprovalState } from './api'
import type { CartLine } from './cart'

/**
 * Asking a manager who is not at the till (owner request 2026-11-15): the till sends the request, the manager answers on
 * their phone, and the till checks for the answer every few seconds until it comes, the manager declines, it runs out
 * (10 minutes) or the cashier cancels.
 */
export async function waitForAnswer(
  id: string, read: (id: string) => Promise<TillApprovalState>, opts: { everyMs?: number; signal?: AbortSignal; sleep?: (ms: number) => Promise<void> } = {},
): Promise<TillApprovalState> {
  const every = opts.everyMs ?? 2500
  const sleep = opts.sleep ?? ((ms: number) => new Promise<void>((r) => setTimeout(r, ms)))
  for (;;) {
    if (opts.signal?.aborted) throw new DOMException('Cancelled', 'AbortError')
    try {
      const s = await read(id)
      if (s.status !== 'pending') return s
    } catch (e) {
      if (opts.signal?.aborted) throw e
      // A missed check on a shaky line is ignored; the next one tries again.
    }
    await sleep(every)
  }
}

export type Removed = { key: string; name: string; qtyMilli: number; amountMinor: number }
export type Peak = { name: string; peakMilli: number; unitMinor: number }

/**
 * Items taken off the sale since it was last approved: for each line, the most it held minus what it holds now (a line
 * taken off holds 0), at its price. Typing over a quantity (10 → 1 → 12) is not a removal: only what is lower at the end
 * counts.
 */
export function removedSince(peaks: Record<string, Peak>, lines: Pick<CartLine, 'key' | 'qtyMilli'>[]): Removed[] {
  const now = new Map(lines.map((l) => [l.key, l.qtyMilli]))
  return Object.entries(peaks).flatMap(([key, p]) => {
    const gone = p.peakMilli - Math.max(0, now.get(key) ?? 0)
    return gone > 0 ? [{ key, name: p.name, qtyMilli: gone, amountMinor: Math.round((p.unitMinor * gone) / 1000) }] : []
  })
}

/** Raise each line's high-water mark (and remember its unit price) as the sale grows. */
export function raisePeaks(peaks: Record<string, Peak>, priced: { key: string; name: string; qtyMilli: number; totalMinor: number }[]): Record<string, Peak> {
  const next = { ...peaks }
  for (const l of priced) {
    if (l.qtyMilli <= 0) continue
    const unit = Math.round((l.totalMinor * 1000) / l.qtyMilli)
    const p = next[l.key]
    if (!p || l.qtyMilli > p.peakMilli) next[l.key] = { name: l.name, peakMilli: l.qtyMilli, unitMinor: unit }
    else if (p.unitMinor !== unit) next[l.key] = { ...p, unitMinor: unit }
  }
  return next
}

/** After an approval: what the sale holds once the approved removal is made is the new starting point. */
export function settlePeaks(peaks: Record<string, Peak>, after: Pick<CartLine, 'key' | 'qtyMilli'>[]): Record<string, Peak> {
  const now = new Map(after.map((l) => [l.key, l.qtyMilli]))
  return Object.fromEntries(Object.entries(peaks).flatMap(([key, p]) => {
    const q = now.get(key) ?? 0
    return q > 0 ? [[key, { ...p, peakMilli: Math.min(p.peakMilli, q) }]] : []
  }))
}

/** A supervisor is needed: removals worth more than the company's amount (null = the company does not ask). */
export function voidNeedsApproval(removed: Removed[], aboveMinor: number | null | undefined): boolean {
  if (aboveMinor === null || aboveMinor === undefined) return false
  return removed.length > 0 && removed.reduce((a, r) => a + r.amountMinor, 0) > aboveMinor
}
