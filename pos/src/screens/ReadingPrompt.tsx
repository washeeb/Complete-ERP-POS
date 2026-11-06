import { useState } from 'react'
import { readingFlag, readingNote } from '../reading'
import type { Reading, ReadingKind } from '../types'

const WORDS = { normal: null, low: 'Low: consider referring to a clinic.', high: 'High: advise them to see a clinic.', urgent: 'Refer now: advise them to go to a clinic or hospital today.' }

/** A health check at the till: the reading goes with the sale. Can be skipped: the fee is still charged, and the reading is on no record. */
export function ReadingPrompt({ kind, name, onDone, onCancel }: { kind: ReadingKind; name: string; onDone: (r: Reading | null, note: string | null) => void; onCancel: () => void }) {
  const [a, setA] = useState('')
  const [b, setB] = useState('')
  const [c, setC] = useState('')
  const reading: Reading = kind === 'bp' ? { systolic: Number(a), diastolic: Number(b), ...(c ? { pulse: Number(c) } : {}) } : { value: Number(a) }
  const ready = kind === 'bp' ? Number(a) > Number(b) && Number(b) > 0 : Number(a) > 0
  const advice = ready ? WORDS[readingFlag(kind, reading)] : null
  const unit = { bp: '', sugar: 'mmol/L', weight: 'kg', temperature: '°C' }[kind]
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
        ) : (
          <label>Reading ({unit})<input autoFocus inputMode="decimal" aria-label="Reading" value={a} onChange={(e) => setA(e.target.value)} /></label>
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
