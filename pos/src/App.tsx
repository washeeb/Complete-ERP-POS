import { useEffect, useState } from 'react'
import { connectionState, pingServer, type ConnectionState } from './connection'
import './App.css'

const LABELS: Record<ConnectionState, string> = {
  online: 'Connected to server',
  'server-down': 'Network OK, server not answering',
  offline: 'No network — working offline',
}

export default function App() {
  const [state, setState] = useState<ConnectionState>('offline')

  useEffect(() => {
    let alive = true
    const check = async () => {
      const reachable = navigator.onLine && (await pingServer())
      if (alive) setState(connectionState(navigator.onLine, reachable))
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

  return (
    <main className="shell">
      <h1>Synclytics POS</h1>
      <p className={`status status-${state}`} role="status">{LABELS[state]}</p>
      <p className="note">Phase 0 shell. Selling arrives in Phase 2; offline selling in Phase 3.</p>
    </main>
  )
}
