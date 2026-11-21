import type { Reading, ReadingKind } from './types'

/** Tests read as a result, not a number (rapid tests); the same words as the back office. */
export const RESULTS: Partial<Record<ReadingKind, [string, string][]>> = {
  hiv: [['non_reactive', 'Non-reactive'], ['reactive', 'Reactive'], ['invalid', 'Invalid (test again)']],
  typhoid: [['negative', 'Negative'], ['positive', 'Positive'], ['invalid', 'Invalid (test again)']],
}

export const UNITS: Partial<Record<ReadingKind, string>> = { sugar: 'mmol/L', weight: 'kg', temperature: '°C', hb: 'g/dL' }

/**
 * What prints on the receipt line: the same words the back office shows. An HIV result is never printed (only that
 * the test was done), and a wound dressing's details stay in the patient's record: null puts no note on the line.
 */
export function readingNote(kind: ReadingKind, r: Reading): string | null {
  if (kind === 'bp') return `${r.systolic}/${r.diastolic} mmHg${r.pulse ? `, pulse ${r.pulse}` : ''}`
  if (kind === 'hiv') return 'HIV test done'
  if (kind === 'wound') return null
  if (kind === 'typhoid') return `Typhoid test: ${RESULTS.typhoid!.find(([k]) => k === r.result)?.[1] ?? ''}`
  return `${r.value} ${UNITS[kind]}`
}

/** The same limits as the server (App\\Domain\\Pharmacy\\HealthReadings): they prompt a referral, they are not a diagnosis. */
export function readingFlag(kind: ReadingKind, r: Reading): 'normal' | 'low' | 'high' | 'urgent' | 'retest' | 'done' {
  const v = r.value ?? 0
  if (kind === 'bp') {
    const [s, d] = [r.systolic ?? 0, r.diastolic ?? 0]
    return s >= 180 || d >= 120 ? 'urgent' : s >= 140 || d >= 90 ? 'high' : s < 90 || d < 60 ? 'low' : 'normal'
  }
  if (kind === 'sugar') return v >= 16.7 || v < 3 ? 'urgent' : v >= 11.1 ? 'high' : v < 3.9 ? 'low' : 'normal'
  if (kind === 'temperature') return v >= 39.5 || v < 35 ? 'urgent' : v >= 38 ? 'high' : v < 36 ? 'low' : 'normal'
  if (kind === 'hb') return v < 7 ? 'urgent' : v < 12 ? 'low' : v >= 18 ? 'high' : 'normal'
  if (kind === 'hiv') return r.result === 'reactive' ? 'urgent' : r.result === 'invalid' ? 'retest' : 'normal'
  if (kind === 'typhoid') return r.result === 'positive' ? 'high' : r.result === 'invalid' ? 'retest' : 'normal'
  if (kind === 'wound') return 'done'
  return 'normal'
}

/** Whether the prompt has what it needs for this kind. */
export function readingReady(kind: ReadingKind, r: Reading): boolean {
  if (kind === 'bp') return (r.systolic ?? 0) > (r.diastolic ?? 0) && (r.diastolic ?? 0) > 0
  if (RESULTS[kind]) return !!RESULTS[kind]!.find(([k]) => k === r.result)
  if (kind === 'wound') return (r.note ?? '').trim().length >= 3
  if (kind === 'hb') return (r.value ?? 0) >= 1 && (r.value ?? 0) <= 25
  return (r.value ?? 0) > 0
}
