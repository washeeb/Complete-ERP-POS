import { describe, expect, it } from 'vitest'
import { scannedCode, type KeyStroke } from './scanner'

const typed = (text: string, gap: number, end = 'Enter'): KeyStroke[] =>
  [...text.split(''), end].map((key, i) => ({ key, at: i * gap }))

describe('scanner input', () => {
  it('reads a code a scanner typed fast and ended with Enter or Tab', () => {
    expect(scannedCode(typed('6001234500012', 8))).toBe('6001234500012')
    expect(scannedCode(typed('A-01-03', 10, 'Tab'))).toBe('A-01-03')
  })

  it('ignores a person typing, a short code and a missing end key', () => {
    expect(scannedCode(typed('6001234500012', 120))).toBeNull() // a person: slow keys
    expect(scannedCode(typed('12', 5))).toBeNull()
    expect(scannedCode(typed('6001234500012', 8, 'x').slice(0, -1))).toBeNull()
    expect(scannedCode([{ key: 'ArrowDown', at: 0 }, ...typed('ABCD', 5).map((k) => ({ ...k, at: k.at + 5 }))])).toBeNull() // a special key in between
  })
})
