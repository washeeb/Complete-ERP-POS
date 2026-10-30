/**
 * A steady colour per person (from their id), so the name band on the till is a different colour for each teller and a
 * till left signed in as someone else stands out at a glance.
 */
export function personColor(id: string): string {
  let h = 0
  for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) >>> 0
  return `hsl(${h % 360} 60% 34%)`
}

/** A staff card scanned into the username box (its code starts SLB1-), not a typed username. */
export function isStaffCard(text: string): boolean {
  return /^SLB1-/i.test(text.trim())
}

/** The card's code as the server hashes it, kept on the till to sign in by card while offline. */
export async function cardKey(code: string): Promise<string> {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(code.trim().toUpperCase()))
  return Array.from(new Uint8Array(bytes), (b) => b.toString(16).padStart(2, '0')).join('')
}
