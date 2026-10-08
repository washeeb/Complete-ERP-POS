/** Electronics at the till: serial / IMEI numbers typed or scanned (same rule as the server's SerialRegistry::normalise). */

/** " 3569 0101-0000001 " → "356901010000001": upper case, no spaces or dashes. */
export const normaliseSerial = (s: string) => s.replace(/[\s-]+/g, '').toUpperCase().slice(0, 80)

/** Add a scanned number to a list: refuses blanks and repeats. */
export function addSerial(list: string[], scanned: string): { list: string[]; error: string | null } {
  const s = normaliseSerial(scanned)
  if (!s) return { list, error: 'Scan or type the serial / IMEI number.' }
  if (list.includes(s)) return { list, error: `${s} is already on this sale.` }
  return { list: [...list, s], error: null }
}

/** Warranty end date for a unit sold today (months from the product), as YYYY-MM-DD, or null. */
export function warrantyUntil(months: number | null | undefined, from = new Date()): string | null {
  if (!months) return null
  const d = new Date(from)
  d.setMonth(d.getMonth() + months)
  return d.toLocaleDateString('en-CA')
}
