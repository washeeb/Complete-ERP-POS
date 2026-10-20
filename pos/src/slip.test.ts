import { describe, expect, it } from 'vitest'
import { slipEscPos, slipRef, slipRows, slipSignatures, type DrawerSlip } from './slip'
import type { Bootstrap } from './types'

const boot = { company: { name: 'Oheneba Demo', currency: 'GHS', exponent: 2, timezone: 'Africa/Accra' }, branch: { code: 'HQ', name: 'Head Office' },
  till: { code: 'T01' } } as unknown as Bootstrap
const ascii = (b: Uint8Array) => Array.from(b).map((c) => (c >= 0x20 && c < 0x7f ? String.fromCharCode(c) : c === 0x0a ? '\n' : '·')).join('')
const drop: DrawerSlip = { id: '01a15a00-0000-7000-8000-00000abc1234', kind: 'drop', amountMinor: 150000, at: '2026-10-20T15:30:00Z', cashier: 'Esi', approvedBy: 'Kojo', queued: false }

describe('drawer slips', () => {
  it('prints a cash drop with the amount, a short reference and both signatures', () => {
    const text = ascii(slipEscPos(drop, boot).toBytes())
    expect(text).toContain('CASH DROP')
    expect(text).toContain('GHS 1,500.00')
    expect(text).toContain(slipRef(drop.id))
    expect(text).toContain('Taken by')
    expect(slipSignatures(drop)).toEqual(['Cashier', 'Supervisor (took the cash)'])
    expect(slipRef(drop.id)).toBe('0ABC1234')
  })

  it('lists what a pay-out was for and who received it; long text goes on its own line', () => {
    const payout: DrawerSlip = { ...drop, kind: 'payout', amountMinor: 4500, approvedBy: null, category: 'Transport and fuel', payee: 'Taxi',
      reason: 'Taxi to the bank and back with the day takings for the deposit', reference: 'R-12', queued: true }
    expect(slipRows(payout, boot).map(([l]) => l)).toEqual(['Ref', 'Date', 'Till', 'Spent on', 'Paid to', 'Receipt no.', 'For', 'Cashier'])
    expect(slipSignatures(payout)).toEqual(['Cashier', 'Received by'])
    const text = ascii(slipEscPos(payout, boot).toBytes())
    expect(text).toContain('PAY-OUT (EXPENSE)')
    expect(text).toContain('For:\n  Taxi to the bank')
    expect(text).toContain('Saved on the till offline')
  })
})
