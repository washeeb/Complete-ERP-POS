import { useState } from 'react'
import { v7 as uuidv7 } from 'uuid'
import { api, ApiError } from '../api'
import { divRound, prettyQty, toMilli } from '../money'
import { getLocalShift, nextSeq } from '../db'
import type { Bootstrap, PostedSale, Returnable } from '../types'
import { SignedIn } from './SignedIn'

const METHOD: Record<string, string> = { cash: 'Cash', momo: 'Mobile money', card: 'Card', account: 'Customer account', points: 'Points', gift_card: 'Gift card', store_credit: 'Store credit', nhis: 'NHIS (comes off the next claim)' }

/**
 * Take goods back (owner decision 2026-10-11): against the receipt within the return period, refunded to how the customer
 * paid; without a receipt (or later), store credit with a supervisor's PIN. Returns need the server.
 */
export function ReturnScreen({ boot, money, onDone }: { boot: Bootstrap; money: (m: number) => string; onDone: (s: PostedSale | null) => void }) {
  const [receipt, setReceipt] = useState('')
  const [found, setFound] = useState<{ sale: PostedSale; returnable: Returnable } | null>(null)
  const [qty, setQty] = useState<Record<string, string>>({})
  const [refs, setRefs] = useState<Record<string, string>>({})
  const [reason, setReason] = useState('')
  const [supervisor, setSupervisor] = useState({ username: '', pin: '' })
  const [needsApproval, setNeedsApproval] = useState(false)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [credit, setCredit] = useState<string | null>(null)

  async function lookup(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    try {
      const r = await api.lookupReceipt(receipt.trim())
      setFound(r)
      setNeedsApproval(!r.returnable.within_period)
      setQty({})
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }

  // Value of what comes back: the price actually paid on each line, in proportion.
  const lines = found?.returnable.lines ?? []
  const value = lines.reduce((a, l) => {
    const q = qty[l.id] ? toMilli(qty[l.id]) : 0
    return a + (q > 0 ? divRound(l.line_total_minor * q, toMilli(l.qty)) : 0)
  }, 0)
  // Refund to the original tenders, in the order they were paid, up to what is still refundable on each.
  const tenders = (found?.returnable.tenders ?? []).filter((t) => t.refundable_minor > 0)
  const refunds = tenders.reduce<{ left: number; list: ((typeof tenders)[number] & { amount: number })[] }>((acc, t) => {
    const amount = Math.min(acc.left, t.refundable_minor)
    return { left: acc.left - amount, list: amount > 0 ? [...acc.list, { ...t, amount }] : acc.list }
  }, { left: value, list: [] }).list

  async function submit() {
    setBusy(true)
    setError('')
    try {
      const shift = boot.shift?.id ?? (await getLocalShift())?.shiftId
      const r = await api.postReturn({
        id: uuidv7(), device_seq: await nextSeq(boot.last_device_seq), occurred_at: new Date().toISOString(), shift_id: shift,
        original_sale_id: found?.sale.id ?? null, reason,
        lines: lines.filter((l) => qty[l.id] && toMilli(qty[l.id]) > 0).map((l) => ({ original_line_id: l.id, qty: qty[l.id] })),
        refunds: needsApproval ? [] : refunds.map((t) => ({ method: t.method, amount_minor: t.amount, reference: refs[t.method] || null })),
        ...(needsApproval ? { approval: supervisor } : {}),
      })
      if (r.store_credit_code) setCredit(r.store_credit_code)
      else onDone(r.sale)
    } catch (err) {
      if (err instanceof ApiError && err.code === 'return_needs_approval') setNeedsApproval(true)
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (credit) {
    return (
      <div className="modal" role="dialog" aria-modal="true" aria-label="Store credit issued">
        <div className="card pay">
          <h2>Store credit issued</h2>
          <p>Give the customer this code (it is also on the slip): <strong className="mono big">{credit}</strong></p>
          <div className="row"><button className="primary big" onClick={() => onDone(null)}>Done</button></div>
        </div>
      </div>
    )
  }

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Return" onKeyDown={(e) => e.key === 'Escape' && onDone(null)}>
      <div className="card pay">
        <h2>Return</h2>
        <form className="row wrap" onSubmit={lookup}>
          <input autoFocus aria-label="Receipt number" placeholder="Receipt number (scan or type)" className="mono" value={receipt} onChange={(e) => setReceipt(e.target.value)} />
          <button type="submit">Find receipt</button>
        </form>
        {found && (
          <>
            <p className="small muted">{found.sale.receipt_no} · {found.sale.occurred_at.replace('T', ' ').slice(0, 16)} · {money(found.sale.total_minor)}
              {!found.returnable.within_period && <strong className="error"> · past the return period — store credit only</strong>}</p>
            <table>
              <thead><tr><th>Item</th><th className="num">Sold</th><th className="num">Can return</th><th>Return</th></tr></thead>
              <tbody>{lines.map((l) => (
                <tr key={l.id}><td>{l.name}</td><td className="num">{prettyQty(toMilli(l.qty))} {l.uom}</td><td className="num">{prettyQty(toMilli(l.returnable_qty))}</td>
                  <td><input aria-label={`Return ${l.name}`} inputMode="decimal" style={{ width: '5em' }} value={qty[l.id] ?? ''} onChange={(e) => setQty({ ...qty, [l.id]: e.target.value })}
                    disabled={toMilli(l.returnable_qty) === 0} /></td></tr>
              ))}</tbody>
            </table>
            <p className="strong">Refund {money(value)}</p>
            {!needsApproval && refunds.map((t) => (
              <div key={t.method} className="row wrap">
                <span>{METHOD[t.method] ?? t.method}: {money(t.amount)}</span>
                {(t.method === 'momo' || t.method === 'card') && (
                  <input aria-label={`${METHOD[t.method]} refund reference`} placeholder="Refund reference" value={refs[t.method] ?? ''} onChange={(e) => setRefs({ ...refs, [t.method]: e.target.value })} />
                )}
              </div>
            ))}
          </>
        )}
        <label>Reason<input aria-label="Return reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} /></label>
        {needsApproval && (
          <div className="grid2">
            <SignedIn />
            <label>Supervisor username<input autoComplete="off" value={supervisor.username} onChange={(e) => setSupervisor({ ...supervisor, username: e.target.value })} autoCapitalize="none" /></label>
            <label>Supervisor PIN<input type="text" className="pin-mask" autoComplete="off" inputMode="numeric" value={supervisor.pin} onChange={(e) => setSupervisor({ ...supervisor, pin: e.target.value.replace(/\D/g, '') })} /></label>
          </div>
        )}
        {error && <p className="error" role="alert">{error}</p>}
        <div className="row">
          <button type="button" onClick={() => onDone(null)} disabled={busy}>Back (Esc)</button>
          <button className="primary big" onClick={submit} disabled={busy || !found || value <= 0 || !reason.trim()}>{busy ? 'Saving…' : 'Refund'}</button>
        </div>
      </div>
    </div>
  )
}
