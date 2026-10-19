import { useState } from 'react'
import { calculate } from '../calc'

/** Calculator (F1): type or tap a sum; "Use" hands the result to the field that opened it (e.g. cash received). */
export function Calculator({ onUse, useLabel, onClose }: { onUse?: (value: number) => void; useLabel?: string; onClose: () => void }) {
  const [expr, setExpr] = useState('')
  const [error, setError] = useState('')
  let result: number | null = null
  try { result = expr ? calculate(expr) : null } catch { result = null }
  const press = (k: string) => {
    setError('')
    if (k === 'C') return setExpr('')
    if (k === '⌫') return setExpr((e) => e.slice(0, -1))
    if (k === '=') {
      try { setExpr(String(calculate(expr))) } catch (e) { setError(e instanceof Error ? e.message : 'Check the sum') }
      return
    }
    setExpr((e) => e + k)
  }
  const keys = ['C', '(', ')', '⌫', '7', '8', '9', '÷', '4', '5', '6', '×', '1', '2', '3', '−', '0', '.', '%', '+']
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Calculator" onKeyDown={(e) => {
      if (e.key === 'Escape') onClose()
      if (e.key === 'Enter') { e.preventDefault(); press('=') }
    }}>
      <div className="card calc">
        <h2>Calculator</h2>
        <input autoFocus className="big mono calc-input" aria-label="Sum" value={expr} placeholder="0"
          onChange={(e) => { setError(''); setExpr(e.target.value.replace(/[^0-9+\-*/().%×÷−\s,]/g, '')) }} />
        <p className="calc-result mono" aria-live="polite">{error ? <span className="error">{error}</span> : result !== null ? `= ${result.toLocaleString('en-GB', { maximumFractionDigits: 6 })}` : ' '}</p>
        <div className="calc-keys">
          {keys.map((k) => <button key={k} type="button" className={'+−×÷'.includes(k) ? 'op' : ''} onClick={() => press(k)}>{k}</button>)}
          <button type="button" className="primary eq" onClick={() => press('=')}>=</button>
        </div>
        <div className="row">
          <button type="button" onClick={onClose}>Close (Esc)</button>
          {result !== null && <button type="button" onClick={() => void navigator.clipboard?.writeText(String(result))}>Copy</button>}
          {onUse && result !== null && result >= 0 && <button type="button" className="primary" onClick={() => { onUse(result!); onClose() }}>{useLabel ?? 'Use'}</button>}
        </div>
      </div>
    </div>
  )
}

export type SaleExtras = { age?: string; sex?: string; weight_kg?: string; pregnant?: boolean; guests?: string; next_visit?: string }
export type SaleDetailsState = { comment: string; onReceipt: boolean; name: string; phone: string; extras: SaleExtras }
export const emptyDetails = (): SaleDetailsState => ({ comment: '', onReceipt: false, name: '', phone: '', extras: {} })

/** What the branch wants before payment is met (a chosen customer counts). */
export function detailsMissing(need: string | undefined, d: SaleDetailsState, customer: { name: string; phone?: string | null } | null): string[] {
  const hasName = !!(customer?.name || d.name.trim())
  const hasPhone = !!(customer?.phone || d.phone.trim())
  return [need === 'name' || need === 'name_phone' ? (hasName ? null : 'name') : null, need === 'phone' || need === 'name_phone' ? (hasPhone ? null : 'phone') : null]
    .filter((x): x is string => !!x)
}

/**
 * Details of this sale: a comment (optionally printed), a walk-in's name and phone (no customer record needed), and the
 * industry's own details (pharmacy: age, sex, weight, pregnancy; restaurant: guests; services: next visit).
 */
export function SaleDetailsDialog({ value, message, hasCustomer, pharmacy, restaurant, services, onSave, onCancel }: {
  value: SaleDetailsState; message?: string; hasCustomer: boolean; pharmacy: boolean; restaurant: boolean; services: boolean
  onSave: (d: SaleDetailsState) => void; onCancel: () => void
}) {
  const [d, setD] = useState<SaleDetailsState>(value)
  const ex = (k: keyof SaleExtras, v: string | boolean) => setD({ ...d, extras: { ...d.extras, [k]: v } })
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Sale details" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={(e) => { e.preventDefault(); onSave(d) }}>
        <h2>Details</h2>
        {message && <p className="error" role="alert">{message}</p>}
        {!hasCustomer && (
          <div className="grid2">
            <label>Customer name (walk-in)<input autoFocus value={d.name} maxLength={120} onChange={(e) => setD({ ...d, name: e.target.value })} /></label>
            <label>Phone<input inputMode="tel" value={d.phone} maxLength={20} onChange={(e) => setD({ ...d, phone: e.target.value })} placeholder="024…" /></label>
          </div>
        )}
        {pharmacy && (
          <div className="grid2">
            <label>Age<input inputMode="numeric" value={d.extras.age ?? ''} onChange={(e) => ex('age', e.target.value.replace(/\D/g, '').slice(0, 3))} /></label>
            <label>Sex<select value={d.extras.sex ?? ''} onChange={(e) => ex('sex', e.target.value)}><option value="">—</option><option value="female">Female</option><option value="male">Male</option></select></label>
            <label>Weight (kg)<input inputMode="decimal" value={d.extras.weight_kg ?? ''} onChange={(e) => ex('weight_kg', e.target.value.replace(/[^\d.]/g, '').slice(0, 5))} /></label>
            <label className="inline"><input type="checkbox" checked={!!d.extras.pregnant} onChange={(e) => ex('pregnant', e.target.checked)} /> Pregnant or breastfeeding</label>
          </div>
        )}
        {restaurant && <label>Guests<input inputMode="numeric" value={d.extras.guests ?? ''} onChange={(e) => ex('guests', e.target.value.replace(/\D/g, '').slice(0, 2))} /></label>}
        {services && <label>Next visit<input type="date" value={d.extras.next_visit ?? ''} onChange={(e) => ex('next_visit', e.target.value)} /></label>}
        <label>Comment<textarea rows={2} maxLength={500} value={d.comment} onChange={(e) => setD({ ...d, comment: e.target.value })} placeholder="e.g. deliver after 4 pm, gift wrap, counselled on dosage" /></label>
        <label className="inline"><input type="checkbox" checked={d.onReceipt} onChange={(e) => setD({ ...d, onReceipt: e.target.checked })} /> Print the comment on the receipt</label>
        <div className="row">
          <button type="button" onClick={onCancel}>Back</button>
          <button className="primary">Save</button>
        </div>
      </form>
    </div>
  )
}

/** The details as the server expects them (empty fields left out). */
export function detailsPayload(d: SaleDetailsState, hasCustomer: boolean) {
  const extras = Object.fromEntries(Object.entries(d.extras).filter(([, v]) => v !== '' && v !== undefined && v !== false))
  return {
    ...(d.comment.trim() ? { comment: d.comment.trim(), comment_on_receipt: d.onReceipt } : {}),
    ...(!hasCustomer && (d.name.trim() || d.phone.trim()) ? { walk_in: { name: d.name.trim() || null, phone: d.phone.trim() || null } } : {}),
    ...(Object.keys(extras).length ? { extras } : {}),
  }
}
