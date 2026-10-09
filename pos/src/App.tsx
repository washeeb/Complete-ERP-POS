import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ApiError, wms } from './api'
import { connectionState, pingServer, type ConnectionState } from './connection'
import { db, getDevice, getLocalShift, getSession, queueCounts, type CacheRecord } from './db'
import { connectLive, type LiveState } from './live'
import { sendQueue } from './offline'
import { CloseShift, Enroll, Login, OpenShift } from './screens/Setup'
import { Handheld } from './screens/Handheld'
import { Kitchen } from './screens/Kitchen'
import { Receipt } from './screens/Receipt'
import { Sell } from './screens/Sell'
import type { Bootstrap, PostedSale, Product } from './types'
import './App.css'
import { applyTheme, getTheme, THEMES, type ThemeChoice } from './theme'
import { problemContext } from './problems'
import { applyBrand } from './brand'

type Stage = 'loading' | 'enroll' | 'login' | 'open-shift' | 'sell' | 'receipt' | 'close-shift' | 'handheld' | 'kitchen' | 'error'

const LABELS: Record<ConnectionState, string> = {
  online: 'Connected',
  'server-down': 'Server not answering — working offline',
  offline: 'No network — working offline',
}

export default function App() {
  const [stage, setStage] = useState<Stage>('loading')
  const [boot, setBoot] = useState<Bootstrap | null>(null)
  const [products, setProducts] = useState<Product[]>([])
  const [catalogVersion, setCatalogVersion] = useState<string | null>(null)
  const [receipt, setReceipt] = useState<PostedSale | null>(null)
  const [error, setError] = useState('')
  const [conn, setConn] = useState<ConnectionState>('offline')
  const [queue, setQueue] = useState({ pending: 0, review: 0, sending: false, lastError: null as string | null })
  const [live, setLive] = useState<LiveState>('off')
  const sending = useRef(false)
  const online = conn === 'online'

  useEffect(() => applyBrand(boot), [boot]) // the company's colour as the till's accent

  /** Fetch start-up data and catalog; fall back to the cached copies when the server is unreachable. */
  const fetchOrCache = useCallback(async (): Promise<{ b: Bootstrap; products: Product[]; version: string | null; live: boolean }> => {
    const cachedBoot = (await db.cache.get('bootstrap')) as Extract<CacheRecord, { id: 'bootstrap' }> | undefined
    const cachedCat = (await db.cache.get('catalog')) as Extract<CacheRecord, { id: 'catalog' }> | undefined
    try {
      const b = await api.bootstrap()
      const fresh = await api.catalog(cachedCat?.version)
      const savedAt = new Date().toISOString()
      await db.cache.put({ id: 'bootstrap', data: b, savedAt })
      if (fresh) await db.cache.put({ id: 'catalog', version: fresh.version, products: fresh.products, promotions: fresh.promotions ?? [], utc_offset_minutes: fresh.utc_offset_minutes ?? 0, grocery: fresh.grocery ?? null, pharmacy: fresh.pharmacy ?? null, restaurant: fresh.restaurant ?? null, electronics: fresh.electronics ?? false, fashion: fresh.fashion ?? null, services: fresh.services ?? null, savedAt })
      return { b, products: fresh?.products ?? cachedCat!.products, version: fresh?.version ?? cachedCat!.version, live: true }
    } catch (e) {
      if (!(e instanceof ApiError && (e.code === 'network' || e.status === 401)) || !cachedBoot || !cachedCat) throw e
      if (e.status === 401 && (await getSession())?.token) throw e // a real sign-out, not an outage
      return { b: cachedBoot.data, products: cachedCat.products, version: cachedCat.version, live: false }
    }
  }, [])

  const load = useCallback(async () => {
    const device = await getDevice()
    if (!device) return setStage('enroll')
    if (device.kind === 'kds') return setStage('kitchen') // kitchen screen: the device itself, nobody signs in
    const session = await getSession()
    if (!session?.userId) {
      await db.kv.delete('session') // signed in before offline support: sign in again to receive an offline grant
      return setStage('login')
    }
    if (device.kind === 'handheld') return setStage('handheld') // warehouse scanner: no till, no shift
    try {
      const { b, products: p, version, live } = await fetchOrCache()
      const local = await getLocalShift()
      // Offline (or shift opened offline and not yet synced): the till's own record of the open shift counts.
      const shift = (live ? b.shift : null) ?? (local ? { ...(b.shift ?? {}), id: local.shiftId, status: 'open' as const, opened_at: local.openedAt,
        opened_by: session.userName, opening_float_minor: local.floatMinor, expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null } : null)
      if (live && b.shift && (!local || local.shiftId !== b.shift.id)) {
        await db.kv.put({ id: 'shift', shiftId: b.shift.id, openedAt: b.shift.opened_at, floatMinor: b.shift.opening_float_minor, cashMinor: local?.cashMinor ?? 0, queued: false })
      }
      setBoot({ ...b, user: { id: session.userId, name: session.userName }, shift })
      setProducts(p)
      setCatalogVersion(version)
      if (!b.till) {
        setError('This device is not assigned to a till. Ask an administrator to assign it under System → Devices.')
        return setStage('error')
      }
      setStage(shift ? 'sell' : 'open-shift')
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        if (e.code === 'device_revoked' && !(await getSession())?.token) {
          await db.kv.delete('device')
          return setStage('enroll')
        }
        await db.kv.delete('session')
        return setStage('login')
      }
      setError(e instanceof ApiError && e.code === 'network'
        ? 'This till has never loaded the catalog. Connect it to the server once before selling offline.'
        : e instanceof Error ? e.message : String(e))
      setStage('error')
    }
  }, [fetchOrCache])

  const flush = useCallback(async () => {
    if (sending.current) return
    sending.current = true
    setQueue((q) => ({ ...q, sending: true }))
    try {
      const r = await sendQueue()
      setQueue({ ...(await queueCounts()), sending: false, lastError: r.error })
    } finally {
      sending.current = false
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  // Connection watch + background sending of queued work (every 15 s while anything waits).
  useEffect(() => {
    let alive = true
    const tick = async () => {
      const ok = navigator.onLine && (await pingServer())
      if (!alive) return
      setConn(connectionState(navigator.onLine, ok))
      const counts = await queueCounts()
      setQueue((q) => ({ ...q, pending: counts.pending, review: counts.review }))
      if (ok && counts.pending > 0) await flush()
    }
    tick()
    const id = setInterval(tick, 15_000)
    window.addEventListener('online', tick)
    window.addEventListener('offline', tick)
    return () => {
      alive = false
      clearInterval(id)
      window.removeEventListener('online', tick)
      window.removeEventListener('offline', tick)
    }
  }, [flush])

  async function logout() {
    const handheld = (await getDevice())?.kind === 'handheld'
    if ((await getSession())?.token) await (handheld ? wms.logout() : api.logout()).catch(() => undefined)
    await db.kv.delete('session')
    setStage('login')
  }

  const refreshCatalog = useCallback(async () => {
    const r = await fetchOrCache().catch(() => null)
    if (!r) return
    setBoot((b) => (b ? { ...r.b, user: b.user, shift: b.shift } : r.b))
    setProducts(r.products)
    setCatalogVersion(r.version)
  }, [fetchOrCache])

  // Live price/stock changes while selling; polling (cheap with ETag) covers a missing or dropped socket.
  const selling = stage === 'sell' || stage === 'receipt'
  const rt = boot?.realtime ?? null
  const rtKey = rt ? JSON.stringify(rt) : ''
  useEffect(() => {
    if (!selling || !online || !rtKey) return
    return connectLive(JSON.parse(rtKey), refreshCatalog, setLive)
  }, [selling, online, rtKey, refreshCatalog])
  useEffect(() => {
    if (!selling || !online || live === 'live') return
    const id = setInterval(refreshCatalog, 60_000)
    return () => clearInterval(id)
  }, [selling, online, live, refreshCatalog])

  return (
    <div className="app">
      <ThemePicker />
      <div className={`status status-${conn} no-print`} role="status">{LABELS[conn]}{online && live === 'live' ? ' · live' : ''}</div>
      {boot?.company.practice && (
        <div className="banner practice no-print" role="status">PRACTICE — nothing here is real: made-up sales, no money, nothing sent to GRA or customers.</div>
      )}
      {boot?.license?.message && (
        <div className={`banner no-print ${boot.license.read_only ? 'bad' : 'warn'}`} role="alert">{boot.license.read_only ? 'Read-only: ' : ''}{boot.license.message}</div>
      )}
      {stage === 'loading' && <p className="center muted">Loading…</p>}
      {stage === 'enroll' && <Enroll onDone={load} />}
      {stage === 'login' && <Login onDone={load} />}
      {stage === 'open-shift' && boot && <OpenShift boot={boot} online={online} onDone={load} />}
      {stage === 'sell' && boot && (
        <Sell boot={boot} products={products} online={online} catalogVersion={catalogVersion} queue={queue}
          onRefresh={refreshCatalog} onLogout={logout} onCloseShift={() => setStage('close-shift')} onSendNow={flush}
          onPosted={async (s) => {
            setReceipt(s)
            setStage('receipt')
            setQueue({ ...queue, ...(await queueCounts()) })
            if (s.status !== 'queued') refreshCatalog() // updated stock figures for the next sale
            else if (online) flush()
          }} />
      )}
      {stage === 'receipt' && boot && receipt && <Receipt sale={receipt} boot={boot} onNew={() => setStage('sell')} />}
      {stage === 'handheld' && <Handheld onLogout={logout} />}
      {stage === 'kitchen' && <Kitchen />}
      {stage === 'close-shift' && boot && <CloseShift boot={boot} online={online} onClosed={logout} onCancel={() => setStage('sell')} />}
      {boot && ['sell', 'receipt', 'open-shift', 'close-shift', 'handheld'].includes(stage) && (
        <ReportProblem screen={stage} online={online} queued={queue.pending} />
      )}
      {stage === 'error' && (
        <div className="card narrow">
          <h1>Something needs attention</h1>
          <p className="error">{error}</p>
          <button className="primary" onClick={load}>Try again</button>
        </div>
      )}
    </div>
  )
}

/** System / Light / Dark for this device (bottom left on every screen). */
function ThemePicker() {
  const [theme, setTheme] = useState<ThemeChoice>(getTheme())
  return (
    <select className="theme-picker no-print" aria-label="Theme" value={theme}
      onChange={(e) => { const t = e.target.value as ThemeChoice; setTheme(t); applyTheme(t) }}>
      {(Object.keys(THEMES) as ThemeChoice[]).map((t) => <option key={t} value={t}>Theme: {THEMES[t]}</option>)}
    </select>
  )
}

/** "Report a problem" (bottom of the screen while signed in): the cashier's words go to support with the screen and the till's last errors. */
function ReportProblem({ screen, online, queued }: { screen: string; online: boolean; queued: number }) {
  const [open, setOpen] = useState(false)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState('')
  const [error, setError] = useState('')

  async function send(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const r = await api.reportProblem(text.trim(), screen, problemContext({ screen, online, queued }))
      setResult(`Thank you — problem ${r.number} is recorded${r.sent ? ' and support has been told' : ''}.`)
      setText('')
    } catch (err) {
      setError(err instanceof ApiError && err.code !== 'network' ? err.message : 'No connection to the server: tell your supervisor, or try again when the status says Connected.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <button type="button" className="report-problem no-print" onClick={() => { setOpen(true); setResult(''); setError('') }}>Report a problem</button>
      {open && (
        <div className="modal" role="dialog" aria-modal="true" aria-label="Report a problem" onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
          <form className="card pay" onSubmit={send}>
            <h2>Report a problem</h2>
            {result ? <p className="ok-text">{result}</p> : <p className="muted">What were you doing, and what happened?</p>}
            {error && <p className="error">{error}</p>}
            {!result && (
              <label>Describe it
                <textarea value={text} onChange={(e) => setText(e.target.value)} rows={4} minLength={5} maxLength={5000} required autoFocus
                  placeholder="e.g. I pressed Print and nothing came out." />
              </label>
            )}
            <div className="row">
              <button type="button" onClick={() => setOpen(false)}>{result ? 'Close' : 'Cancel'}</button>
              {!result && <button type="submit" className="primary" disabled={busy || text.trim().length < 5}>{busy ? 'Sending…' : 'Send'}</button>}
            </div>
          </form>
        </div>
      )}
    </>
  )
}
