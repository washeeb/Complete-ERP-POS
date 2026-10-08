import { describe, expect, it } from 'vitest'
import { addSerial, normaliseSerial, warrantyUntil } from './electronics'

describe('electronics at the till', () => {
  it('normalises serial numbers like the server', () => {
    expect(normaliseSerial(' 3569 0101-0000001 ')).toBe('356901010000001')
    expect(normaliseSerial('ab12')).toBe('AB12')
  })

  it('adds scanned numbers once', () => {
    let r = addSerial([], '3569 01')
    expect(r).toEqual({ list: ['356901'], error: null })
    r = addSerial(r.list, '356901')
    expect(r.error).toBe('356901 is already on this sale.')
    expect(addSerial([], '  ').error).toBe('Scan or type the serial / IMEI number.')
  })

  it('works out the warranty end', () => {
    expect(warrantyUntil(12, new Date('2026-10-20T10:00:00'))).toBe('2027-10-20')
    expect(warrantyUntil(null)).toBeNull()
  })
})
