import type { CartLine } from './cart'
import type { Product, VanDay, VisitOutcome } from './types'

/**
 * Distribution at the till: deposit containers that go with what is sold, today's route, and where the phone is.
 */

/**
 * The cart with its deposit lines: for each product sold with a returnable container (crate, bottle, cylinder), one
 * automatic line of that container — the number of containers needed, rounded up to whole containers when the container
 * is counted in pieces. Recomputed from the other lines, so changing a quantity changes the deposit. Empties handed
 * back (minus lines) are the cashier's own lines and stay as they are.
 */
export function withDeposits(lines: CartLine[], products: Product[]): CartLine[] {
  const own = lines.filter((l) => !l.autoDeposit)
  const need = new Map<string, number>() // container id → base units × 1000
  for (const l of own) {
    const p = l.product
    if (!p.deposit_product_id || l.qtyMilli <= 0) continue
    const per = Number(p.deposit_per_unit ?? '1')
    need.set(p.deposit_product_id, (need.get(p.deposit_product_id) ?? 0) + l.qtyMilli * Number(l.unit.factor) * per)
  }
  const out = [...own]
  for (const [id, q] of need) {
    const container = products.find((p) => p.id === id)
    if (!container || q <= 0) continue
    const unit = container.units.find((u) => u.is_base) ?? container.units[0]
    const step = 10 ** (3 - unit.decimals)
    out.push({ key: `dep:${id}`, product: container, unit, qtyMilli: Math.ceil(Math.round(q) / step) * step, autoDeposit: true })
  }
  return out
}

/** Today's stops in visiting order, with what happened at each (from this phone's own record of the day). */
export function stopsToday(day: VanDay, visited: Record<string, VisitOutcome>) {
  return [...day.stops].sort((a, b) => a.sequence - b.sequence).map((s) => ({ ...s, outcome: visited[s.customer_id] ?? null }))
}

/** Share of the month's target reached, in whole percent (null without a target). */
export const achieved = (day: VanDay) => (day.month.target_minor ? Math.floor((day.month.sold_minor * 100) / day.month.target_minor) : null)

const VISITS_KEY = (date: string) => `van-visits:${date}`

/** What happened at each customer today on this phone (kept on the device, so it shows offline too). */
export function visitedToday(date = new Date().toLocaleDateString('en-CA')): Record<string, VisitOutcome> {
  try {
    return JSON.parse(localStorage.getItem(VISITS_KEY(date)) ?? '{}')
  } catch {
    return {}
  }
}

export function rememberVisit(customerId: string, outcome: VisitOutcome, date = new Date().toLocaleDateString('en-CA')) {
  const v = { ...visitedToday(date), [customerId]: outcome }
  try { localStorage.setItem(VISITS_KEY(date), JSON.stringify(v)) } catch { /* private window */ }
  return v
}

/** The phone's position for a visit, if the rep allows it (no answer within 8 seconds = no position). */
export function currentPosition(): Promise<{ lat: number; lng: number; accuracy_m: number } | null> {
  if (typeof navigator === 'undefined' || !navigator.geolocation) return Promise.resolve(null)
  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy_m: Math.round(p.coords.accuracy) }),
      () => resolve(null), { enableHighAccuracy: true, timeout: 8000, maximumAge: 60_000 })
  })
}
