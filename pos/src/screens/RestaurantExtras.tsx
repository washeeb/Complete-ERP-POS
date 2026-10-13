import { useCallback, useEffect, useMemo, useState } from 'react'
import { v7 as uuidv7 } from 'uuid'
import { ApiError, dining, type WasteBody, type WasteProduct, type WasteResult } from '../api'
import { formatMoney, parseMoney } from '../money'
import type { DiningOrder, DiningTable, Reservation } from '../types'

const PLATFORMS = [['bolt_food', 'Bolt Food'], ['glovo', 'Glovo'], ['chowdeck', 'Chowdeck']] as const
const NETWORKS = [['mtn', 'MTN MoMo'], ['telecel', 'Telecel Cash'], ['at', 'AT Money']] as const
const errorText = (e: unknown) => (e instanceof Error ? e.message : String(e))
const time = (iso: string) => new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
const localInput = (d: Date) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)

type Money = { currency: string; exponent: number }

/** Today's bookings: seat the guests, take or refund a deposit, mark no-shows; and new bookings. */
export function BookingsPanel({ tables, money, onSeated, onClose }: {
  tables: DiningTable[]; money: Money; onSeated: (o: DiningOrder) => void; onClose: () => void
}) {
  const [list, setList] = useState<Reservation[]>([])
  const [day, setDay] = useState(() => new Date().toLocaleDateString('en-CA'))
  const [error, setError] = useState('')
  const [editing, setEditing] = useState<Reservation | 'new' | null>(null)
  const [paying, setPaying] = useState<{ r: Reservation; refund: boolean } | null>(null)
  const fmt = (m: number) => formatMoney(m, money.currency, money.exponent)
  const load = useCallback(() => dining.reservations(day).then((r) => setList(r.reservations)).catch((e) => setError(errorText(e))), [day])
  useEffect(() => { load() }, [load])
  async function act(f: () => Promise<unknown>) {
    setError('')
    try { await f(); await load() } catch (e) { setError(errorText(e)) }
  }
  const code = (id: string | null) => tables.find((t) => t.id === id)?.code ?? '—'
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Bookings">
      <div className="card wide">
        <div className="row spread">
          <h2>Bookings</h2>
          <div className="row">
            <input type="date" value={day} onChange={(e) => setDay(e.target.value)} aria-label="Day" />
            <button type="button" className="primary" onClick={() => setEditing('new')}>New booking</button>
            <button type="button" onClick={onClose}>Back</button>
          </div>
        </div>
        {error && <p className="error" role="alert">{error}</p>}
        {list.length === 0 && <p className="muted">No bookings this day.</p>}
        <ul className="bill-items">
          {list.map((r) => (
            <li key={r.id}>
              <span><strong>{time(r.starts_at)} · {r.guest_name}</strong> ({r.party_size}) · table {code(r.dining_table_id)}
                <span className="small muted"><br />{r.number}{r.phone ? ` · ${r.phone}` : ''}{r.deposit_minor ? ` · deposit ${fmt(r.deposit_minor)} (${r.deposit_status})` : ''}{r.note ? ` · ${r.note}` : ''}</span></span>
              <span className="row wrap">
                {r.status === 'booked' ? (
                  <>
                    <button type="button" className="primary" onClick={() => act(async () => onSeated((await dining.seat(r.id)).order))}>Seat</button>
                    <button type="button" onClick={() => setPaying({ r, refund: false })}>Deposit</button>
                    <button type="button" onClick={() => setEditing(r)}>Change</button>
                    <button type="button" onClick={() => act(() => dining.noShow(r.id))}>No-show</button>
                    <button type="button" onClick={() => { const why = window.prompt('Why is the booking cancelled?'); if (why) act(() => dining.cancelBooking(r.id, why)) }}>Cancel</button>
                  </>
                ) : <span className="chip small">{({ seated: 'Seated', no_show: 'No-show', cancelled: 'Cancelled' } as Record<string, string>)[r.status]}</span>}
                {r.status !== 'booked' && r.deposit_left_minor > 0 && r.deposit_status === 'held' && <button type="button" onClick={() => setPaying({ r, refund: true })}>Refund deposit</button>}
              </span>
            </li>
          ))}
        </ul>
      </div>
      {editing && <BookingForm booking={editing === 'new' ? null : editing} tables={tables} onDone={() => { setEditing(null); load() }} onCancel={() => setEditing(null)} />}
      {paying && <DepositDialog r={paying.r} refund={paying.refund} money={money} onDone={() => { setPaying(null); load() }} onCancel={() => setPaying(null)} />}
    </div>
  )
}

function BookingForm({ booking, tables, onDone, onCancel }: { booking: Reservation | null; tables: DiningTable[]; onDone: () => void; onCancel: () => void }) {
  const soon = new Date(Math.ceil(Date.now() / 900000) * 900000 + 3600000)
  const [f, setF] = useState({ guest_name: booking?.guest_name ?? '', phone: booking?.phone ?? '', party_size: String(booking?.party_size ?? 2),
    starts_at: localInput(booking ? new Date(booking.starts_at) : soon), dining_table_id: booking?.dining_table_id ?? '', note: booking?.note ?? '' })
  const [error, setError] = useState('')
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value })
  async function save(e: React.FormEvent) {
    e.preventDefault()
    try {
      await dining.book({ guest_name: f.guest_name, phone: f.phone || null, party_size: Number(f.party_size), starts_at: new Date(f.starts_at).toISOString(),
        dining_table_id: f.dining_table_id || null, note: f.note || null }, booking?.id)
      onDone()
    } catch (err) {
      setError(errorText(err))
    }
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={booking ? 'Change booking' : 'New booking'} onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={save}>
        <h2>{booking ? `Booking ${booking.number}` : 'New booking'}</h2>
        {error && <p className="error" role="alert">{error}</p>}
        <label>Name<input autoFocus value={f.guest_name} onChange={set('guest_name')} maxLength={120} required /></label>
        <div className="row">
          <label>Phone<input inputMode="tel" value={f.phone} onChange={set('phone')} maxLength={20} /></label>
          <label>Guests<input inputMode="numeric" value={f.party_size} onChange={set('party_size')} /></label>
        </div>
        <div className="row">
          <label>Date and time<input type="datetime-local" value={f.starts_at} onChange={set('starts_at')} step={900} /></label>
          <label>Table<select value={f.dining_table_id} onChange={set('dining_table_id')}>
            <option value="">Choose on arrival</option>
            {tables.map((t) => <option key={t.id} value={t.id}>{t.code} ({t.seats})</option>)}
          </select></label>
        </div>
        <label>Note<input value={f.note} onChange={set('note')} maxLength={255} placeholder="e.g. birthday, high chair" /></label>
        <div className="row"><button type="button" onClick={onCancel}>Back</button><button className="primary big">Save booking</button></div>
      </form>
    </div>
  )
}

/** Take a deposit for a booking, or give back what is left of it (a supervisor approves). */
function DepositDialog({ r, refund, money, onDone, onCancel }: { r: Reservation; refund: boolean; money: Money; onDone: () => void; onCancel: () => void }) {
  const [method, setMethod] = useState<'cash' | 'momo' | 'card'>('cash')
  const [amount, setAmount] = useState('')
  const [provider, setProvider] = useState('mtn')
  const [reference, setReference] = useState('')
  const [approval, setApproval] = useState<{ username: string; pin: string } | null>(null)
  const [error, setError] = useState('')
  const fmt = (m: number) => formatMoney(m, money.currency, money.exponent)
  async function save(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    const extra = method === 'momo' ? { provider, reference } : reference ? { reference } : {}
    try {
      if (refund) await dining.refund(r.id, { method, ...extra, approval: approval?.username ? approval : null })
      else await dining.deposit(r.id, { method, amount_minor: parseMoney(amount, money.exponent), ...extra })
      onDone()
    } catch (err) {
      if (err instanceof ApiError && err.code === 'refund_needs_approval') setApproval({ username: '', pin: '' })
      setError(errorText(err))
    }
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={refund ? 'Refund deposit' : 'Take deposit'} onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={save}>
        <h2>{refund ? `Refund ${fmt(r.deposit_left_minor)} to ${r.guest_name}` : `Deposit — ${r.guest_name}`}</h2>
        {error && <p className="error" role="alert">{error}</p>}
        <div className="segmented" role="tablist">
          {(['cash', 'momo', 'card'] as const).map((m) => <button key={m} type="button" role="tab" aria-selected={method === m} className={method === m ? 'active' : ''}
            onClick={() => setMethod(m)}>{m === 'cash' ? 'Cash' : m === 'momo' ? 'Mobile money' : 'Card'}</button>)}
        </div>
        {!refund && <label>Amount<input autoFocus className="big mono" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} required /></label>}
        {method === 'momo' && <select aria-label="Network" value={provider} onChange={(e) => setProvider(e.target.value)}>{NETWORKS.map(([id, n]) => <option key={id} value={id}>{n}</option>)}</select>}
        {method !== 'cash' && <label>{method === 'momo' ? 'Transaction ID' : 'Reference (optional)'}<input value={reference} onChange={(e) => setReference(e.target.value)} maxLength={80} /></label>}
        {approval && (
          <div className="row">
            <label>Supervisor username<input value={approval.username} onChange={(e) => setApproval({ ...approval, username: e.target.value })} autoCapitalize="none" /></label>
            <label>Supervisor PIN<input type="password" inputMode="numeric" value={approval.pin} onChange={(e) => setApproval({ ...approval, pin: e.target.value.replace(/\D/g, '').slice(0, 8) })} /></label>
          </div>
        )}
        <div className="row"><button type="button" onClick={onCancel}>Back</button><button className="primary big">{refund ? 'Refund' : 'Take deposit'}</button></div>
      </form>
    </div>
  )
}

/** A delivery-app order keyed in at the till: the app and its order number; then add the items like any bill. */
export function DeliveryDialog({ onOpen, onCancel }: { onOpen: (o: DiningOrder) => void; onCancel: () => void }) {
  const [platform, setPlatform] = useState<string>('bolt_food')
  const [ref, setRef] = useState('')
  const [error, setError] = useState('')
  async function open(e: React.FormEvent) {
    e.preventDefault()
    try {
      onOpen((await dining.openDelivery({ id: uuidv7(), platform, external_ref: ref.trim() })).order)
    } catch (err) {
      setError(errorText(err))
    }
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Delivery order" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={open}>
        <h2>Delivery-app order</h2>
        {error && <p className="error" role="alert">{error}</p>}
        <div className="segmented" role="tablist">
          {PLATFORMS.map(([id, n]) => <button key={id} type="button" role="tab" aria-selected={platform === id} className={platform === id ? 'active' : ''} onClick={() => setPlatform(id)}>{n}</button>)}
        </div>
        <label>Order number in the app<input autoFocus value={ref} onChange={(e) => setRef(e.target.value)} maxLength={80} required /></label>
        <div className="row"><button type="button" onClick={onCancel}>Back</button><button className="primary big">Open order</button></div>
      </form>
    </div>
  )
}

/**
 * Kitchen waste: what was wasted (a dish counts as its recipe's ingredients), how much, and why. Above the value
 * limit a manager approves with their PIN. On the kitchen screen the cook also gives their username and PIN.
 */
export function WasteDialog({ products, identify, money, onSave, onCancel }: {
  products: WasteProduct[]; identify: boolean; money: Money
  onSave: (body: WasteBody & { username?: string; pin?: string }) => Promise<WasteResult>; onCancel: () => void
}) {
  const [query, setQuery] = useState('')
  const [lines, setLines] = useState<{ p: WasteProduct; qty: string }[]>([])
  const [reason, setReason] = useState('')
  const [who, setWho] = useState({ username: '', pin: '' })
  const [approval, setApproval] = useState<{ username: string; pin: string } | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState<WasteResult | null>(null)
  const results = useMemo(() => {
    const words = query.toLowerCase().split(/\s+/).filter(Boolean)
    return words.length ? products.filter((p) => words.every((w) => p.name.toLowerCase().includes(w) || p.sku.toLowerCase().includes(w))).slice(0, 8) : []
  }, [products, query])
  async function save(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    if (!lines.length || !reason.trim()) return setError('Add what was wasted and why.')
    try {
      setDone(await onSave({ items: lines.map((l) => ({ product_id: l.p.id, product_uom_id: l.p.units[0].id, qty: l.qty })), reason: reason.trim(),
        approval: approval?.username ? approval : null, ...(identify ? who : {}) }))
    } catch (err) {
      if (err instanceof ApiError && err.code === 'waste_needs_approval') setApproval({ username: '', pin: '' })
      setError(errorText(err))
    }
  }
  if (done) {
    return (
      <div className="modal" role="dialog" aria-modal="true" aria-label="Waste recorded">
        <div className="card pay">
          <h2>Waste recorded</h2>
          <p>{formatMoney(done.value_minor, done.currency || money.currency, money.exponent)} at cost{done.approved_by ? `, approved by ${done.approved_by}` : ''}.</p>
          <div className="row"><button type="button" className="primary big" onClick={onCancel}>Done</button></div>
        </div>
      </div>
    )
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Kitchen waste" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={save}>
        <h2>Kitchen waste</h2>
        {error && <p className="error" role="alert">{error}</p>}
        <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Dish or ingredient" aria-label="Find what was wasted" autoComplete="off" />
        <ul className="results">
          {results.map((p) => <li key={p.id}><button type="button" onClick={() => { setLines((l) => [...l, { p, qty: '1' }]); setQuery('') }}>{p.name} <span className="muted small">{p.units[0]?.uom_code}</span></button></li>)}
        </ul>
        {lines.map((l, i) => (
          <div key={i} className="row">
            <span>{l.p.name}</span>
            <input className="qty mono" inputMode="decimal" value={l.qty} aria-label={`Quantity of ${l.p.name}`}
              onChange={(e) => setLines((x) => x.map((y, j) => (j === i ? { ...y, qty: e.target.value } : y)))} /> {l.p.units[0]?.uom_code}
            <button type="button" className="link" onClick={() => setLines((x) => x.filter((_, j) => j !== i))} aria-label={`Remove ${l.p.name}`}>✕</button>
          </div>
        ))}
        <label>What happened<input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={120} placeholder="burnt, dropped, past its date…" /></label>
        {identify && (
          <div className="row">
            <label>Your username<input value={who.username} onChange={(e) => setWho({ ...who, username: e.target.value })} autoCapitalize="none" /></label>
            <label>Your PIN<input type="password" inputMode="numeric" value={who.pin} onChange={(e) => setWho({ ...who, pin: e.target.value.replace(/\D/g, '').slice(0, 8) })} /></label>
          </div>
        )}
        {approval && (
          <div className="row">
            <label>Manager username<input value={approval.username} onChange={(e) => setApproval({ ...approval, username: e.target.value })} autoCapitalize="none" /></label>
            <label>Manager PIN<input type="password" inputMode="numeric" value={approval.pin} onChange={(e) => setApproval({ ...approval, pin: e.target.value.replace(/\D/g, '').slice(0, 8) })} /></label>
          </div>
        )}
        <div className="row"><button type="button" onClick={onCancel}>Back</button><button className="primary big">Record waste</button></div>
      </form>
    </div>
  )
}
