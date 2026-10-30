import { useCallback, useEffect, useMemo, useState } from 'react'
import { repairs } from '../api'
import { search } from '../cart'
import { addSerial } from '../electronics'
import { formatMoney, parseMoney } from '../money'
import type { Product, RepairJob, SerialInfo, TradeInItem } from '../types'
import { SignedIn } from './SignedIn'

const errorText = (e: unknown) => (e instanceof Error ? e.message : String(e))

/** Scan the serial / IMEI number of each unit sold; the quantity is the number of units scanned. */
export function SerialPrompt({ product, onDone, onCancel }: { product: Product; onDone: (serials: string[]) => void; onCancel: () => void }) {
  const [list, setList] = useState<string[]>([])
  const [scan, setScan] = useState('')
  const [error, setError] = useState('')
  function add(e: React.FormEvent) {
    e.preventDefault()
    const r = addSerial(list, scan)
    setError(r.error ?? '')
    setList(r.list)
    setScan('')
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={`Serial numbers for ${product.name}`} onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <div className="card pay">
        <h2>{product.name}</h2>
        <p className="muted">Scan the serial / IMEI number of each unit{product.warranty_months ? ` · ${product.warranty_months}-month warranty` : ''}.</p>
        <form onSubmit={add}>
          <input autoFocus className="big mono" value={scan} onChange={(e) => setScan(e.target.value)} aria-label="Serial or IMEI number" placeholder="Scan or type, then Enter" />
        </form>
        {error && <p className="error" role="alert">{error}</p>}
        <ul className="bill-items">
          {list.map((s) => <li key={s}><span className="mono">{s}</span><button type="button" className="link" onClick={() => setList(list.filter((x) => x !== s))} aria-label={`Remove ${s}`}>✕</button></li>)}
        </ul>
        <div className="row"><button type="button" onClick={onCancel}>Back</button>
          <button type="button" className="primary big" disabled={!list.length} onClick={() => onDone(list)}>Add {list.length || ''} to sale</button></div>
      </div>
    </div>
  )
}

/** A used item handed in as part-payment: what it is, its serial, its condition and the value given. */
export function TradeInDialog({ products, currency, exponent, due, onAdd, onCancel }: {
  products: Product[]; currency: string; exponent: number; due: number
  onAdd: (item: TradeInItem, approval: { username: string; pin: string } | null) => void; onCancel: () => void
}) {
  const [query, setQuery] = useState('')
  const [product, setProduct] = useState<Product | null>(null)
  const [serial, setSerial] = useState('')
  const [condition, setCondition] = useState('')
  const [value, setValue] = useState('')
  const [who, setWho] = useState({ username: '', pin: '' })
  const [error, setError] = useState('')
  const results = useMemo(() => search(products.filter((p) => p.track_stock), query, 8), [products, query])
  function save(e: React.FormEvent) {
    e.preventDefault()
    try {
      if (!product) throw new Error('Choose what is being traded in.')
      const v = parseMoney(value, exponent)
      if (v <= 0) throw new Error('Enter the value given for it.')
      if (v > due) throw new Error(`The trade-in cannot be more than what is due (${formatMoney(due, currency, exponent)}).`)
      if (product.track_serials && !serial.trim()) throw new Error('Enter its serial / IMEI number.')
      onAdd({ product_id: product.id, name: product.name, serial: serial.trim() || null, condition: condition.trim() || null, value_minor: v },
        who.username ? who : null)
    } catch (err) {
      setError(errorText(err))
    }
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Trade-in" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={save}>
        <h2>Trade-in</h2>
        {error && <p className="error" role="alert">{error}</p>}
        {product ? (
          <p><strong>{product.name}</strong> <button type="button" className="link" onClick={() => setProduct(null)}>change</button></p>
        ) : (
          <>
            <input autoFocus value={query} onChange={(e) => setQuery(e.target.value)} aria-label="Find the item taken in" placeholder="e.g. Used phone" />
            <ul className="results">{results.map((p) => <li key={p.id}><button type="button" onClick={() => setProduct(p)}>{p.name}</button></li>)}</ul>
          </>
        )}
        <label>Serial / IMEI<input className="mono" value={serial} onChange={(e) => setSerial(e.target.value)} maxLength={80} /></label>
        <label>Condition<input value={condition} onChange={(e) => setCondition(e.target.value)} maxLength={200} placeholder="e.g. screen scratched, battery 80 %" /></label>
        <label>Value given<input className="big mono" inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)} /></label>
        <p className="muted small">A supervisor approves unless you may accept trade-ins yourself.</p>
        <div className="row">
          <SignedIn />
          <label>Supervisor username<input autoComplete="off" value={who.username} onChange={(e) => setWho({ ...who, username: e.target.value })} autoCapitalize="none" /></label>
          <label>Supervisor PIN<input type="text" className="pin-mask" autoComplete="off" inputMode="numeric" value={who.pin} onChange={(e) => setWho({ ...who, pin: e.target.value.replace(/\D/g, '').slice(0, 8) })} /></label>
        </div>
        <div className="row"><button type="button" onClick={onCancel}>Back</button><button className="primary big">Add trade-in</button></div>
      </form>
    </div>
  )
}

const NEXT: Record<string, string[]> = {
  received: ['diagnosing', 'waiting_parts', 'repairing', 'ready'], diagnosing: ['waiting_parts', 'repairing', 'ready'],
  waiting_parts: ['repairing', 'ready'], repairing: ['waiting_parts', 'ready'], at_supplier: ['repairing', 'ready'], ready: ['repairing'],
}
const LABEL: Record<string, string> = { diagnosing: 'Diagnosing', waiting_parts: 'Waiting for parts', repairing: 'Repairing', ready: 'Ready to collect' }

/** Repairs at the till: open jobs, a new job, a warranty check by serial, parts and labour, and collection. */
export function RepairsScreen({ products, currency, exponent, onCollect, onClose }: {
  products: Product[]; currency: string; exponent: number; onCollect: (job: RepairJob) => void; onClose: () => void
}) {
  const [jobs, setJobs] = useState<RepairJob[]>([])
  const [open, setOpen] = useState<RepairJob | null>(null)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState('')
  const [lookup, setLookup] = useState('')
  const [found, setFound] = useState<SerialInfo[] | null>(null)
  const money = (m: number) => formatMoney(m, currency, exponent)
  const load = useCallback(() => repairs.list().then((r) => setJobs(r.jobs)).catch((e) => setError(errorText(e))), [])
  useEffect(() => { load() }, [load])
  async function act(f: () => Promise<{ job: RepairJob }>) {
    setError('')
    try {
      const r = await f()
      setOpen(r.job)
      load()
    } catch (e) {
      setError(errorText(e))
    }
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Repairs">
      <div className="card wide">
        <div className="row spread"><h2>Repairs</h2>
          <div className="row"><button type="button" className="primary" onClick={() => { setCreating(true); setOpen(null) }}>New repair job</button><button type="button" onClick={onClose}>Back to till</button></div>
        </div>
        {error && <p className="error" role="alert">{error}</p>}
        <form className="row" onSubmit={(e) => { e.preventDefault(); repairs.serial(lookup).then((r) => setFound(r.serials)).catch((err) => setError(errorText(err))) }}>
          <input value={lookup} onChange={(e) => setLookup(e.target.value)} aria-label="Check warranty by serial" placeholder="Check warranty: serial / IMEI" />
          <button type="submit">Check</button>
        </form>
        {found && (found.length === 0 ? <p className="muted">Not a number we know.</p> : found.map((f) => (
          <p key={f.serial + f.product_id} className={f.in_warranty ? 'ok' : 'muted'}><strong>{f.product}</strong> {f.serial} · {f.status_label}
            {f.receipt_no ? ` · sold on ${f.receipt_no}${f.customer ? ` to ${f.customer}` : ''}` : ''}
            {f.warranty_until ? ` · warranty ${f.in_warranty ? 'until' : 'ended'} ${f.warranty_until}` : ''}</p>)))}
        <div className="bill-cols">
          <section aria-label="Open jobs">
            <ul className="bill-items">
              {jobs.map((j) => (
                <li key={j.id}><button type="button" className="link" onClick={() => { setOpen(j); setCreating(false) }}>{j.number} · {j.customer_name} · {j.device}</button>
                  <span className="chip small">{j.status_label}{j.in_warranty ? ' · warranty' : ''}</span></li>
              ))}
              {jobs.length === 0 && <li className="muted">No open repairs.</li>}
            </ul>
          </section>
          <section aria-label="Job">
            {creating && <NewJob onSaved={(j) => { setCreating(false); setOpen(j); load() }} onError={setError} />}
            {open && <JobCard job={open} products={products} money={money} onStatus={(s) => act(() => repairs.status(open.id, s))}
              onLines={(lines) => act(() => repairs.lines(open.id, lines))} onHandOver={() => act(() => repairs.handOver(open.id))} onCollect={() => onCollect(open)} />}
          </section>
        </div>
      </div>
    </div>
  )
}

function NewJob({ onSaved, onError }: { onSaved: (j: RepairJob) => void; onError: (m: string) => void }) {
  const [f, setF] = useState({ customer_name: '', phone: '', serial: '', device: '', fault: '', accessories: '' })
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value })
  async function save(e: React.FormEvent) {
    e.preventDefault()
    try {
      onSaved((await repairs.open({ customer_name: f.customer_name, phone: f.phone || null, serial: f.serial || null, device: f.device || null, fault: f.fault,
        accessories: f.accessories || null })).job)
    } catch (err) {
      onError(errorText(err))
    }
  }
  return (
    <form onSubmit={save} aria-label="New repair job">
      <label>Customer<input value={f.customer_name} onChange={set('customer_name')} required maxLength={120} /></label>
      <label>Phone<input inputMode="tel" value={f.phone} onChange={set('phone')} maxLength={20} /></label>
      <label>Serial / IMEI<input className="mono" value={f.serial} onChange={set('serial')} maxLength={80} /></label>
      <label>Device<input value={f.device} onChange={set('device')} maxLength={160} placeholder="Filled in from a serial we sold" /></label>
      <label>What is wrong<input value={f.fault} onChange={set('fault')} required maxLength={2000} /></label>
      <label>Left with it<input value={f.accessories} onChange={set('accessories')} maxLength={255} placeholder="charger, case…" /></label>
      <button className="primary big">Take in</button>
    </form>
  )
}

function JobCard({ job, products, money, onStatus, onLines, onHandOver, onCollect }: {
  job: RepairJob; products: Product[]; money: (m: number) => string; onStatus: (s: string) => void
  onLines: (l: { product_uom_id: string; qty: string }[]) => void; onHandOver: () => void; onCollect: () => void
}) {
  const [lines, setLines] = useState(job.lines.map((l) => ({ product_uom_id: l.product_uom_id, name: l.name, qty: l.qty })))
  const [query, setQuery] = useState('')
  useEffect(() => setLines(job.lines.map((l) => ({ product_uom_id: l.product_uom_id, name: l.name, qty: l.qty }))), [job])
  const results = useMemo(() => search(products, query, 6), [products, query])
  const charge = job.lines.reduce((a, l) => a + Math.round(l.unit_price_minor * Number(l.qty)), 0)
  const changed = JSON.stringify(lines.map((l) => [l.product_uom_id, Number(l.qty)])) !== JSON.stringify(job.lines.map((l) => [l.product_uom_id, Number(l.qty)]))
  return (
    <div aria-label={`Job ${job.number}`}>
      <h3>{job.number} · {job.status_label}</h3>
      <p>{job.customer_name}{job.phone ? ` · ${job.phone}` : ''}<br />{job.device}{job.serial ? ` · ${job.serial}` : ''}<br />
        <span className="muted">{job.fault}</span>{job.in_warranty && <><br /><strong>Under warranty — no charge</strong></>}</p>
      <div className="row wrap">{(NEXT[job.status] ?? []).map((s) => <button key={s} type="button" onClick={() => onStatus(s)}>{LABEL[s]}</button>)}</div>
      <h4>Parts and labour</h4>
      <ul className="bill-items">
        {lines.map((l, i) => (
          <li key={i}><span>{l.name}</span>
            <input className="qty mono" inputMode="decimal" value={l.qty} aria-label={`Quantity of ${l.name}`} onChange={(e) => setLines(lines.map((x, j) => (j === i ? { ...x, qty: e.target.value } : x)))} />
            <button type="button" className="link" onClick={() => setLines(lines.filter((_, j) => j !== i))} aria-label={`Remove ${l.name}`}>✕</button></li>
        ))}
      </ul>
      <input value={query} onChange={(e) => setQuery(e.target.value)} aria-label="Add a part or labour" placeholder="Add a part or labour" />
      <ul className="results">{results.map((p) => <li key={p.id}><button type="button" onClick={() => {
        setLines([...lines, { product_uom_id: (p.units.find((u) => u.is_base) ?? p.units[0]).id, name: p.name, qty: '1' }])
        setQuery('')
      }}>{p.name}</button></li>)}</ul>
      {changed && <button type="button" onClick={() => onLines(lines.map((l) => ({ product_uom_id: l.product_uom_id, qty: l.qty })))}>Save parts and labour</button>}
      <p>To pay: <strong>{money(charge)}</strong></p>
      {job.status === 'ready' && !changed && (charge > 0
        ? <button type="button" className="primary big" onClick={onCollect}>Collect and pay</button>
        : <button type="button" className="primary big" onClick={onHandOver}>Hand over</button>)}
    </div>
  )
}
