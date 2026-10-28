import { describe, expect, it } from 'vitest'
import { shiftClosedOnServer, staleShiftSince } from './shift'

describe('staleShiftSince', () => {
  const now = new Date(2026, 9, 27, 9, 0) // 27 Oct, 09:00 local

  it('flags a shift opened yesterday morning and never closed', () => {
    expect(staleShiftSince(new Date(2026, 9, 26, 8, 0).toISOString(), now)).toBe(true)
  })

  it('leaves a night shift that started late yesterday alone, and today\'s shift', () => {
    expect(staleShiftSince(new Date(2026, 9, 26, 22, 0).toISOString(), now)).toBe(false)
    expect(staleShiftSince(new Date(2026, 9, 27, 7, 30).toISOString(), now)).toBe(false)
    expect(staleShiftSince(undefined, now)).toBe(false)
  })
})

describe('shiftClosedOnServer', () => {
  it('drops the till\'s note when the server has no open shift and none is waiting to be sent', () => {
    expect(shiftClosedOnServer(true, false, 's1', [])).toBe(true)
  })

  it('keeps it offline, while the server has a shift, or while a shift opened offline is still waiting to be sent', () => {
    expect(shiftClosedOnServer(false, false, 's1', [])).toBe(false)
    expect(shiftClosedOnServer(true, true, 's1', [])).toBe(false)
    expect(shiftClosedOnServer(true, false, 's1', ['s1'])).toBe(false)
    expect(shiftClosedOnServer(true, false, undefined, [])).toBe(false)
  })
})
