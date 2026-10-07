import type { CartLine } from './cart'
import { divRound } from './money'
import type { DiningItem, DiningOrder, ModifierGroup, Product, Reservation } from './types'

/**
 * Restaurant rules on the till. Bill lines, modifiers and the service charge follow the server (DiningSales, DiningService):
 * each dish is paid whole with its priced extras; the service charge is its rate × the bill after discounts.
 */

/** The modifier groups a product offers, in the product's order. */
export function groupsFor(product: Product, groups: ModifierGroup[]): ModifierGroup[] {
  return (product.modifier_group_ids ?? []).map((id) => groups.find((g) => g.id === id)).filter((g): g is ModifierGroup => !!g)
}

/** null when the choices are acceptable; otherwise what the waiter must fix (same rule as the server). */
export function modifierError(product: Product, groups: ModifierGroup[], chosen: string[]): string | null {
  for (const g of groupsFor(product, groups)) {
    const n = g.options.filter((o) => chosen.includes(o.id)).length
    if (n < g.min || n > g.max) return `Choose ${g.min === g.max ? g.min : `${g.min}–${g.max}`} for ${g.name}.`
  }
  return null
}

/** Names of the chosen options (kitchen note) and the products charged for priced ones. */
export function describeChoices(product: Product, groups: ModifierGroup[], chosen: string[]) {
  const options = groupsFor(product, groups).flatMap((g) => g.options.filter((o) => chosen.includes(o.id)))
  return { names: options.map((o) => o.name), chargedProductIds: options.map((o) => o.product_id).filter((p): p is string => !!p) }
}

export const kitchenNote = (names: string[], note?: string | null) => [...names, note?.trim() ?? ''].filter(Boolean).join(', ') || null

/** Service charge amount: rate × (gross on tax-inclusive lists, net otherwise) of the rest of the bill. */
export function serviceAmount(base: { total: number; net: number }, inclusive: boolean, bp: number): number {
  return bp > 0 ? divRound((inclusive ? base.total : base.net) * bp, 10000) : 0
}

/**
 * Cart lines that pay these bill items: the dish (with its choices as the note) and each priced extra as its own product.
 * Items whose product (or extra) is not in the till's catalog are skipped and returned, so the cashier can refresh.
 */
export function billLines(items: DiningItem[], products: Product[]): { lines: CartLine[]; missing: DiningItem[] } {
  const lines: CartLine[] = []
  const missing: DiningItem[] = []
  for (const i of items) {
    const product = products.find((p) => p.id === i.product_id)
    const unit = product?.units.find((u) => u.id === i.product_uom_id)
    const extras = i.modifiers.filter((m) => m.product_id).map((m) => products.find((p) => p.id === m.product_id))
    if (!product || !unit || extras.some((e) => !e || !e.units.find((u) => u.is_base))) {
      missing.push(i)
      continue
    }
    const qtyMilli = Math.round(Number(i.qty) * 1000)
    lines.push({ key: `di:${i.id}`, product, unit, qtyMilli, diningItemId: i.id, note: kitchenNote(i.modifiers.map((m) => m.name), i.note) })
    extras.forEach((e, n) => lines.push({ key: `di:${i.id}:${n}`, product: e!, unit: e!.units.find((u) => u.is_base)!, qtyMilli, diningItemId: i.id, note: `with ${i.name}` }))
  }
  return { lines, missing }
}

/** Items still to pay on a bill (not voided, not paid). */
export const unpaid = (o: DiningOrder) => o.items.filter((i) => i.status !== 'void' && !i.paid)

/** Equal shares of an amount for N guests; the first guests carry the odd pesewas. */
export function evenSplit(total: number, guests: number): number[] {
  const n = Math.max(1, Math.floor(guests))
  const base = Math.floor(total / n)
  return Array.from({ length: n }, (_, i) => base + (i < total - base * n ? 1 : 0))
}

/** Minutes since a time, for kitchen tickets and the floor view. */
export const minutesSince = (iso: string | null | undefined, now: number) => (iso ? Math.max(0, Math.floor((now - Date.parse(iso)) / 60000)) : 0)

/** Courses still waiting to be called on a bill, in order. */
export const heldCourses = (o: DiningOrder) => [...new Set(o.items.filter((i) => i.status === 'held').map((i) => i.course))].sort((a, b) => a - b)

/** Late arrivals: a booking more than this many minutes past its time is shown as late (its table can be released). */
export const LATE_MINUTES = 15

/**
 * The booking holding a table on the floor view: from `holdMinutes` before its time until the guests are seated;
 * `late` once they are more than 15 minutes late.
 */
export function tableBooking(reservations: Reservation[], tableId: string, now: number, holdMinutes: number): { reservation: Reservation; late: boolean } | null {
  const r = reservations
    .filter((x) => x.status === 'booked' && x.dining_table_id === tableId && Date.parse(x.starts_at) - holdMinutes * 60000 <= now)
    .sort((a, b) => Date.parse(a.starts_at) - Date.parse(b.starts_at))[0]
  return r ? { reservation: r, late: now - Date.parse(r.starts_at) > LATE_MINUTES * 60000 } : null
}
