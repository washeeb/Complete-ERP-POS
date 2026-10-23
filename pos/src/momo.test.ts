import { describe, expect, it } from 'vitest'
import { finished, manualAllowed, paymentFrom, promptAvailable } from './momo'

describe('mobile money payment requests', () => {
  it('lets the cashier type the ID unless the owner chose requests only, and always offline', () => {
    expect(manualAllowed('manual', true)).toBe(true)
    expect(manualAllowed('prompt', true)).toBe(true)
    expect(manualAllowed('prompt_only', true)).toBe(false)
    expect(manualAllowed('prompt_only', false)).toBe(true)
    expect(manualAllowed(undefined, true)).toBe(true) // an older server
    expect(promptAvailable('prompt', true)).toBe(true)
    expect(promptAvailable('prompt', false)).toBe(false)
    expect(promptAvailable('manual', true)).toBe(false)
  })

  it('turns a paid request into a payment that names it', () => {
    const c = { id: 'c1', status: 'success' as const, message: null, network: 'telecel', amount_minor: 5000, reference: '777', phone: '233201234567' }
    expect(finished(c)).toBe(true)
    expect(finished({ ...c, status: 'pending' })).toBe(false)
    expect(paymentFrom(c)).toEqual({ method: 'momo', provider: 'telecel', amount_minor: 5000, tendered_minor: 5000, reference: '777', payer_phone: '233201234567', momo_charge_id: 'c1' })
  })
})
