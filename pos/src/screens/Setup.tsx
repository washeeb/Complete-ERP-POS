import { useState } from 'react'
import { api, ApiError } from '../api'
import { db } from '../db'
import { createDeviceKey } from '../deviceKey'
import { formatMoney, parseMoney } from '../money'
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
      await db.kv.put({ id: 'device', deviceId: r.device_id, token: r.token, privateKey, enrolledAt: new Date().toISOString() })
      onDone()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Enrolment failed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="card narrow" onSubmit={submit}>
      <h1>Set up this till</h1>
      <p className="muted">
        An administrator registers this device under <strong>System → Devices</strong> and gets a one-time code. Enter it here.
      </p>
      <label>
        Enrolment code
        <input autoFocus className="big mono" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())}
          placeholder="ABCD-EFGH-JKMN" maxLength={14} autoComplete="off" required />
      </label>
      {error && <p className="error" role="alert">{error}</p>}
      <button className="primary big" disabled={busy || code.replace(/-/g, '').length !== 12}>{busy ? 'Enrolling…' : 'Enrol this till'}</button>
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
      const r = await api.login(username.trim(), pin)
      await db.kv.put({ id: 'session', token: r.token, userName: r.user.name, expiresAt: r.expires_at })
      onDone()
    } catch (err) {
      setPin('')
      setError(err instanceof ApiError ? err.message : 'Sign-in failed.')
    } finally {
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
      {error && <p className="error" role="alert">{error}</p>}
    </form>
  )
}

export function OpenShift({ boot, onDone }: { boot: Bootstrap; onDone: () => void }) {
  const [float, setFloat] = useState('')
  const [error, setError] = useState('')

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    try {
      await api.openShift(parseMoney(float || '0', boot.company.exponent))
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
export function CloseShift({ boot, onClosed, onCancel }: { boot: Bootstrap; onClosed: () => void; onCancel: () => void }) {
  const [counted, setCounted] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const [result, setResult] = useState<null | { expected: number; counted: number; diff: number }>(null)
  const money = (m: number) => formatMoney(m, boot.company.currency, boot.company.exponent)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    try {
      const { shift } = await api.closeShift(parseMoney(counted, boot.company.exponent), note)
      setResult({ expected: shift.expected_cash_minor ?? 0, counted: shift.counted_cash_minor ?? 0, diff: shift.over_short_minor ?? 0 })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not close the shift.')
    }
  }

  if (result) {
    return (
      <div className="card narrow">
        <h1>Shift closed</h1>
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
