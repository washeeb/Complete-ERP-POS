import { useCallback, useEffect, useRef, useState } from 'react'
import { v7 as uuidv7 } from 'uuid'
import { ApiError, wms } from '../api'
import type { WmsBin, WmsBootstrap, WmsCount, WmsOrder, WmsTransfer, WmsUnit, WmsWarehouse } from '../types'

/**
 * Warehouse handheld (scanner phone): receive purchase orders, put away into bins, receive transfers, count.
 * Works online against its branch's server; every action is checked and recorded there.
 * A hardware scanner types the code and presses Enter, so the scan box is an ordinary text field.
 */

type Mode = 'home' | 'receive' | 'putaway' | 'transfers' | 'count'

const q = (v: string | number) => String(Number(v))
const msg = (e: unknown) => (e instanceof ApiError || e instanceof Error ? e.message : String(e))

function ScanBox({ onScan, label = 'Scan or type a code', autoFocus = true }: { onScan: (code: string) => void; label?: string; autoFocus?: boolean }) {
  const [v, setV] = useState('')
  return (
    <form className="scan" onSubmit={(e) => { e.preventDefault(); if (v.trim()) onScan(v.trim()); setV('') }}>
      <input aria-label={label} placeholder={label} value={v} onChange={(e) => setV(e.target.value)} autoFocus={autoFocus} className="big" autoComplete="off" />
    </form>
  )
}

function BinSelect({ bins, value, onChange, label, emptyLabel }: { bins: WmsBin[]; value: string | null; onChange: (v: string | null) => void; label: string; emptyLabel?: string }) {
  return (
    <label>{label}
      <select value={value ?? ''} onChange={(e) => onChange(e.target.value || null)}>
        {emptyLabel && <option value="">{emptyLabel}</option>}
        {bins.filter((b) => !(emptyLabel && b.kind === 'receiving')).map((b) => <option key={b.id} value={b.id}>{b.code}{b.name ? ` · ${b.name}` : ''}</option>)}
      </select>
    </label>
  )
}

export function Handheld({ onLogout }: { onLogout: () => void }) {
  const [boot, setBoot] = useState<WmsBootstrap | null>(null)
  const [whId, setWhId] = useState<string>('')
  const [mode, setMode] = useState<Mode>('home')
  const [error, setError] = useState('')
  const [flash, setFlash] = useState('')
  const finish = (m: string) => { setFlash(m); setMode('home') }

  useEffect(() => {
    wms.bootstrap().then((b) => { setBoot(b); setWhId(b.warehouses[0]?.id ?? '') }).catch((e) => setError(msg(e)))
  }, [])

  const wh = boot?.warehouses.find((w) => w.id === whId) ?? null
  if (error && !boot) return <div className="card narrow"><p className="error">{error}</p><button onClick={onLogout}>Sign out</button></div>
  if (!boot) return <p className="center muted">Loading…</p>

  return (
    <div className="handheld">
      <header className="row wrap hh-head">
        <strong>{boot.branch.code}</strong>
        <select aria-label="Warehouse" value={whId} onChange={(e) => { setWhId(e.target.value); setMode('home') }}>
          {boot.warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} · {w.name}</option>)}
        </select>
        <span className="muted small">{boot.user.name}</span>
        {mode !== 'home' && <button onClick={() => setMode('home')}>Menu</button>}
        <button onClick={onLogout}>Sign out</button>
      </header>
      {flash && mode === 'home' && <p className="banner" role="status">{flash}</p>}
      {!wh && <p className="error">You are not set up for warehouse work at {boot.branch.name}.</p>}
      {wh && mode === 'home' && (
        <nav className="hh-menu">
          {wh.can.includes('stock.receive') && <button className="primary big" onClick={() => { setFlash(''); setMode('receive') }}>Receive delivery</button>}
          {wh.can.includes('stock.receive') && wh.use_bins && <button className="primary big" onClick={() => { setFlash(''); setMode('putaway') }}>Put away / move</button>}
          {wh.can.includes('stock.transfer') && <button className="primary big" onClick={() => { setFlash(''); setMode('transfers') }}>Receive transfer</button>}
          {wh.can.includes('stock.count') && <button className="primary big" onClick={() => { setFlash(''); setMode('count') }}>Count stock</button>}
        </nav>
      )}
      {wh && mode === 'receive' && <Receive wh={wh} done={finish} />}
      {wh && mode === 'putaway' && <PutAway wh={wh} />}
      {wh && mode === 'transfers' && <Transfers wh={wh} done={finish} />}
      {wh && mode === 'count' && <Count wh={wh} done={finish} />}
    </div>
  )
}

function Receive({ wh, done }: { wh: WmsWarehouse; done: (message: string) => void }) {
  const [orders, setOrders] = useState<WmsOrder[] | null>(null)
  const [po, setPo] = useState<WmsOrder | null>(null)
  const [now, setNow] = useState<Record<string, string>>({})
  const [lots, setLots] = useState<Record<string, { batch_no: string; expires_on: string }>>({}) // batch-tracked lines: from the pack
  const [bin, setBin] = useState<string | null>(null)
  const [ref, setRef] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const clientId = useRef(uuidv7()) // one per delivery: a resend never receives twice

  useEffect(() => { wms.purchaseOrders(wh.id).then(setOrders).catch((e) => setNote(msg(e))) }, [wh.id])

  function scan(code: string) {
    const line = po?.lines.find((l) => l.barcodes.includes(code) || l.sku === code.toUpperCase())
    if (!line) return setNote(`“${code}” is not on ${po?.number}.`)
    setNow((n) => ({ ...n, [line.id]: q(Number(n[line.id] ?? 0) + 1) }))
    setNote(`+1 ${line.uom_code} ${line.name}`)
  }

  async function post() {
    if (!po) return
    setBusy(true)
    try {
      const lines = po.lines.filter((l) => Number(now[l.id] ?? 0) > 0).map((l) => ({ purchase_order_line_id: l.id, qty: now[l.id], bin_id: bin,
        ...(l.track_batches ? { batch_no: lots[l.id]?.batch_no ?? '', expires_on: lots[l.id]?.expires_on || null } : {}) }))
      const r = await wms.receive({ purchase_order_id: po.id, client_id: clientId.current, supplier_ref: ref || undefined, lines })
      done(r.status === 'held' ? `${r.number}: more than ordered — waiting for a manager. Nothing is in stock yet.` : `${r.number} received into stock.`)
    } catch (e) {
      setNote(msg(e))
    } finally {
      setBusy(false)
    }
  }

  if (!po) {
    return (
      <section className="card">
        <h2>Receive delivery</h2>
        {orders === null ? <p className="muted">Loading orders…</p> : orders.length === 0 ? <p className="muted">No approved orders waiting for {wh.code}.</p> : (
          <ul className="hh-list">
            {orders.map((o) => (
              <li key={o.id}><button onClick={() => { setPo(o); setNow({}) }}><strong>{o.number}</strong> · {o.supplier}{o.expected_on ? <span className="muted"> · due {o.expected_on}</span> : null}</button></li>
            ))}
          </ul>
        )}
        {note && <p className="error">{note}</p>}
      </section>
    )
  }

  return (
    <section className="card">
      <h2>{po.number} <span className="muted small">{po.supplier}</span></h2>
      <ScanBox onScan={scan} label="Scan each item (or type quantities below)" />
      <table className="hh-table">
        <thead><tr><th>Item</th><th>Ordered</th><th>Before</th><th>Now</th></tr></thead>
        <tbody>
          {po.lines.map((l) => (
            <tr key={l.id}>
              <td>{l.name}<br /><span className="muted small">{l.sku} · {l.uom_code}</span></td>
              <td>{q(l.ordered)}</td>
              <td>{q(l.received)}</td>
              <td><input aria-label={`Received now ${l.name}`} inputMode="decimal" value={now[l.id] ?? ''} placeholder="0" onChange={(e) => setNow({ ...now, [l.id]: e.target.value })} />
                {l.track_batches && Number(now[l.id] ?? 0) > 0 && (
                  <div className="hh-lot">
                    <input aria-label={`Batch ${l.name}`} placeholder="Batch no." value={lots[l.id]?.batch_no ?? ''}
                      onChange={(e) => setLots({ ...lots, [l.id]: { batch_no: e.target.value, expires_on: lots[l.id]?.expires_on ?? '' } })} />
                    <input aria-label={`Expiry ${l.name}`} type="date" value={lots[l.id]?.expires_on ?? ''}
                      onChange={(e) => setLots({ ...lots, [l.id]: { batch_no: lots[l.id]?.batch_no ?? '', expires_on: e.target.value } })} />
                  </div>
                )}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {wh.use_bins && <BinSelect bins={wh.bins} value={bin} onChange={setBin} label="Into bin" emptyLabel="Receiving dock" />}
      <label>Supplier delivery note no.<input value={ref} onChange={(e) => setRef(e.target.value)} /></label>
      {note && <p className="muted">{note}</p>}
      <div className="row">
        <button onClick={() => setPo(null)}>Back</button>
        <button className="primary big" disabled={busy || !Object.values(now).some((v) => Number(v) > 0)} onClick={post}>{busy ? 'Saving…' : 'Post receipt'}</button>
      </div>
    </section>
  )
}

function PutAway({ wh }: { wh: WmsWarehouse }) {
  const receiving = wh.bins.find((b) => b.kind === 'receiving') ?? wh.bins[0]
  const [from, setFrom] = useState<string | null>(receiving?.id ?? null)
  const [to, setTo] = useState<string | null>(null)
  const [unit, setUnit] = useState<WmsUnit | null>(null)
  const [qty, setQty] = useState('1')
  const [contents, setContents] = useState<{ sku: string; name: string; on_hand: string }[]>([])
  const [note, setNote] = useState('')

  const load = useCallback(() => { if (from) wms.binStock(from).then(setContents).catch((e) => setNote(msg(e))) }, [from])
  useEffect(load, [load])

  async function scan(code: string) {
    try {
      const r = await wms.lookup(code, wh.id)
      if (r.type === 'bin') { setTo(r.bin.id); setNote(`To bin ${r.bin.code}`) } else { setUnit(r.unit); setNote(`${r.unit.name} (${r.unit.uom_code})`) }
    } catch (e) { setNote(msg(e)) }
  }

  async function move() {
    if (!unit || !from || !to) return
    try {
      await wms.putAway({ warehouse_id: wh.id, product_uom_id: unit.product_uom_id, qty, from_bin_id: from, to_bin_id: to })
      setNote(`Moved ${qty} ${unit.uom_code} ${unit.name} to ${wh.bins.find((b) => b.id === to)?.code}.`)
      setUnit(null); setQty('1'); load()
    } catch (e) { setNote(msg(e)) }
  }

  return (
    <section className="card">
      <h2>Put away / move</h2>
      <BinSelect bins={wh.bins} value={from} onChange={setFrom} label="From bin" />
      <ul className="hh-list small">{contents.map((c) => <li key={c.sku}>{q(c.on_hand)} × {c.name}</li>)}{contents.length === 0 && <li className="muted">Empty</li>}</ul>
      <ScanBox onScan={scan} label="Scan the product, then the shelf label" />
      {unit && (
        <label>Quantity ({unit.uom_code})
          <input inputMode="decimal" value={qty} onChange={(e) => setQty(e.target.value)} />
          <select aria-label="Unit" value={unit.product_uom_id} onChange={(e) => { const u = unit.units.find((x) => x.product_uom_id === e.target.value)!; setUnit({ ...unit, ...u }) }}>
            {unit.units.map((u) => <option key={u.product_uom_id} value={u.product_uom_id}>{u.uom_code}</option>)}
          </select>
        </label>
      )}
      <BinSelect bins={wh.bins.filter((b) => b.id !== from)} value={to} onChange={setTo} label="To bin" emptyLabel="— scan or choose —" />
      {note && <p className="muted">{note}</p>}
      <button className="primary big" disabled={!unit || !to || !(Number(qty) > 0)} onClick={move}>Move</button>
    </section>
  )
}

function Transfers({ wh, done }: { wh: WmsWarehouse; done: (message: string) => void }) {
  const [list, setList] = useState<WmsTransfer[] | null>(null)
  const [t, setT] = useState<WmsTransfer | null>(null)
  const [got, setGot] = useState<Record<string, string>>({})
  const [bin, setBin] = useState<string | null>(null)
  const [note, setNote] = useState('')

  useEffect(() => { wms.transfers(wh.id).then(setList).catch((e) => setNote(msg(e))) }, [wh.id])

  async function receive() {
    if (!t) return
    try {
      const r = await wms.receiveTransfer(t.id, Object.fromEntries(t.lines.map((l) => [l.id, got[l.id] ?? q(l.qty)])), bin)
      done(r.has_discrepancy ? `${r.number} received. The difference has been reported for review.` : `${r.number} received.`)
    } catch (e) { setNote(msg(e)) }
  }

  if (!t) {
    return (
      <section className="card">
        <h2>Receive transfer</h2>
        {list === null ? <p className="muted">Loading…</p> : list.length === 0 ? <p className="muted">Nothing in transit to {wh.code}.</p> : (
          <ul className="hh-list">{list.map((x) => <li key={x.id}><button onClick={() => { setT(x); setGot({}) }}><strong>{x.number}</strong> from {x.from}</button></li>)}</ul>
        )}
        {note && <p className="error">{note}</p>}
      </section>
    )
  }
  return (
    <section className="card">
      <h2>{t.number} <span className="muted small">from {t.from}</span></h2>
      <p className="muted small">Enter what actually arrived. Anything different is reported automatically.</p>
      <table className="hh-table">
        <thead><tr><th>Item</th><th>Sent</th><th>Arrived</th></tr></thead>
        <tbody>{t.lines.map((l) => (
          <tr key={l.id}><td>{l.name}<br /><span className="muted small">{l.uom_code}</span></td><td>{q(l.qty)}</td>
            <td><input aria-label={`Arrived ${l.name}`} inputMode="decimal" value={got[l.id] ?? q(l.qty)} onChange={(e) => setGot({ ...got, [l.id]: e.target.value })} /></td></tr>
        ))}</tbody>
      </table>
      {wh.use_bins && <BinSelect bins={wh.bins} value={bin} onChange={setBin} label="Into bin" emptyLabel="Receiving dock" />}
      {note && <p className="error">{note}</p>}
      <div className="row"><button onClick={() => setT(null)}>Back</button><button className="primary big" onClick={receive}>Confirm receipt</button></div>
    </section>
  )
}

function Count({ wh, done: finish }: { wh: WmsWarehouse; done: (message: string) => void }) {
  const [counts, setCounts] = useState<WmsCount[] | null>(null)
  const [c, setC] = useState<WmsCount | null>(null)
  const [bin, setBin] = useState<WmsBin | null>(null)
  const [unit, setUnit] = useState<WmsUnit | null>(null)
  const [qty, setQty] = useState('')
  const [done, setDone] = useState<string[]>([])
  const [note, setNote] = useState('')
  const [confirming, setConfirming] = useState(false)

  const load = useCallback(() => { wms.counts(wh.id).then(setCounts).catch((e) => setNote(msg(e))) }, [wh.id])
  useEffect(load, [load])

  async function start() {
    try { await wms.startCount(wh.id, null); load() } catch (e) { setNote(msg(e)) }
  }

  async function scan(code: string) {
    try {
      const r = await wms.lookup(code, wh.id)
      if (r.type === 'bin') { setBin(r.bin); setNote(`Counting bin ${r.bin.code}`) } else { setUnit(r.unit); setQty(''); setNote('') }
    } catch (e) { setNote(msg(e)) }
  }

  async function save() {
    if (!c || !unit) return
    try {
      await wms.countLine(c.id, unit.product_uom_id, qty, c.bin_id ?? bin?.id ?? null)
      setDone([`${q(qty)} ${unit.uom_code} ${unit.name}${bin && !c.bin_id ? ` in ${bin.code}` : ''}`, ...done])
      setUnit(null); setQty('')
    } catch (e) { setNote(msg(e)) }
  }

  async function submit() {
    if (!c) return
    if (!confirming) return setConfirming(true) // a second tap confirms
    try { const r = await wms.submitCount(c.id); finish(`${r.number} submitted for approval.`) } catch (e) { setNote(msg(e)); setConfirming(false) }
  }

  if (!c) {
    return (
      <section className="card">
        <h2>Count stock</h2>
        {counts === null ? <p className="muted">Loading…</p> : (
          <ul className="hh-list">{counts.map((x) => <li key={x.id}><button onClick={() => setC(x)}><strong>{x.number}</strong>{x.bin ? ` · bin ${x.bin}` : ' · whole warehouse'}</button></li>)}
            {counts.length === 0 && <li className="muted">No count open.</li>}</ul>
        )}
        <button onClick={start}>Start a new count of {wh.code}</button>
        {note && <p className="error">{note}</p>}
      </section>
    )
  }
  const needBin = wh.use_bins && !c.bin_id && !bin
  return (
    <section className="card">
      <h2>{c.number}{c.bin ? <span className="muted small"> · bin {c.bin}</span> : null}</h2>
      <ScanBox onScan={scan} label={needBin ? 'Scan the bin label first' : 'Scan a product (or the next bin)'} />
      {unit && (
        <form onSubmit={(e) => { e.preventDefault(); save() }}>
          <label>{unit.name} — how many {unit.uom_code}?
            <input className="big" autoFocus inputMode="decimal" value={qty} onChange={(e) => setQty(e.target.value)} />
          </label>
          <button className="primary big" disabled={qty === '' || needBin}>Save count</button>
        </form>
      )}
      {note && <p className="muted">{note}</p>}
      <ul className="hh-list small">{done.map((d, i) => <li key={i}>✓ {d}</li>)}</ul>
      <div className="row"><button onClick={() => setC(null)}>Back</button><button className="primary" onClick={submit}>{confirming ? 'Tap again to submit (uncounted items stay unchanged)' : 'Submit count'}</button></div>
    </section>
  )
}
