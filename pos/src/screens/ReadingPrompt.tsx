import { useState } from 'react'
import { readingFlag, readingNote, readingReady, RESULTS, UNITS } from '../reading'
import type { Reading, ReadingKind } from '../types'

const WORDS = {
  normal: null, done: null, low: 'Low: consider referring to a clinic.', high: 'High: advise them to see a clinic.',
  urgent: 'Refer now: advise them to go to a clinic or hospital today.', retest: 'The test did not work: test again with a new kit.',
}

/** A reactive HIV test: what the staff do next (the result itself never prints). */
const HIV_REACTIVE = 'Reactive: this is a screening result, not a diagnosis. Counsel the person privately and refer them for a confirmatory test today.'

/** A health check at the till: the reading goes with the sale. Can be skipped: the fee is still charged, and the reading is on no record. */
export function ReadingPrompt({ kind, name, onDone, onCancel }: { kind: ReadingKind; name: string; onDone: (r: Reading | null, note: string | null) => void; onCancel: () => void }) {
  const [a, setA] = useState('')
  const [b, setB] = useState('')
  const [c, setC] = useState('')
  const [result, setResult] = useState('')
  const [note, setNote] = useState('')
  const results = RESULTS[kind]
  const reading: Reading = kind === 'bp' ? { systolic: Number(a), diastolic: Number(b), ...(c ? { pulse: Number(c) } : {}) }
    : results ? { result } : kind === 'wound' ? { note } : { value: Number(a) }
  const ready = readingReady(kind, reading)
  const advice = !ready ? null : kind === 'hiv' && result === 'reactive' ? HIV_REACTIVE : WORDS[readingFlag(kind, reading)]
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={name} onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <div className="card pay">
        <h2>{name}</h2>
        {kind === 'bp' ? (
          <div className="grid2">
            <label>Top number<input autoFocus inputMode="numeric" aria-label="Top number" value={a} onChange={(e) => setA(e.target.value)} /></label>
            <label>Bottom number<input inputMode="numeric" aria-label="Bottom number" value={b} onChange={(e) => setB(e.target.value)} /></label>
            <label>Pulse (optional)<input inputMode="numeric" aria-label="Pulse" value={c} onChange={(e) => setC(e.target.value)} /></label>
          </div>
        ) : results ? (
          <fieldset className="choices" aria-label="Result">
            <legend>Result</legend>
            {results.map(([k, l], i) => (
              <button key={k} type="button" autoFocus={i === 0} className={result === k ? 'active' : undefined} aria-pressed={result === k} onClick={() => setResult(k)}>{l}</button>
            ))}
          </fieldset>
        ) : null}
        {kind === 'hiv' && <p className="muted">Only “HIV test done” prints on the receipt; the result is kept in the patient's record.</p>}
        {results || kind === 'bp' ? null : kind === 'wound' ? (
          <label>What was done<input autoFocus aria-label="What was done" maxLength={255} placeholder="e.g. left leg cleaned and dressed" value={note} onChange={(e) => setNote(e.target.value)} /></label>
        ) : (
          <label>Reading ({UNITS[kind]})<input autoFocus inputMode="decimal" aria-label="Reading" value={a} onChange={(e) => setA(e.target.value)} /></label>
        )}
        {advice && <p className="error" role="alert">{advice}</p>}
        <div className="row">
          <button type="button" onClick={onCancel}>Back (Esc)</button>
          <button type="button" onClick={() => onDone(null, null)}>Add without a reading</button>
          <button type="button" className="primary big" disabled={!ready} onClick={() => onDone(reading, readingNote(kind, reading))}>Add</button>
        </div>
      </div>
    </div>
  )
}
