import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from './api'
import { connectionState, pingServer, type ConnectionState } from './connection'
import { db, getDevice, getSession } from './db'
import { CloseShift, Enroll, Login, OpenShift } from './screens/Setup'
import { Receipt } from './screens/Receipt'
import { Sell } from './screens/Sell'
import type { Bootstrap, PostedSale, Product } from './types'
import './App.css'

type Stage = 'loading' | 'enroll' | 'login' | 'open-shift' | 'sell' | 'receipt' | 'close-shift' | 'error'

const LABELS: Record<ConnectionState, string> = {
  online: 'Connected',
  'server-down': 'Server not answering',
  offline: 'No network',
}

export default function App() {
  const [stage, setStage] = useState<Stage>('loading')
  const [boot, setBoot] = useState<Bootstrap | null>(null)
  const [products, setProducts] = useState<Product[]>([])
  const [receipt, setReceipt] = useState<PostedSale | null>(null)
  const [error, setError] = useState('')
  const [conn, setConn] = useState<ConnectionState>('offline')

  const load = useCallback(async () => {
    if (!(await getDevice())) return setStage('enroll')
    if (!(await getSession())) return setStage('login')
    try {
      const [b, c] = await Promise.all([api.bootstrap(), api.catalog()])
      setBoot(b)
      setProducts(c.products)
      if (!b.till) {
        setError('This device is not assigned to a till. Ask an administrator to assign it under System → Devices.')
        return setStage('error')
      }
      setStage(b.shift ? 'sell' : 'open-shift')
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        if (e.code === 'device_revoked' && !(await getSession())) {
          await db.kv.delete('device')
          return setStage('enroll')
        }
        return setStage('login')
      }
      setError(e instanceof Error ? e.message : String(e))
      setStage('error')
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    let alive = true
    const check = async () => {
      const ok = navigator.onLine && (await pingServer())
      if (alive) setConn(connectionState(navigator.onLine, ok))
    }
    check()
    const id = setInterval(check, 10_000)
    window.addEventListener('online', check)
    window.addEventListener('offline', check)
    return () => {
      alive = false
      clearInterval(id)
      window.removeEventListener('online', check)
      window.removeEventListener('offline', check)
    }
  }, [])

  async function logout() {
    await api.logout().catch(() => undefined)
    await db.kv.delete('session')
    setStage('login')
  }

  async function refreshCatalog() {
    const [b, c] = await Promise.all([api.bootstrap(), api.catalog()])
    setBoot(b)
    setProducts(c.products)
  }

  return (
    <div className="app">
      <div className={`status status-${conn} no-print`} role="status">{LABELS[conn]}</div>
      {stage === 'loading' && <p className="center muted">Loading…</p>}
      {stage === 'enroll' && <Enroll onDone={load} />}
      {stage === 'login' && <Login onDone={load} />}
      {stage === 'open-shift' && boot && <OpenShift boot={boot} onDone={load} />}
      {stage === 'sell' && boot && (
        <Sell boot={boot} products={products} onRefresh={refreshCatalog} onLogout={logout} onCloseShift={() => setStage('close-shift')}
          onPosted={async (s) => {
            setReceipt(s)
            setStage('receipt')
            refreshCatalog().catch(() => undefined) // updated stock figures for the next sale
          }} />
      )}
      {stage === 'receipt' && boot && receipt && <Receipt sale={receipt} boot={boot} onNew={() => setStage('sell')} />}
      {stage === 'close-shift' && boot && <CloseShift boot={boot} onClosed={logout} onCancel={() => setStage('sell')} />}
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
