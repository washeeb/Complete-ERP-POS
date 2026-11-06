import { describe, expect, it } from 'vitest'
import { readingFlag, readingNote } from './reading'

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
})
