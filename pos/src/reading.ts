import type { Reading, ReadingKind } from './types'

/** What prints on the receipt line: the same words the back office shows. */
export function readingNote(kind: ReadingKind, r: Reading): string {
  if (kind === 'bp') return `${r.systolic}/${r.diastolic} mmHg${r.pulse ? `, pulse ${r.pulse}` : ''}`
  return `${r.value} ${{ sugar: 'mmol/L', weight: 'kg', temperature: '°C' }[kind]}`
}

/** The same limits as the server (App\\Domain\\Pharmacy\\HealthReadings): they prompt a referral, they are not a diagnosis. */
export function readingFlag(kind: ReadingKind, r: Reading): 'normal' | 'low' | 'high' | 'urgent' {
  const v = r.value ?? 0
  if (kind === 'bp') {
    const [s, d] = [r.systolic ?? 0, r.diastolic ?? 0]
    return s >= 180 || d >= 120 ? 'urgent' : s >= 140 || d >= 90 ? 'high' : s < 90 || d < 60 ? 'low' : 'normal'
  }
  if (kind === 'sugar') return v >= 16.7 || v < 3 ? 'urgent' : v >= 11.1 ? 'high' : v < 3.9 ? 'low' : 'normal'
  if (kind === 'temperature') return v >= 39.5 || v < 35 ? 'urgent' : v >= 38 ? 'high' : v < 36 ? 'low' : 'normal'
  return 'normal'
}
