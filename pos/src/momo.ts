import type { Payment } from './types'

/**
 * Mobile money that confirms itself (owner request 2026-10-23): the till sends a payment request to the customer's
 * phone through the company's provider (Paystack or Hubtel) and adds the payment only once the server says it is paid.
 */
export type MomoMode = 'manual' | 'prompt' | 'prompt_only'

export type MomoCharge = {
  id: string; status: 'pending' | 'otp' | 'success' | 'failed' | 'expired'; message: string | null
  network: string; amount_minor: number; reference: string; phone: string
}

/** How often the till asks the server while the customer approves on their phone. */
export const POLL_MS = 3000

export const finished = (c: MomoCharge) => c.status === 'success' || c.status === 'failed' || c.status === 'expired'

/** May the cashier type a transaction ID? Always when there is no provider or the till is offline. */
export const manualAllowed = (mode: MomoMode | undefined, online: boolean) => (mode ?? 'manual') !== 'prompt_only' || !online

/** May the till send a payment request? Only online, with a provider set up. */
export const promptAvailable = (mode: MomoMode | undefined, online: boolean) => online && (mode === 'prompt' || mode === 'prompt_only')

/** The payment for the sale from a paid request: the server records it as verified with the provider's transaction id. */
export function paymentFrom(c: MomoCharge): Payment {
  return { method: 'momo', provider: c.network, amount_minor: c.amount_minor, tendered_minor: c.amount_minor, reference: c.reference, payer_phone: c.phone, momo_charge_id: c.id }
}
