import { describe, expect, it } from 'vitest'
import { staleShiftSince } from './shift'

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
