import type { Product } from './types'

/** Days ahead that count as "expires soon" on the till (amber); expired is red. */
export const SOON_DAYS = 90

/**
 * The expiry the cashier should see for a batch-tracked product: the batch sold next (earliest expiry with stock at this
 * till's store), e.g. "Exp 06/2027". Null for products without batches or without a dated batch in stock.
 */
export function expiryNote(p: Pick<Product, 'expires_on'>, today: Date = new Date()): { text: string; soon: boolean; expired: boolean } | null {
  if (!p.expires_on) return null
  const d = new Date(`${p.expires_on}T00:00:00`)
  if (Number.isNaN(d.getTime())) return null
  const days = Math.floor((d.getTime() - new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime()) / 86_400_000)
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  if (days < 0) return { text: `Expired ${mm}/${d.getFullYear()}`, soon: false, expired: true }
  return { text: `Exp ${mm}/${d.getFullYear()}`, soon: days <= SOON_DAYS, expired: false }
}
