import { describe, expect, it } from 'vitest'
import { expiryNote } from './expiry'

describe('expiry at the till', () => {
  const today = new Date(2026, 9, 22)
  it('shows the next batch expiry, amber within 90 days, red once past', () => {
    expect(expiryNote({ expires_on: '2027-06-30' }, today)).toEqual({ text: 'Exp 06/2027', soon: false, expired: false })
    expect(expiryNote({ expires_on: '2026-12-31' }, today)).toEqual({ text: 'Exp 12/2026', soon: true, expired: false })
    expect(expiryNote({ expires_on: '2026-10-01' }, today)).toEqual({ text: 'Expired 10/2026', soon: false, expired: true })
    expect(expiryNote({ expires_on: null }, today)).toBeNull()
  })
})
