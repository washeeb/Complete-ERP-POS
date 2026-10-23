import { useCallback, useEffect, useRef, useState } from 'react'
import { v7 as uuidv7 } from 'uuid'
import { ApiError, wms } from '../api'
import { getSession } from '../db'
import { retryRefused } from '../offline'
import { cameraScanSupported, listenForScans, scanFeedback } from '../scanner'
import { act, cachedGet, discard, lookupLocal, updateCopy, waiting, type ActResult } from '../wmsOffline'
import { CameraScan } from './CameraScan'
import type { WmsBin, WmsBootstrap, WmsCatalog, WmsCount, WmsOrder, WmsTransfer, WmsUnit, WmsWarehouse } from '../types'

/**
 * Warehouse handheld (scanner phone): receive purchase orders, put away into bins, receive transfers, count.
 * Works with its branch's server, and keeps working without it (offline handhelds, owner request 2026-10-23): lists
 * and scans come from the handheld's own copy, and every action is queued and sent when the server is back
 * (wmsOffline.ts). A hardware scanner types the code and presses Enter (or Tab), so the scan box is an ordinary text
 * field; a scan that arrives while the cursor is elsewhere is caught too, and phones without a scanner use the camera.
 */

type Mode = 'home' | 'receive' | 'putaway' | 'transfers' | 'count'
type Ctx = { wh: WmsWarehouse; catalog: WmsCatalog | null; online: boolean; refresh: () => void }

const q = (v: string | number) => String(Number(v))
const msg = (e: unknown) => (e instanceof ApiError || e instanceof Error ? e.message : String(e))
/** The server only when this person is signed in with it; signed in offline, the handheld's copy. */
const remote = async <T,>(fn: () => Promise<T>): Promise<T> => ((await getSession())?.token ? fn() : Promise.reject(new ApiError('network', 'Signed in offline.', 0)))
/** The person's message for an action's outcome. */
const told = (r: ActResult, done: string) => r.state === 'done' ? done : r.state === 'queued'
  ? `${done.replace(/\.$/, '')} — saved on this handheld; it is sent when the server is back.` : `Refused: ${r.detail ?? 'see the list on the menu'}`

function ScanBox({ onScan, label = 'Scan or type a code', autoFocus = true }: { onScan: (code: string) => void; label?: string; autoFocus?: boolean }) {
  const [v, setV] = useState('')
  const [camera, setCamera] = useState(false)
  const latest = useRef(onScan)
  useEffect(() => { latest.current = onScan }, [onScan])
  // A scan while the cursor is not in the box (after tapping a button or a list) still counts.
  useEffect(() => listenForScans((code) => latest.current(code)), [])
  const submit = () => { if (v.trim()) { scanFeedback(true); latest.current(v.trim()) } setV('') }
  return (
    <form className="scan" onSubmit={(e) => { e.preventDefault(); submit() }}>
      <input aria-label={label} placeholder={label} value={v} onChange={(e) => setV(e.target.value)} autoFocus={autoFocus} className="big" autoComplete="off"
        enterKeyHint="go" onKeyDown={(e) => { if (e.key === 'Tab' && v.trim()) { e.preventDefault(); submit() } }} />
      {cameraScanSupported() && <button type="button" className="camera-btn" onClick={() => setCamera(true)} aria-label="Scan with the camera">📷</button>}
      {camera && <CameraScan onScan={(code) => { setCamera(false); latest.current(code) }} onClose={() => setCamera(false)} />}
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

/** A scan: on the handheld first (works offline), then the server for anything new since the last copy. */
async function lookup(ctx: Ctx, code: string) {
  const local = ctx.catalog ? lookupLocal(ctx.catalog, code) : null
  if (local) return local
  if (!ctx.online) throw new Error(`“${code}” is not in this handheld’s copy. It is checked when the server is back.`)
  return wms.lookup(code, ctx.wh.id)
}

export function Handheld({ onLogout }: { onLogout: () => void }) {
  const [boot, setBoot] = useState<WmsBootstrap | null>(null)
  const [whId, setWhId] = useState<string>('')
  const [mode, setMode] = useState<Mode>('home')
  const [error, setError] = useState('')
  const [flash, setFlash] = useState('')
  const [online, setOnline] = useState(true)
  const [catalog, setCatalog] = useState<WmsCatalog | null>(null)
  const [queue, setQueue] = useState<Awaited<ReturnType<typeof waiting>>>({ pending: 0, refused: [] })
  const finish = (m: string) => { setFlash(m); setMode('home') }

  const refreshQueue = useCallback(() => { waiting().then(setQueue).catch(() => undefined) }, [])
  useEffect(() => {
    cachedGet('bootstrap', () => remote(wms.bootstrap)).then((r) => { setBoot(r.data); setOnline(!r.offline); setWhId((w) => w || r.data.warehouses[0]?.id || '') })
      .catch((e) => setError(msg(e)))
  }, [])
  // The scan catalogue for this warehouse, refreshed every 30 minutes while online.
  useEffect(() => {
    if (!whId) return
    const get = () => cachedGet(`catalog:${whId}`, () => remote(() => wms.catalog(whId))).then((r) => { setCatalog(r.data); setOnline(!r.offline) }).catch(() => setCatalog(null))
    get()
    const t = window.setInterval(get, 30 * 60_000)
    return () => window.clearInterval(t)
  }, [whId])
  useEffect(() => { refreshQueue(); const t = window.setInterval(refreshQueue, 5000); return () => window.clearInterval(t) }, [refreshQueue, mode])

  const wh = boot?.warehouses.find((w) => w.id === whId) ?? null
  if (error && !boot) return <div className="card narrow"><p className="error">{error}</p><button onClick={onLogout}>Sign out</button></div>
  if (!boot) return <p className="center muted">Loading…</p>
  const ctx: Ctx | null = wh ? { wh, catalog, online, refresh: refreshQueue } : null

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
      {(!online || queue.pending > 0) && (
        <p className={online ? 'banner' : 'banner warn'} role="status">
          {online ? '' : 'Working offline: everything is saved on this handheld. '}
          {queue.pending > 0 ? `${queue.pending} action(s) waiting to be sent.` : ''}
        </p>
      )}
      {flash && mode === 'home' && <p className="banner" role="status">{flash}</p>}
      {mode === 'home' && queue.refused.length > 0 && (
        <section className="card">
          <h2>Refused by the server</h2>
          <p className="muted small">Fix the cause (or ask a manager), then send again. Discard only what you have done again correctly.</p>
          <ul className="hh-list small">{queue.refused.map((r) => (
            <li key={r.seq}><strong>{r.label}</strong> — {r.error} <button className="link" onClick={() => { discard(r.seq).then(refreshQueue) }}>Discard</button></li>
          ))}</ul>
          <button onClick={() => { retryRefused().then(refreshQueue) }}>Send again</button>
        </section>
      )}
      {!wh && <p className="error">You are not set up for warehouse work at {boot.branch.name}.</p>}
      {wh && mode === 'home' && (
        <nav className="hh-menu">
          {wh.can.includes('stock.receive') && <button className="primary big" onClick={() => { setFlash(''); setMode('receive') }}>Receive delivery</button>}
          {wh.can.includes('stock.receive') && wh.use_bins && <button className="primary big" onClick={() => { setFlash(''); setMode('putaway') }}>Put away / move</button>}
          {wh.can.includes('stock.transfer') && <button className="primary big" onClick={() => { setFlash(''); setMode('transfers') }}>Receive transfer</button>}
          {wh.can.includes('stock.count') && <button className="primary big" onClick={() => { setFlash(''); setMode('count') }}>Count stock</button>}
        </nav>
      )}
      {ctx && mode === 'receive' && <Receive ctx={ctx} done={finish} />}
      {ctx && mode === 'putaway' && <PutAway ctx={ctx} />}
      {ctx && mode === 'transfers' && <Transfers ctx={ctx} done={finish} />}
      {ctx && mode === 'count' && <Count ctx={ctx} done={finish} />}
    </div>
  )
}

function Receive({ ctx, done }: { ctx: Ctx; done: (message: string) => void }) {
  const { wh } = ctx
  const [orders, setOrders] = useState<WmsOrder[] | null>(null)
  const [po, setPo] = useState<WmsOrder | null>(null)
  const [now, setNow] = useState<Record<string, string>>({})
  const [lots, setLots] = useState<Record<string, { batch_no: string; expires_on: string }>>({}) // batch-tracked lines: from the pack
  const [sns, setSns] = useState<Record<string, string>>({}) // serial-numbered lines: one number per unit (scanned)
  const [bin, setBin] = useState<string | null>(null)
  const [ref, setRef] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const clientId = useRef(uuidv7()) // one per delivery: a resend never receives twice

  useEffect(() => { cachedGet(`orders:${wh.id}`, () => remote(() => wms.purchaseOrders(wh.id))).then((r) => setOrders(r.data)).catch((e) => setNote(msg(e))) }, [wh.id])

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
        ...(l.track_batches ? { batch_no: lots[l.id]?.batch_no ?? '', expires_on: lots[l.id]?.expires_on || null } : {}),
        ...(l.track_serials ? { serials: (sns[l.id] ?? '').split(/[\s,]+/).filter(Boolean) } : {}) }))
      const r = await act('wms_receive', { purchase_order_id: po.id, client_id: clientId.current, supplier_ref: ref || undefined, lines }, `Receipt for ${po.number}`, clientId.current)
      if (r.state === 'refused') return setNote(told(r, ''))
      // The handheld's copy shows it received, until the server's own list comes back.
      await updateCopy<WmsOrder[]>(`orders:${wh.id}`, (list) => list.map((o) => o.id !== po.id ? o : { ...o, lines: o.lines.map((l) => ({ ...l, received: q(Number(l.received) + Number(now[l.id] ?? 0)) })) }))
      ctx.refresh()
      done(told(r, r.detail?.startsWith('More') ? `${r.number}: more than ordered — waiting for a manager. Nothing is in stock yet.` : `${r.number ?? po.number} received into stock.`))
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
                )}
                {l.track_serials && Number(now[l.id] ?? 0) > 0 && (
                  <textarea aria-label={`Serial numbers ${l.name}`} placeholder="Scan each serial / IMEI" rows={3} value={sns[l.id] ?? ''}
                    onChange={(e) => setSns({ ...sns, [l.id]: e.target.value })} />
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

function PutAway({ ctx }: { ctx: Ctx }) {
  const { wh } = ctx
  const receiving = wh.bins.find((b) => b.kind === 'receiving') ?? wh.bins[0]
  const [from, setFrom] = useState<string | null>(receiving?.id ?? null)
  const [to, setTo] = useState<string | null>(null)
  const [unit, setUnit] = useState<WmsUnit | null>(null)
  const [qty, setQty] = useState('1')
  const [contents, setContents] = useState<{ sku: string; name: string; on_hand: string }[] | null>([])
  const [note, setNote] = useState('')

  // What is in the bin needs the server (it changes all the time); offline the move is still recorded.
  const load = useCallback(() => { if (from) remote(() => wms.binStock(from)).then(setContents).catch(() => setContents(null)) }, [from])
  useEffect(load, [load])

  async function scan(code: string) {
    try {
      const r = await lookup(ctx, code)
      if (r.type === 'bin') { setTo(r.bin.id); setNote(`To bin ${r.bin.code}`) } else { setUnit(r.unit); setNote(`${r.unit.name} (${r.unit.uom_code})`) }
    } catch (e) { setNote(msg(e)) }
  }

  async function move() {
    if (!unit || !from || !to) return
    try {
      const toCode = wh.bins.find((b) => b.id === to)?.code
      const r = await act('wms_putaway', { warehouse_id: wh.id, product_uom_id: unit.product_uom_id, qty, from_bin_id: from, to_bin_id: to }, `Move ${qty} ${unit.uom_code} ${unit.name} to ${toCode}`)
      setNote(told(r, `Moved ${qty} ${unit.uom_code} ${unit.name} to ${toCode}.`))
      if (r.state !== 'refused') { setUnit(null); setQty('1'); load() }
      ctx.refresh()
    } catch (e) { setNote(msg(e)) }
  }

  return (
    <section className="card">
      <h2>Put away / move</h2>
      <BinSelect bins={wh.bins} value={from} onChange={setFrom} label="From bin" />
      {contents === null ? <p className="muted small">Bin contents show when the server can be reached.</p>
        : <ul className="hh-list small">{contents.map((c) => <li key={c.sku}>{q(c.on_hand)} × {c.name}</li>)}{contents.length === 0 && <li className="muted">Empty</li>}</ul>}
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

function Transfers({ ctx, done }: { ctx: Ctx; done: (message: string) => void }) {
  const { wh } = ctx
  const [list, setList] = useState<WmsTransfer[] | null>(null)
  const [t, setT] = useState<WmsTransfer | null>(null)
  const [got, setGot] = useState<Record<string, string>>({})
  const [bin, setBin] = useState<string | null>(null)
  const [note, setNote] = useState('')

  useEffect(() => { cachedGet(`transfers:${wh.id}`, () => remote(() => wms.transfers(wh.id))).then((r) => setList(r.data)).catch((e) => setNote(msg(e))) }, [wh.id])

  async function receive() {
    if (!t) return
    try {
      const r = await act('wms_transfer', { transfer_id: t.id, lines: Object.fromEntries(t.lines.map((l) => [l.id, got[l.id] ?? q(l.qty)])), to_bin_id: bin }, `Transfer ${t.number}`)
      if (r.state === 'refused') return setNote(told(r, ''))
      await updateCopy<WmsTransfer[]>(`transfers:${wh.id}`, (all) => all.filter((x) => x.id !== t.id))
      ctx.refresh()
      done(told(r, r.detail?.startsWith('Received;') ? `${t.number} received. The difference has been reported for review.` : `${t.number} received.`))
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

function Count({ ctx, done: finish }: { ctx: Ctx; done: (message: string) => void }) {
  const { wh } = ctx
  const [counts, setCounts] = useState<WmsCount[] | null>(null)
  const [c, setC] = useState<WmsCount | null>(null)
  const [bin, setBin] = useState<WmsBin | null>(null)
  const [unit, setUnit] = useState<WmsUnit | null>(null)
  const [qty, setQty] = useState('')
  const [done, setDone] = useState<string[]>([])
  const [note, setNote] = useState('')
  const [confirming, setConfirming] = useState(false)
  const key = `counts:${wh.id}`

  const load = useCallback(() => { cachedGet(key, () => remote(() => wms.counts(wh.id))).then((r) => setCounts(r.data)).catch((e) => setNote(msg(e))) }, [key, wh.id])
  useEffect(load, [load])

  async function start() {
    try {
      const id = uuidv7() // the count's own id, so counting can begin before the server has it
      const r = await act('wms_count_start', { id, warehouse_id: wh.id, bin_id: null }, `New count of ${wh.code}`, id)
      if (r.state === 'refused') return setNote(told(r, ''))
      const count: WmsCount = { id, number: r.number ?? 'New count (not sent yet)', bin: null, bin_id: null, blind: true, counted: 0, lines: 0 }
      await updateCopy<WmsCount[]>(key, (all) => [...all.filter((x) => x.id !== id), count])
      ctx.refresh()
      if (r.state === 'done') load(); else setCounts((all) => [...(all ?? []), count])
    } catch (e) { setNote(msg(e)) }
  }

  async function scan(code: string) {
    try {
      const r = await lookup(ctx, code)
      if (r.type === 'bin') { setBin(r.bin); setNote(`Counting bin ${r.bin.code}`) } else { setUnit(r.unit); setQty(''); setNote('') }
    } catch (e) { setNote(msg(e)) }
  }

  async function save() {
    if (!c || !unit) return
    try {
      const where = bin && !c.bin_id ? ` in ${bin.code}` : ''
      const r = await act('wms_count_line', { count_id: c.id, product_uom_id: unit.product_uom_id, qty, bin_id: c.bin_id ?? bin?.id ?? null }, `${c.number}: ${q(qty)} ${unit.uom_code} ${unit.name}${where}`)
      if (r.state === 'refused') return setNote(told(r, ''))
      setDone([`${q(qty)} ${unit.uom_code} ${unit.name}${where}${r.state === 'queued' ? ' (saved, not sent yet)' : ''}`, ...done])
      setUnit(null); setQty('')
      ctx.refresh()
    } catch (e) { setNote(msg(e)) }
  }

  async function submit() {
    if (!c) return
    if (!confirming) return setConfirming(true) // a second tap confirms
    try {
      const r = await act('wms_count_submit', { count_id: c.id }, `Submit ${c.number}`)
      if (r.state === 'refused') { setConfirming(false); return setNote(told(r, '')) }
      await updateCopy<WmsCount[]>(key, (all) => all.filter((x) => x.id !== c.id))
      ctx.refresh()
      finish(told(r, `${r.number ?? c.number} submitted for approval.`))
    } catch (e) { setNote(msg(e)); setConfirming(false) }
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
