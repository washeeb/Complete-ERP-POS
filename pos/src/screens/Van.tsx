import { useState } from 'react'
import { stopsToday, visitedToday } from '../distribution'
import { recordVisit } from '../visits'
import { prettyQty, toMilli } from '../money'
import type { Product, TillCustomer, VanDay, VisitOutcome } from '../types'

const errorText = (e: unknown) => (e instanceof Error ? e.message : String(e))
const OUTCOME: Record<VisitOutcome, string> = { sold: 'Sold', order: 'Order taken', no_order: 'No order', closed: 'Closed' }

/**
 * Today's route on the rep's phone: the customers to visit, in order, and what happened at each. From here the rep
 * sells to a customer, takes an order for delivery, or records "no order" / "closed" — with the phone's GPS position.
 */
export function RouteScreen({ day, customers, online, onSell, onOrder, onClose }: {
  day: VanDay; customers: TillCustomer[]; online: boolean
  onSell: (c: TillCustomer, routeId: string) => void; onOrder: (c: TillCustomer, routeId: string) => void; onClose: () => void
}) {
  const [visited, setVisited] = useState(visitedToday())
  const [error, setError] = useState('')
  const [busy, setBusy] = useState<string | null>(null)
  const stops = stopsToday(day, visited)
  async function mark(customerId: string, routeId: string, outcome: VisitOutcome) {
    const note = window.prompt(outcome === 'closed' ? 'Note (optional), e.g. "closed for a funeral"' : 'Why no order? (optional)') ?? ''
    setBusy(customerId)
    setError('')
    try { setVisited(await recordVisit(online, customerId, routeId, outcome, { note: note || null })) } catch (e) { setError(errorText(e)) } finally { setBusy(null) }
  }
  const done = stops.filter((s) => s.outcome).length
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Today's route">
      <div className="card wide">
        <div className="row spread">
          <h2>Today's route <span className="muted small">{day.routes.map((r) => r.name).join(' · ') || 'No route today'} · {done} of {stops.length} visited</span></h2>
          <button type="button" onClick={onClose}>Back to till</button>
        </div>
        {error && <p className="error" role="alert">{error}</p>}
        <ul className="bill-items">
          {stops.map((s) => {
            const c = customers.find((x) => x.id === s.customer_id)
            return (
              <li key={`${s.route_id}:${s.customer_id}`}>
                <span><strong>{s.sequence}. {c?.name ?? 'Customer not on this phone yet — refresh'}</strong>{c?.phone ? <span className="small muted"> · {c.phone}</span> : null}</span>
                <span className="row wrap">
                  {s.outcome && <span className="chip small">{OUTCOME[s.outcome]}</span>}
                  {c && <button type="button" className="primary" onClick={() => onSell(c, s.route_id)}>Sell</button>}
                  {c && <button type="button" onClick={() => onOrder(c, s.route_id)}>Take order</button>}
                  <button type="button" disabled={busy === s.customer_id} onClick={() => mark(s.customer_id, s.route_id, 'no_order')}>No order</button>
                  <button type="button" disabled={busy === s.customer_id} onClick={() => mark(s.customer_id, s.route_id, 'closed')}>Closed</button>
                </span>
              </li>
            )
          })}
          {stops.length === 0 && <li className="muted">No customers to visit today on this van's routes.</li>}
        </ul>
      </div>
    </div>
  )
}

/** Empties handed back: a container and how many — added as a minus line at the deposit price. */
export function EmptiesDialog({ containers, onAdd, onCancel }: { containers: Product[]; onAdd: (p: Product, qtyMilli: number) => void; onCancel: () => void }) {
  const [id, setId] = useState(containers[0]?.id ?? '')
  const [qty, setQty] = useState('')
  const p = containers.find((c) => c.id === id)
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Empties returned" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card" onSubmit={(e) => { e.preventDefault(); if (p && toMilli(qty) > 0) onAdd(p, toMilli(qty)) }}>
        <h2>Empties returned</h2>
        <p className="muted small">The deposit is taken off this sale (or paid out in cash if nothing is bought).</p>
        <label>Container<select value={id} onChange={(e) => setId(e.target.value)}>
          {containers.map((c) => <option key={c.id} value={c.id}>{c.name}{c.on_hand !== null ? ` (${prettyQty(toMilli(c.on_hand))} on board)` : ''}</option>)}
        </select></label>
        <label>How many<input inputMode="numeric" value={qty} onChange={(e) => setQty(e.target.value)} autoFocus required /></label>
        <div className="row"><button type="button" onClick={onCancel}>Cancel</button><button className="primary">Take back</button></div>
      </form>
    </div>
  )
}
