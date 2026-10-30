import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react'
import { shiftClosedOnServer } from './shift'
import { api, ApiError, wms } from './api'
import { connectionState, pingServer, type ConnectionState } from './connection'
import { db, getDevice, getLocalShift, getSession, queueCounts, type CacheRecord } from './db'
import { connectLive, type LiveState } from './live'
import { saveUnsentBackup } from './hardware'
import { exportQueueFile, sendQueue } from './offline'
import { HEALTH_EVERY_TICKS, reportHealth, type FixHandlers } from './health'
import { CloseShift, Enroll, Handover, Login, OpenShift } from './screens/Setup'
import { TillSettings } from './screens/TillSettings'
import { Receipt } from './screens/Receipt'
import { Sell } from './screens/Sell'
import type { Bootstrap, PostedSale, Product } from './types'
import { setSignedInName } from './screens/SignedIn'

// Screens only some devices use load when needed (a till never downloads the kitchen screen at start-up).
const Handheld = lazy(() => import('./screens/Handheld').then((m) => ({ default: m.Handheld })))
const Kitchen = lazy(() => import('./screens/Kitchen').then((m) => ({ default: m.Kitchen })))
import './App.css'
import { applyTheme, getTheme, THEMES, type ThemeChoice } from './theme'
import { problemContext } from './problems'
import { applyBrand } from './brand'

type Stage = 'loading' | 'enroll' | 'login' | 'open-shift' | 'handover' | 'sell' | 'receipt' | 'close-shift' | 'settings' | 'handheld' | 'kitchen' | 'error'

const LABELS: Record<ConnectionState, string> = {
  online: 'Connected',
  'server-down': 'Server not answering — working offline',
  offline: 'No network — working offline',
}

/** How often the desktop till saves its own copy of unsent sales while any are waiting. */
const BACKUP_EVERY_MS = 15 * 60_000

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
  const fixes = useRef<FixHandlers | null>(null)
  const stageRef = useRef<string>('loading')
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
      let local = await getLocalShift()
      // The server answers and has no open shift for this till, and the till is not still sending one it opened offline:
      // the shift was closed (on this till, another one, or in the back office). Forget it, so Open shift appears
      // instead of a till whose every sale the server refuses ("Open a shift on this till before selling").
      if (shiftClosedOnServer(live, !!b.shift, local?.shiftId, (await db.queue.filter((q) => q.type === 'shift_open').toArray()).map((q) => q.id))) {
        await db.kv.delete('shift')
        local = undefined
      }
      // Offline (or shift opened offline and not yet synced): the till's own record of the open shift counts.
      const shift = (live ? b.shift : null) ?? (local ? { ...(b.shift ?? {}), id: local.shiftId, status: 'open' as const, opened_at: local.openedAt,
        opened_by: local.openedByName ?? session.userName, opened_by_id: local.openedById, opening_float_minor: local.floatMinor, expected_cash_minor: null, counted_cash_minor: null, over_short_minor: null } : null)
      if (live && b.shift && (!local || local.shiftId !== b.shift.id)) {
        await db.kv.put({ id: 'shift', shiftId: b.shift.id, openedAt: b.shift.opened_at, floatMinor: b.shift.opening_float_minor, cashMinor: local?.cashMinor ?? 0, queued: false,
          openedById: b.shift.opened_by_id, openedByName: b.shift.opened_by })
      }
      setBoot({ ...b, user: { id: session.userId, name: session.userName }, shift })
      setSignedInName(session.userName)
      setProducts(p)
      setCatalogVersion(version)
      if (!b.till) {
        setError('This device is not assigned to a till. Ask an administrator to assign it under System → Devices.')
        return setStage('error')
      }
      // One shift per person: another person's shift still open here is closed (counted) before this person opens their own.
      setStage(!shift ? 'open-shift' : b.shift_per_person !== false && shift.opened_by_id && shift.opened_by_id !== session.userId ? 'handover' : 'sell')
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
    let ticks = 0
    let backedUpAt = 0
    const tick = async () => {
      const ok = navigator.onLine && (await pingServer())
      if (!alive) return
      setConn(connectionState(navigator.onLine, ok))
      const counts = await queueCounts()
      setQueue((q) => ({ ...q, pending: counts.pending, review: counts.review }))
      // The desktop till saves a copy of what is waiting on the PC every 15 minutes (the "Export unsent sales" file).
      if (counts.pending + counts.review > 0 && Date.now() - backedUpAt >= BACKUP_EVERY_MS) {
        backedUpAt = Date.now()
        await saveUnsentBackup(await exportQueueFile()).catch(() => undefined)
      }
      if (ok && counts.pending > 0) await flush()
      // Health every 5 minutes (and at start) while signed in: the answer may carry a fix queued in the back office.
      if (ok && ticks++ % HEALTH_EVERY_TICKS === 0 && (await getSession())?.token && fixes.current) {
        await reportHealth(stageRef.current, fixes.current).catch(() => undefined)
      }
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
    setSignedInName('')
    setStage('login')
  }

  const refreshCatalog = useCallback(async () => {
    const r = await fetchOrCache().catch(() => null)
    if (!r) return
    setBoot((b) => (b ? { ...r.b, user: b.user, shift: b.shift } : r.b))
    setProducts(r.products)
    setCatalogVersion(r.version)
  }, [fetchOrCache])
  // Stock only (a few kilobytes): after each sale and when stock changes. The catalog itself is asked for only when
  // products, prices or promotions change, so a sale no longer makes every till download it all again.
  const refreshStock = useCallback(async () => {
    const cat = (await db.cache.get('catalog')) as Extract<CacheRecord, { id: 'catalog' }> | undefined
    const fresh = await api.stock(cat?.stockVersion)
    if (!fresh) return
    const apply = (list: Product[]) => list.map((p) => (fresh.products[p.id] ? { ...p, ...fresh.products[p.id] } : p))
    setProducts(apply)
    if (cat) await db.cache.put({ ...cat, products: apply(cat.products), stockVersion: fresh.version })
  }, [])
  useEffect(() => {
    stageRef.current = stage
    fixes.current = { syncNow: async () => { await flush() }, refreshCatalog: async () => { await refreshCatalog(); await refreshStock() } }
  }, [stage, flush, refreshCatalog, refreshStock])

  // Live price/stock changes while selling; polling (cheap with ETag) covers a missing or dropped socket.
  const selling = stage === 'sell' || stage === 'receipt'
  const rt = boot?.realtime ?? null
  const rtKey = rt ? JSON.stringify(rt) : ''
  useEffect(() => {
    if (!selling || !online || !rtKey) return
    return connectLive(JSON.parse(rtKey), refreshCatalog, refreshStock, setLive)
  }, [selling, online, rtKey, refreshCatalog, refreshStock])
  useEffect(() => {
    if (!selling || !online || live === 'live') return
    const id = setInterval(() => { refreshCatalog().then(refreshStock) }, 60_000) // both cheap when nothing changed (ETag)
    return () => clearInterval(id)
  }, [selling, online, live, refreshCatalog, refreshStock])

  const handingOver = !!boot?.shift && !!boot.shift.opened_by_id && boot.shift.opened_by_id !== boot.user.id

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
      {stage === 'open-shift' && boot && <OpenShift boot={boot} online={online} onDone={load} onLogout={logout} />}
      {stage === 'sell' && boot && (
        <Sell boot={boot} products={products} online={online} catalogVersion={catalogVersion} queue={queue}
          onRefresh={refreshCatalog} onLogout={logout} onCloseShift={() => setStage('close-shift')} onSettings={() => setStage('settings')} onSendNow={flush}
          onPosted={async (s) => {
            setReceipt(s)
            setStage('receipt')
            setQueue({ ...queue, ...(await queueCounts()) })
            if (s.status !== 'queued') refreshStock() // updated stock figures for the next sale (not the whole catalog)
            else if (online) flush()
          }} />
      )}
      {stage === 'receipt' && boot && receipt && <Receipt sale={receipt} boot={boot} onNew={() => setStage('sell')} />}
      {stage === 'handheld' && <Suspense fallback={<p className="muted center">Loading…</p>}><Handheld onLogout={logout} /></Suspense>}
      {stage === 'kitchen' && <Suspense fallback={<p className="muted center">Loading…</p>}><Kitchen /></Suspense>}
      {stage === 'settings' && boot && <TillSettings boot={boot} online={online} onClose={() => setStage('sell')} />}
      {stage === 'handover' && boot?.shift && <Handover boot={boot} onClose={() => setStage('close-shift')} onLogout={logout} />}
      {stage === 'close-shift' && boot && (
        // At a handover the next person stays signed in and opens their own shift once the other one is closed.
        <CloseShift boot={boot} online={online} onClosed={handingOver ? load : logout} onCancel={() => setStage(handingOver ? 'handover' : 'sell')} />
      )}
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

/** The till screen as a JPEG (about 1280 px wide), drawn in the browser: no permission prompt; null when it cannot be drawn. */
async function screenPicture(): Promise<string | null> {
  const root = document.getElementById('root')
  if (!root) return null
  try {
    const ratio = Math.min(1, 1280 / Math.max(1, root.clientWidth))
    const { toJpeg } = await import('html-to-image') // only when a problem is reported with a picture
    return await toJpeg(root, { quality: 0.7, pixelRatio: ratio, cacheBust: false, skipFonts: true, backgroundColor: getComputedStyle(document.body).backgroundColor || '#fff' })
  } catch {
    return null
  }
}

/** "Report a problem" (bottom of the screen while signed in): the cashier's words go to support with the screen and the till's last errors. */
function ReportProblem({ screen, online, queued }: { screen: string; online: boolean; queued: number }) {
  const [open, setOpen] = useState(false)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState('')
  const [error, setError] = useState('')
  // A picture of the screen as it was when Report a problem was pressed (before this box covered it); the cashier can leave it out.
  const [picture, setPicture] = useState<string | null>(null)
  const [withPicture, setWithPicture] = useState(true)

  async function openReport() {
    setResult('')
    setError('')
    setPicture(await screenPicture())
    setOpen(true)
  }

  async function send(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const r = await api.reportProblem(text.trim(), screen, problemContext({ screen, online, queued }), withPicture ? picture : null)
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
      <button type="button" className="report-problem no-print" onClick={openReport}>Report a problem</button>
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
            {!result && picture && (
              <label className="inline">
                <input type="checkbox" checked={withPicture} onChange={(e) => setWithPicture(e.target.checked)} /> Include a picture of the screen
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
