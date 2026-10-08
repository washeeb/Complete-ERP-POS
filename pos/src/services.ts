import type { CartLine } from './cart'
import type { Appointment, Product, ServicePass, StaffMember } from './types'

/** Services at the till: bookable services, the day's appointments by staff member, and prepaid passes. */

/** A bookable service (it has a duration): the till asks who did it, for their commission. */
export const isService = (p: Product) => p.service_minutes !== null && p.service_minutes !== undefined

/** Booking times for a day, every `step` minutes from `start` up to (not including) `end` ("HH:MM"). */
export function slots(start: string, end: string, step: number): string[] {
  const mins = (t: string) => { const [h, m] = t.split(':').map(Number); return (h || 0) * 60 + (m || 0) }
  const out: string[] = []
  for (let t = mins(start); t < mins(end) && out.length < 300; t += Math.max(5, step)) {
    out.push(`${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`)
  }
  return out
}

/** The day's appointments under each staff member, in time order (cancelled ones left out). */
export function byStaff(staff: StaffMember[], appointments: Appointment[]) {
  return staff.map((s) => ({ staff: s, appointments: appointments.filter((a) => a.employee_id === s.id && a.status !== 'cancelled')
    .sort((a, b) => a.starts_at.localeCompare(b.starts_at)) }))
}

/** Sessions of a pass item already in the cart (not yet paid). */
export const inCart = (lines: CartLine[], passId: string, item: number) =>
  lines.filter((l) => l.pass?.id === passId && l.pass.item === item).reduce((a, l) => a + Math.round(l.qtyMilli / 1000), 0)

/** What can pay for this service: each pass item that covers it, with the sessions still free (null = unlimited). */
export function coveringItems(passes: ServicePass[], productId: string, lines: CartLine[] = []) {
  return passes.flatMap((p) => p.items.map((it, i) => ({ pass: p, item: i, label: it.label, left: it.remaining === null ? null : it.remaining - inCart(lines, p.id, i) }))
    .filter((x) => p.items[x.item].product_ids.includes(productId) && (x.left === null || x.left > 0)))
}

/** Local time "HH:MM" of an ISO timestamp. */
export const hhmm = (iso: string) => new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
