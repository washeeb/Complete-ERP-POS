import { describe, expect, it } from 'vitest'
import { readingFlag, readingNote, readingReady } from './reading'

describe('health check readings', () => {
  it('words the reading for the receipt', () => {
    expect(readingNote('bp', { systolic: 128, diastolic: 82, pulse: 74 })).toBe('128/82 mmHg, pulse 74')
    expect(readingNote('sugar', { value: 5.4 })).toBe('5.4 mmol/L')
  })

  it('flags as the server does', () => {
    expect(readingFlag('bp', { systolic: 120, diastolic: 80 })).toBe('normal')
    expect(readingFlag('bp', { systolic: 150, diastolic: 80 })).toBe('high')
    expect(readingFlag('bp', { systolic: 185, diastolic: 95 })).toBe('urgent')
    expect(readingFlag('bp', { systolic: 85, diastolic: 55 })).toBe('low')
    expect(readingFlag('sugar', { value: 12 })).toBe('high')
    expect(readingFlag('temperature', { value: 37 })).toBe('normal')
    expect(readingFlag('weight', { value: 70 })).toBe('normal')
  })

  it('reads the new tests: HB in g/dL, HIV and typhoid as a result, a wound dressing as what was done', () => {
    expect(readingNote('hb', { value: 12.5 })).toBe('12.5 g/dL')
    expect(readingFlag('hb', { value: 6.5 })).toBe('urgent')
    expect(readingFlag('hb', { value: 10 })).toBe('low')
    expect(readingFlag('hb', { value: 14 })).toBe('normal')
    expect(readingFlag('typhoid', { result: 'positive' })).toBe('high')
    expect(readingNote('typhoid', { result: 'negative' })).toBe('Typhoid test: Negative')
    expect(readingFlag('hiv', { result: 'reactive' })).toBe('urgent')
    expect(readingFlag('hiv', { result: 'invalid' })).toBe('retest')
    expect(readingReady('hiv', {})).toBe(false)
    expect(readingReady('wound', { note: 'left leg dressed' })).toBe(true)
    expect(readingNote('wound', { note: 'left leg dressed' })).toBeNull()
  })

  it('never prints an HIV result on the receipt, whatever it is', () => {
    for (const result of ['non_reactive', 'reactive', 'invalid']) expect(readingNote('hiv', { result })).toBe('HIV test done')
  })
})
