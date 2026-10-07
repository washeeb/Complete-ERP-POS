import { useCallback, useEffect, useMemo, useState } from 'react'
import { v7 as uuidv7 } from 'uuid'
import { ApiError, dining, type NewDiningItem } from '../api'
import { search } from '../cart'
import { prettyQty, toMilli } from '../money'
import { describeChoices, groupsFor, heldCourses, kitchenNote, minutesSince, modifierError, unpaid } from '../restaurant'
import type { DiningItem, DiningOrder, DiningTable, Floor as FloorData, Product, Restaurant } from '../types'

export const COURSES = ['Now', 'Starter', 'Main', 'Dessert'] as const
const STATUS: Record<DiningItem['status'], string> = { held: 'Waiting', fired: 'In kitchen', ready: 'Ready', served: 'Served', void: 'Voided' }
const errorText = (e: unknown) => (e instanceof Error ? e.message : String(e))

/** Reload a view when a bill changes elsewhere (live notice), and every few seconds in case the socket is down. */
function useDiningRefresh(reload: () => void, everyMs = 10_000) {
  useEffect(() => {
    const on = () => reload()
    window.addEventListener('dining-changed', on)
    const id = setInterval(reload, everyMs)
    return () => {
      window.removeEventListener('dining-changed', on)
      clearInterval(id)
    }
  }, [reload, everyMs])
}

export type Choice = { optionIds: string[]; names: string[]; chargedProductIds: string[]; note: string | null; course: number; seat: number | null; hold: boolean; qtyMilli: number }

/** Choices for a dish (spice, sides, extras), and for table service its course, seat and whether to hold it. */
export function ModifierPicker({ product, restaurant, tableService, onDone, onCancel }: {
  product: Product; restaurant: Restaurant; tableService: boolean; onDone: (c: Choice) => void; onCancel: () => void
}) {
  const groups = groupsFor(product, restaurant.modifier_groups)
  const [chosen, setChosen] = useState<string[]>([])
  const [note, setNote] = useState('')
  const [course, setCourse] = useState(0)
  const [seat, setSeat] = useState('')
  const [hold, setHold] = useState(false)
  const [qty, setQty] = useState('1')
  const [error, setError] = useState('')
  function toggle(groupMax: number, groupIds: string[], id: string) {
    setChosen((c) => {
      if (c.includes(id)) return c.filter((x) => x !== id)
      const mine = c.filter((x) => groupIds.includes(x))
      return groupMax === 1 ? [...c.filter((x) => !groupIds.includes(x)), id] : mine.length >= groupMax ? c : [...c, id]
    })
  }
  function submit(e: React.FormEvent) {
    e.preventDefault()
    const problem = modifierError(product, restaurant.modifier_groups, chosen)
    if (problem) return setError(problem)
    const milli = toMilli(qty || '0')
    if (milli < 1000 || milli % 1000) return setError('Enter a whole number of portions.')
    const d = describeChoices(product, restaurant.modifier_groups, chosen)
    onDone({ optionIds: chosen, names: d.names, chargedProductIds: d.chargedProductIds, note: note.trim() || null, course, seat: seat ? Number(seat) : null, hold, qtyMilli: milli })
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={`Choices for ${product.name}`} onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={submit}>
        <h2>{product.name}</h2>
        {error && <p className="error" role="alert">{error}</p>}
        {groups.map((g) => (
          <fieldset key={g.id} className="choices">
            <legend>{g.name} <span className="muted small">{g.min > 0 ? `choose ${g.min === g.max ? g.min : `${g.min}–${g.max}`}` : `optional, up to ${g.max}`}</span></legend>
            {g.options.map((o) => (
              <button key={o.id} type="button" aria-pressed={chosen.includes(o.id)} className={chosen.includes(o.id) ? 'active' : ''}
                onClick={() => toggle(g.max, g.options.map((x) => x.id), o.id)}>{o.name}{o.product_id ? ' +' : ''}</button>
            ))}
          </fieldset>
        ))}
        <div className="row">
          <label>Portions<input className="mono" inputMode="numeric" value={qty} onChange={(e) => setQty(e.target.value)} /></label>
          {tableService && <label>Seat<input inputMode="numeric" value={seat} onChange={(e) => setSeat(e.target.value.replace(/\D/g, '').slice(0, 2))} /></label>}
        </div>
        {tableService && (
          <div className="row">
            <div className="segmented" role="tablist" aria-label="Course">
              {COURSES.map((c, i) => <button key={c} type="button" role="tab" aria-selected={course === i} className={course === i ? 'active' : ''} onClick={() => setCourse(i)}>{c}</button>)}
            </div>
            <label className="inline"><input type="checkbox" checked={hold} onChange={(e) => setHold(e.target.checked)} /> Hold until called</label>
          </div>
        )}
        <label>Note for the kitchen<input value={note} onChange={(e) => setNote(e.target.value)} maxLength={200} placeholder="e.g. well done, no salt" /></label>
        <div className="row"><button type="button" onClick={onCancel}>Back</button><button className="primary big">Add</button></div>
      </form>
    </div>
  )
}

/** The floor: tables on the plan (free or with a bill), open bar tabs and counter orders. */
export function FloorView({ onOpenBill, onClose }: { onOpenBill: (o: DiningOrder) => void; onClose: () => void }) {
  const [floor, setFloor] = useState<FloorData | null>(null)
  const [error, setError] = useState('')
  const [opening, setOpening] = useState<{ table?: DiningTable; tab?: boolean } | null>(null)
  const [now, setNow] = useState(Date.now())
  const load = useCallback(() => {
    dining.floor().then((f) => { setFloor(f); setError(''); setNow(Date.now()) }).catch((e) => setError(errorText(e)))
  }, [])
  useEffect(load, [load])
  useDiningRefresh(load)
  const byTable = useMemo(() => new Map((floor?.orders ?? []).filter((o) => o.dining_table_id).map((o) => [o.dining_table_id!, o])), [floor])
  const areas = useMemo(() => [...new Set((floor?.tables ?? []).map((t) => t.area ?? ''))], [floor])

  async function open(body: Parameters<typeof dining.open>[0]) {
    try {
      const { order } = await dining.open(body)
      setOpening(null)
      onOpenBill(order)
    } catch (e) {
      setError(errorText(e))
      setOpening(null)
      load()
    }
  }

  return (
    <div className="modal floor" role="dialog" aria-modal="true" aria-label="Tables">
      <div className="card wide">
        <div className="row spread"><h2>Tables</h2><button type="button" onClick={onClose}>Back to till</button></div>
        {error && <p className="error" role="alert">{error}</p>}
        {!floor ? <p className="muted">Loading the floor…</p> : (
          <>
            {areas.map((area) => (
              <section key={area}>
                {area && <h3>{area}</h3>}
                <div className="floor-grid">
                  {floor.tables.filter((t) => (t.area ?? '') === area).map((t) => {
                    const o = byTable.get(t.id)
                    const ready = o?.items.some((i) => i.status === 'ready')
                    return (
                      <button key={t.id} type="button" style={{ gridColumn: t.pos_x + 1, gridRow: t.pos_y + 1 }}
                        className={`table-tile ${o ? 'busy' : 'free'} ${ready ? 'ready' : ''}`} aria-label={`Table ${t.code}${o ? ', open bill' : ', free'}`}
                        onClick={() => (o ? onOpenBill(o) : setOpening({ table: t }))}>
                        <strong>{t.code}</strong>
                        <span className="small">{o ? `${unpaid(o).length} item(s) · ${minutesSince(o.opened_at, now)} min` : `${t.seats} seats`}</span>
                        {ready && <span className="small">Food ready</span>}
                      </button>
                    )
                  })}
                </div>
              </section>
            ))}
            <h3>Bar tabs and counter</h3>
            <div className="row wrap">
              {floor.orders.filter((o) => !o.dining_table_id).map((o) => (
                <button key={o.id} type="button" className="table-tile busy" onClick={() => onOpenBill(o)}>
                  <strong>{o.label}</strong><span className="small">{unpaid(o).length} item(s)</span>
                </button>
              ))}
              <button type="button" onClick={() => setOpening({ tab: true })}>New bar tab</button>
            </div>
          </>
        )}
      </div>
      {opening && <OpenBill table={opening.table} onOpen={(covers, name) => open(opening.table
        ? { id: uuidv7(), kind: 'table', dining_table_id: opening.table.id, covers }
        : { id: uuidv7(), kind: 'tab', tab_name: name, covers })} onCancel={() => setOpening(null)} />}
    </div>
  )
}

function OpenBill({ table, onOpen, onCancel }: { table?: DiningTable; onOpen: (covers: number, name: string) => void; onCancel: () => void }) {
  const [covers, setCovers] = useState(table ? String(Math.min(2, table.seats)) : '1')
  const [name, setName] = useState('')
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Open a bill" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={(e) => { e.preventDefault(); if (table || name.trim()) onOpen(Number(covers) || 0, name.trim()) }}>
        <h2>{table ? `Table ${table.code}` : 'New bar tab'}</h2>
        {!table && <label>Name on the tab<input autoFocus value={name} onChange={(e) => setName(e.target.value)} maxLength={60} /></label>}
        <label>Guests<input autoFocus={!!table} inputMode="numeric" value={covers} onChange={(e) => setCovers(e.target.value.replace(/\D/g, '').slice(0, 2))} /></label>
        <div className="row"><button type="button" onClick={onCancel}>Back</button><button className="primary big">Open bill</button></div>
      </form>
    </div>
  )
}

type Pending = NewDiningItem & { name: string; names: string[] }

/** One bill: what is on it, what the kitchen is doing, new items to send, and paying (all or some items). */
export function BillView({ initial, products, restaurant, onPay, onBack }: {
  initial: DiningOrder; products: Product[]; restaurant: Restaurant
  onPay: (order: DiningOrder, items: DiningItem[]) => void; onBack: () => void
}) {
  const [order, setOrder] = useState(initial)
  const [pending, setPending] = useState<Pending[]>([])
  const [query, setQuery] = useState('')
  const [picking, setPicking] = useState<Product | null>(null)
  const [selected, setSelected] = useState<string[]>([])
  const [voiding, setVoiding] = useState<{ message: string; needsApproval: boolean } | null>(null)
  const [moving, setMoving] = useState<DiningTable[] | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [now, setNow] = useState(Date.now())
  const results = useMemo(() => search(products.filter((p) => !p.drug), query, 12), [products, query])
  const reload = useCallback(() => {
    dining.order(order.id).then((r) => { setOrder(r.order); setNow(Date.now()) }).catch(() => undefined)
  }, [order.id])
  useDiningRefresh(reload)
  const tableService = order.kind === 'table' || order.kind === 'tab'
  const left = unpaid(order)

  async function run(f: () => Promise<{ order: DiningOrder }>) {
    setBusy(true)
    setError('')
    try {
      const r = await f()
      setOrder(r.order)
      setSelected([])
      return true
    } catch (e) {
      setError(errorText(e))
      return false
    } finally {
      setBusy(false)
    }
  }

  function addPicked(product: Product, c: Choice) {
    setPending((p) => [...p, { id: uuidv7(), product_id: product.id, product_uom_id: (product.units.find((u) => u.is_base) ?? product.units[0]).id,
      qty: String(c.qtyMilli / 1000), modifier_option_ids: c.optionIds, note: c.note, course: c.course, seat: c.seat, hold: c.hold, name: product.name, names: c.names }])
    setPicking(null)
    setQuery('')
  }
  function pick(product: Product) {
    if (groupsFor(product, restaurant.modifier_groups).length || tableService) return setPicking(product)
    addPicked(product, { optionIds: [], names: [], chargedProductIds: [], note: null, course: 0, seat: null, hold: false, qtyMilli: 1000 })
  }
  async function send() {
    if (await run(() => dining.addItems(order.id, pending.map((i) => ({ id: i.id, product_id: i.product_id, product_uom_id: i.product_uom_id, qty: i.qty, modifier_option_ids: i.modifier_option_ids, note: i.note, course: i.course, seat: i.seat, hold: i.hold }))))) setPending([])
  }
  async function voidSelected(reason: string, approval: { username: string; pin: string } | null) {
    try {
      setBusy(true)
      const r = await dining.void(order.id, selected, reason, approval)
      setOrder(r.order)
      setSelected([])
      setVoiding(null)
    } catch (e) {
      const needs = e instanceof ApiError && ['void_needs_approval', 'approval_failed', 'over_limit', 'own_document', 'pin_locked'].includes(e.code)
      setVoiding({ message: errorText(e), needsApproval: needs || !!approval })
    } finally {
      setBusy(false)
    }
  }
  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))
  const byCourse = [...new Set(order.items.map((i) => i.course))].sort((a, b) => a - b)
  const paySet = selected.length ? left.filter((i) => selected.includes(i.id)) : left

  return (
    <div className="modal bill" role="dialog" aria-modal="true" aria-label={`Bill ${order.label}`}>
      <div className="card wide">
        <div className="row spread">
          <h2>{order.label} <span className="muted small">{order.number} · {order.waiter}{order.covers ? ` · ${order.covers} guests` : ''}</span></h2>
          <button type="button" onClick={onBack} disabled={pending.length > 0} title={pending.length ? 'Send or remove the new items first' : undefined}>Back to tables</button>
        </div>
        {error && <p className="error" role="alert">{error}</p>}
        <div className="bill-cols">
          <section aria-label="On the bill">
            {order.items.length === 0 && <p className="muted">Nothing ordered yet.</p>}
            {byCourse.map((c) => (
              <div key={c}>
                <h4>{COURSES[c] ?? `Course ${c}`}</h4>
                <ul className="bill-items">
                  {order.items.filter((i) => i.course === c).map((i) => (
                    <li key={i.id} className={`st-${i.status}${i.paid ? ' paid' : ''}`}>
                      <label>
                        {i.status !== 'void' && !i.paid && <input type="checkbox" checked={selected.includes(i.id)} onChange={() => toggle(i.id)} aria-label={`Select ${i.name}`} />}
                        <span>{prettyQty(toMilli(i.qty))} × {i.name}{i.seat ? ` · seat ${i.seat}` : ''}
                          {kitchenNote(i.modifiers.map((m) => m.name), i.note) && <span className="small muted"><br />{kitchenNote(i.modifiers.map((m) => m.name), i.note)}</span>}</span>
                      </label>
                      <span className="small chip">{i.paid ? 'Paid' : STATUS[i.status]}{i.status === 'fired' ? ` ${minutesSince(i.fired_at, now)}′` : ''}</span>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
            <div className="row wrap">
              {heldCourses(order).map((c) => <button key={c} type="button" disabled={busy} onClick={() => run(() => dining.fire(order.id, c))}>Call {COURSES[c] ?? `course ${c}`}</button>)}
              <button type="button" disabled={busy || !selected.length} onClick={() => run(() => dining.serve(order.id, selected))}>Served</button>
              <button type="button" disabled={busy || !selected.length} onClick={() => setVoiding({ message: '', needsApproval: false })}>Void</button>
              {order.kind === 'table' && <button type="button" disabled={busy} onClick={() => dining.floor().then((f) => setMoving(f.tables.filter((t) => !f.orders.some((o) => o.dining_table_id === t.id))))}>Move table</button>}
              {left.length === 0 && order.items.length > 0 && <button type="button" disabled={busy} onClick={() => run(() => dining.close(order.id)).then((ok) => ok && onBack())}>Close bill</button>}
              <button type="button" className="primary big" disabled={busy || pending.length > 0 || paySet.length === 0} onClick={() => onPay(order, paySet)}>
                {selected.length ? `Pay ${paySet.length} selected` : 'Pay bill'}</button>
            </div>
          </section>
          <section aria-label="Add items">
            <input className="big" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Find a dish or drink" aria-label="Find a dish or drink" autoComplete="off" />
            <ul className="results">
              {results.map((p) => <li key={p.id}><button type="button" onClick={() => pick(p)}>{p.name}{p.station ? <span className="muted small"> · {p.station}</span> : null}</button></li>)}
            </ul>
            {pending.length > 0 && (
              <>
                <h4>New — not sent yet</h4>
                <ul className="bill-items">
                  {pending.map((i) => (
                    <li key={i.id}><span>{i.qty} × {i.name}{i.hold ? ' (hold)' : ''}<span className="small muted"><br />{kitchenNote(i.names, i.note)}</span></span>
                      <button type="button" className="link" onClick={() => setPending((p) => p.filter((x) => x.id !== i.id))} aria-label={`Remove ${i.name}`}>✕</button></li>
                  ))}
                </ul>
                <button type="button" className="primary big" disabled={busy} onClick={send}>Send to kitchen</button>
              </>
            )}
          </section>
        </div>
        <p className="muted small">Total to pay is worked out at the till, with any service charge{restaurant.tips ? ' and tip' : ''}.</p>
      </div>
      {picking && <ModifierPicker product={picking} restaurant={restaurant} tableService={tableService} onDone={(c) => addPicked(picking, c)} onCancel={() => setPicking(null)} />}
      {voiding && <VoidDialog message={voiding.message} needsApproval={voiding.needsApproval} busy={busy} onVoid={voidSelected} onCancel={() => setVoiding(null)} />}
      {moving && (
        <div className="modal" role="dialog" aria-modal="true" aria-label="Move to table">
          <div className="card pay">
            <h2>Move to which table?</h2>
            <div className="row wrap">{moving.map((t) => <button key={t.id} type="button" onClick={() => { setMoving(null); run(() => dining.move(order.id, t.id)) }}>{t.code}</button>)}</div>
            {moving.length === 0 && <p className="muted">No free tables.</p>}
            <button type="button" onClick={() => setMoving(null)}>Back</button>
          </div>
        </div>
      )}
    </div>
  )
}

function VoidDialog({ message, needsApproval, busy, onVoid, onCancel }: {
  message: string; needsApproval: boolean; busy: boolean; onVoid: (reason: string, approval: { username: string; pin: string } | null) => void; onCancel: () => void
}) {
  const [reason, setReason] = useState('')
  const [username, setUsername] = useState('')
  const [pin, setPin] = useState('')
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Void items" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={(e) => { e.preventDefault(); if (reason.trim()) onVoid(reason.trim(), needsApproval && username ? { username, pin } : null) }}>
        <h2>Void items</h2>
        {message && <p className="error" role="alert">{message}</p>}
        <label>Reason<input autoFocus value={reason} onChange={(e) => setReason(e.target.value)} maxLength={200} placeholder="e.g. customer changed order, spilled" /></label>
        {needsApproval && (
          <div className="row">
            <label>Supervisor username<input value={username} onChange={(e) => setUsername(e.target.value)} autoCapitalize="none" /></label>
            <label>Supervisor PIN<input type="password" inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 8))} /></label>
          </div>
        )}
        <div className="row"><button type="button" onClick={onCancel} disabled={busy}>Back</button><button className="primary big" disabled={busy || !reason.trim()}>Void</button></div>
      </form>
    </div>
  )
}
