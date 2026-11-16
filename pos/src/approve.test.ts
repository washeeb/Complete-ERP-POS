import { describe, expect, it } from 'vitest'
import type { TillApprovalState } from './api'
import { raisePeaks, removedSince, settlePeaks, voidNeedsApproval, waitForAnswer } from './approve'

const state = (status: TillApprovalState['status']): TillApprovalState => ({ id: 'r1', kind: 'no_sale', status, decided_by: status === 'approved' ? 'Ama' : null, note: null, expires_at: '' })

describe('waitForAnswer', () => {
  it('keeps checking while the manager has not answered, and ignores a missed check', async () => {
    const answers: (TillApprovalState | Error)[] = [state('pending'), new Error('offline'), state('pending'), state('approved')]
    const r = await waitForAnswer('r1', async () => { const a = answers.shift()!; if (a instanceof Error) throw a; return a }, { sleep: async () => {} })
    expect(r.status).toBe('approved')
    expect(r.decided_by).toBe('Ama')
    expect(answers).toHaveLength(0)
  })

  it('stops when the cashier cancels', async () => {
    const ctl = new AbortController()
    const p = waitForAnswer('r1', async () => state('pending'), { signal: ctl.signal, sleep: async () => { ctl.abort() } })
    await expect(p).rejects.toThrow('Cancelled')
  })
})

describe('removing items from a sale', () => {
  const rice = { key: 'a', name: 'Rice 5 kg', qtyMilli: 2000, totalMinor: 24000 }
  const oil = { key: 'b', name: 'Oil 1 L', qtyMilli: 1000, totalMinor: 3500 }

  it('counts lines taken off and quantities lowered, at their price', () => {
    const peaks = raisePeaks({}, [rice, oil])
    const removed = removedSince(peaks, [{ key: 'a', qtyMilli: 1000 }])
    expect(removed).toEqual([{ key: 'a', name: 'Rice 5 kg', qtyMilli: 1000, amountMinor: 12000 }, { key: 'b', name: 'Oil 1 L', qtyMilli: 1000, amountMinor: 3500 }])
    expect(voidNeedsApproval(removed, 0)).toBe(true)
    expect(voidNeedsApproval(removed, 20000)).toBe(false)
    expect(voidNeedsApproval(removed, null)).toBe(false) // the company does not ask

    // Approved: those removals are settled, a later one is asked about again.
    const settled = settlePeaks(peaks, [{ key: 'a', qtyMilli: 1000 }])
    expect(removedSince(settled, [{ key: 'a', qtyMilli: 1000 }])).toEqual([])
    expect(removedSince(settled, [])).toEqual([{ key: 'a', name: 'Rice 5 kg', qtyMilli: 1000, amountMinor: 12000 }])
  })

  it('does not count typing over a quantity that ends higher', () => {
    let peaks = raisePeaks({}, [{ ...rice, qtyMilli: 10000, totalMinor: 120000 }])
    peaks = raisePeaks(peaks, [{ ...rice, qtyMilli: 1000, totalMinor: 12000 }]) // "10" → "1" while typing "12"
    peaks = raisePeaks(peaks, [{ ...rice, qtyMilli: 12000, totalMinor: 144000 }])
    expect(removedSince(peaks, [{ key: 'a', qtyMilli: 12000 }])).toEqual([])
  })
})
