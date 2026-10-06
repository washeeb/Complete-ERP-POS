import { clock } from '../clock'
import { useState } from 'react'
import { api, ApiError } from '../api'
import { db } from '../db'
import { createDeviceKey } from '../deviceKey'
import { formatMoney, parseMoney } from '../money'
import { offlineSignIn, saveGrant } from '../offline'
import { closeShift, openShift } from '../till'
import type { Bootstrap } from '../types'

/** First run on a new till: enter the one-time code an administrator got when registering this device. */
export function Enroll({ onDone }: { onDone: () => void }) {
  const [code, setCode] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const { privateKey, publicKeySpki } = await createDeviceKey()
      const r = await api.enroll(code, publicKeySpki)
      await db.kv.put({ id: 'device', deviceId: r.device_id, token: r.token, privateKey, enrolledAt: new Date().toISOString(), kind: r.kind })
      onDone()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Enrolment failed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="card narrow" onSubmit={submit}>
      <h1>Set up this device</h1>
      <p className="muted">
        An administrator registers this device under <strong>System → Devices</strong> and gets a one-time code. Enter it here.
      </p>
      <label>
        Enrolment code
        <input autoFocus className="big mono" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())}
          placeholder="ABCD-EFGH-JKMN" maxLength={14} autoComplete="off" required />
      </label>
      {error && <p className="error" role="alert">{error}</p>}
      <button className="primary big" disabled={busy || code.replace(/-/g, '').length !== 12}>{busy ? 'Enrolling…' : 'Enrol this device'}</button>
    </form>
  )
}

/** Username + PIN on an on-screen keypad (touch) or keyboard. */
export function Login({ onDone, deviceName }: { onDone: () => void; deviceName?: string }) {
  const [username, setUsername] = useState('')
  const [pin, setPin] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(e?: React.FormEvent) {
    e?.preventDefault()
    if (!username || pin.length < 4) return
    setBusy(true)
    setError('')
    try {
      try {
        const r = await api.login(username.trim(), pin)
        if (r.offline_grant) await saveGrant(r.offline_grant) // lets this person keep selling here if the server goes away (tills only)
        await db.kv.put({ id: 'session', token: r.token, userId: r.user.id, userName: r.user.name, expiresAt: r.expires_at })
      } catch (err) {
        if (!(err instanceof ApiError && err.code === 'network')) throw err
        // Server unreachable: the PIN unlocks this person's offline grant on this till.
        const g = await offlineSignIn(username, pin)
        await db.kv.put({ id: 'session', token: null, userId: g.user_id, userName: g.user_name, expiresAt: g.expires_at })
      }
      onDone()
    } catch (err) {
      setPin('')
      setError(err instanceof Error ? err.message : 'Sign-in failed.')
    } finally {
      setBusy(false)
    }
  }

  const [clocked, setClocked] = useState('')

  async function punch(kind: 'in' | 'out') {
    if (!username || pin.length < 4) {
      setError('Enter your username and PIN, then press Clock in or Clock out.')
      return
    }
    setBusy(true)
    setError('')
    setClocked('')
    try {
      const r = await clock(username, pin, kind)
      const time = new Date(r.at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
      setClocked(`${r.name} clocked ${kind} at ${time}${r.offline ? ' (saved on this till; sent when the server is back)' : ''}.`)
      setUsername('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Clocking failed.')
    } finally {
      setPin('')
      setBusy(false)
    }
  }

  const press = (k: string) => setPin((p) => (k === '⌫' ? p.slice(0, -1) : p.length < 8 ? p + k : p))

  return (
    <form className="card narrow" onSubmit={submit}>
      <h1>Sign in</h1>
      {deviceName && <p className="muted">{deviceName}</p>}
      <label>
        Username
        <input autoFocus className="big" value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="username" autoCapitalize="none" required />
      </label>
      <label>
        PIN
        <input className="big mono pin" type="password" inputMode="numeric" value={pin} autoComplete="off"
          onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 8))} />
      </label>
      <div className="keypad" aria-label="PIN keypad">
        {['1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0', 'OK'].map((k) =>
          k === 'OK'
            ? <button key={k} type="submit" className="primary" disabled={busy}>OK</button>
            : <button key={k} type="button" onClick={() => press(k)} aria-label={k === '⌫' ? 'Delete' : k}>{k}</button>,
        )}
      </div>
      <div className="row clock-row">
        <button type="button" onClick={() => punch('in')} disabled={busy}>Clock in</button>
        <button type="button" onClick={() => punch('out')} disabled={busy}>Clock out</button>
      </div>
      {clocked && <p className="ok" role="status">{clocked}</p>}
      {error && <p className="error" role="alert">{error}</p>}
    </form>
  )
}

export function OpenShift({ boot, online, onDone }: { boot: Bootstrap; online: boolean; onDone: () => void }) {
  const [float, setFloat] = useState('')
  const [error, setError] = useState('')

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    try {
      await openShift(parseMoney(float || '0', boot.company.exponent), online)
      onDone()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not open the shift.')
    }
  }

  return (
    <form className="card narrow" onSubmit={submit}>
      <h1>Open shift</h1>
      <p className="muted">{boot.branch.name} · {boot.till?.name}. Count the cash in the drawer before you start.</p>
      <label>
        Opening float ({boot.company.currency})
        <input autoFocus className="big mono" inputMode="decimal" value={float} onChange={(e) => setFloat(e.target.value)} placeholder="0.00" />
      </label>
      {error && <p className="error" role="alert">{error}</p>}
      <button className="primary big">Open shift</button>
    </form>
  )
}

/** Blind close: the cashier counts first; expected cash is shown only after submitting. */
export function CloseShift({ boot, online, onClosed, onCancel }: { boot: Bootstrap; online: boolean; onClosed: () => void; onCancel: () => void }) {
  const [counted, setCounted] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const [result, setResult] = useState<null | { expected: number; counted: number; diff: number; provisional: boolean }>(null)
  const money = (m: number) => formatMoney(m, boot.company.currency, boot.company.exponent)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    try {
      const { shift, provisional } = await closeShift(parseMoney(counted, boot.company.exponent), note, online)
      setResult({ expected: shift.expected_cash_minor ?? 0, counted: shift.counted_cash_minor ?? 0, diff: shift.over_short_minor ?? 0, provisional })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not close the shift.')
    }
  }

  if (result) {
    return (
      <div className="card narrow">
        <h1>Shift closed</h1>
        {result.provisional && <p className="banner warn">Worked out on this till while offline; the server confirms it when the till reconnects.</p>}
        <dl className="totals">
          <dt>Counted</dt><dd>{money(result.counted)}</dd>
          <dt>Expected</dt><dd>{money(result.expected)}</dd>
          <dt>{result.diff < 0 ? 'Short' : result.diff > 0 ? 'Over' : 'Balanced'}</dt>
          <dd className={result.diff < 0 ? 'error' : ''}>{money(Math.abs(result.diff))}</dd>
        </dl>
        <button className="primary big" onClick={onClosed}>Done</button>
      </div>
    )
  }

  return (
    <form className="card narrow" onSubmit={submit}>
      <h1>Close shift</h1>
      <p className="muted">Count all the cash in the drawer, including the float.</p>
      <label>
        Cash counted ({boot.company.currency})
        <input autoFocus className="big mono" inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} required />
      </label>
      <label>
        Note (optional)
        <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
      </label>
      {error && <p className="error" role="alert">{error}</p>}
      <div className="row">
        <button type="button" onClick={onCancel}>Back to selling</button>
        <button className="primary big">Close shift</button>
      </div>
    </form>
  )
}
