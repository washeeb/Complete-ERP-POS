import { clock } from '../clock'
import { Fragment, useState } from 'react'
import { api, ApiError } from '../api'
import { db } from '../db'
import { createDeviceKey } from '../deviceKey'
import { formatMoney, parseMoney } from '../money'
import { offlineSignIn, saveGrant } from '../offline'
import { closeShift, openShift } from '../till'
import { CLOSE_CHECKS, type Bootstrap, type OtherCheck } from '../types'

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
  const vaults = boot.cash?.vaults === true
  const issued = (boot.cash?.sessions ?? []).filter((v) => v.status === 'issued')
  const [vaultId, setVaultId] = useState<string>(issued[0]?.id ?? '')
  const [checked, setChecked] = useState(false)
  const money = (m: number) => formatMoney(m, boot.company.currency, boot.company.exponent)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    try {
      if (vaults) {
        const v = issued.find((x) => x.id === vaultId)
        if (!v) throw new Error('Choose your vault.')
        await openShift(v.float_minor, online, v.id)
      } else {
        await openShift(parseMoney(float || '0', boot.company.exponent), online)
      }
      onDone()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not open the shift.')
    }
  }

  if (vaults && issued.length === 0) {
    return (
      <div className="card narrow">
        <h1>Open shift</h1>
        <p>No vault has been issued to you on {boot.till?.name ?? 'this till'} yet.</p>
        <p className="muted">Ask the cashier to issue your vault (Cash desk). It holds your float; only you can open the till on it.</p>
        <button className="primary big" onClick={() => window.location.reload()}>Check again</button>
      </div>
    )
  }

  return (
    <form className="card narrow" onSubmit={submit}>
      <h1>Open shift</h1>
      <p className="muted">{boot.branch.name} · {boot.till?.name}. Count the cash before you start.</p>
      {vaults ? (
        <>
          <fieldset className="vaults">
            <legend>Your vault</legend>
            {issued.map((v) => (
              <label key={v.id} className="vault-choice">
                <input type="radio" name="vault" value={v.id} checked={vaultId === v.id} onChange={() => setVaultId(v.id)} />
                <span><strong>{v.vault}</strong> · float {money(v.float_minor)} · issued by {v.issued_by}</span>
              </label>
            ))}
          </fieldset>
          <label className="check">
            <input type="checkbox" checked={checked} onChange={(e) => setChecked(e.target.checked)} required />
            I have counted the float in the tray: {money(issued.find((x) => x.id === vaultId)?.float_minor ?? 0)}
          </label>
          <p className="muted small">If the float is not right, do not open the till: give the tray back to the cashier.</p>
        </>
      ) : (
        <label>
          Opening float ({boot.company.currency})
          <input autoFocus className="big mono" inputMode="decimal" value={float} onChange={(e) => setFloat(e.target.value)} placeholder="0.00" />
        </label>
      )}
      {error && <p className="error" role="alert">{error}</p>}
      <button className="primary big">Open shift</button>
    </form>
  )
}

/**
 * Blind close: the person on the till counts first; expected cash is shown only after submitting, and not at all on a
 * vault (the cashier counts the tray next, against this count). Counted note by note when the currency has a list.
 */
export function CloseShift({ boot, online, onClosed, onCancel }: { boot: Bootstrap; online: boolean; onClosed: () => void; onCancel: () => void }) {
  const [counted, setCounted] = useState('')
  const [counts, setCounts] = useState<Record<string, string>>({})
  const [note, setNote] = useState('')
  const [other, setOther] = useState<Record<string, string>>({})
  const [error, setError] = useState('')
  const [result, setResult] = useState<null | { expected: number | null; counted: number; diff: number | null; provisional: boolean; other: OtherCheck[]; otherSent: boolean }>(null)
  const money = (m: number) => formatMoney(m, boot.company.currency, boot.company.exponent)
  const denominations = boot.cash?.denominations ?? []
  const byNotes = denominations.length > 0
  const total = denominations.reduce((sum, d) => sum + (parseInt(counts[d.key] || '0', 10) || 0) * d.value, 0)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    try {
      const denoms = byNotes ? Object.fromEntries(denominations.map((d) => [d.key, parseInt(counts[d.key] || '0', 10) || 0]).filter(([, n]) => (n as number) > 0)) : null
      const amount = byNotes ? total : parseMoney(counted, boot.company.exponent)
      // MoMo and card: only what was filled in is checked; an empty box means "not checked".
      const read = Object.fromEntries(CLOSE_CHECKS.filter((c) => (other[c.key] ?? '').trim() !== '').map((c) => [c.key, parseMoney(other[c.key], boot.company.exponent)]))
      const { shift, provisional } = await closeShift(amount, note, online, denoms, Object.keys(read).length ? read : null)
      setResult({ expected: shift.expected_cash_minor, counted: shift.counted_cash_minor ?? amount, diff: shift.over_short_minor, provisional,
        other: shift.other ?? [], otherSent: Object.keys(read).length > 0 })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not close the shift.')
    }
  }

  if (result) {
    return (
      <div className="card narrow">
        <h1>Shift closed</h1>
        {result.provisional && <p className="banner warn">Worked out on this till while offline; the server confirms it when the till reconnects.</p>}
        {result.expected === null ? (
          <>
            <dl className="totals"><dt>You counted</dt><dd>{money(result.counted)}</dd></dl>
            <p>Close the tray and hand it to the cashier. They count it again before it is checked against the till.</p>
          </>
        ) : (
          <dl className="totals">
            <dt>Counted</dt><dd>{money(result.counted)}</dd>
            <dt>Expected</dt><dd>{money(result.expected)}</dd>
            <dt>{(result.diff ?? 0) < 0 ? 'Short' : (result.diff ?? 0) > 0 ? 'Over' : 'Balanced'}</dt>
            <dd className={(result.diff ?? 0) < 0 ? 'error' : ''}>{money(Math.abs(result.diff ?? 0))}</dd>
          </dl>
        )}
        {result.other.length > 0 && (
          <>
            <h2>Mobile money and card</h2>
            <dl className="totals">
              {result.other.map((c) => (
                <Fragment key={c.key}>
                  <dt>{c.label}</dt>
                  <dd className={(c.diff ?? 0) < 0 ? 'error' : ''}>
                    {c.counted === null ? `${money(c.expected)} expected · not checked`
                      : c.diff === 0 ? `${money(c.counted)} · balanced` : `${money(c.counted)} entered · ${money(c.expected)} expected · ${(c.diff ?? 0) < 0 ? 'short' : 'over'} ${money(Math.abs(c.diff ?? 0))}`}
                  </dd>
                </Fragment>
              ))}
            </dl>
            {result.other.some((c) => (c.diff ?? 0) !== 0) && <p className="muted">A difference is listed for the manager on Till shifts. Check the MoMo transaction IDs on the receipts against the merchant statement.</p>}
          </>
        )}
        {result.provisional && result.otherSent && <p className="muted">Mobile money and card are checked when the till reconnects.</p>}
        <button className="primary big" onClick={onClosed}>Done</button>
      </div>
    )
  }

  return (
    <form className={`card narrow${byNotes ? ' count' : ''}`} onSubmit={submit}>
      <h1>Close shift</h1>
      <p className="muted">Count all the cash in the {boot.cash?.vaults ? 'tray' : 'drawer'}, including the float.</p>
      {byNotes ? (
        <>
          {/* Notes and coins side by side (stacked on a narrow screen): one line each, with what that line comes to. */}
          <div className="denoms">
            {([['Notes', denominations.filter((d) => d.key.startsWith('n'))], ['Coins', denominations.filter((d) => !d.key.startsWith('n'))]] as const)
              .filter(([, list]) => list.length > 0).map(([title, list]) => (
                <fieldset key={title} className="denom-group">
                  <legend>{title}</legend>
                  {list.map((d) => {
                    const n = parseInt(counts[d.key] || '0', 10) || 0
                    return (
                      <label key={d.key} className="denom-row">
                        <span>{d.label.replace(/ (note|coin)$/, '')}</span>
                        <input autoFocus={d.key === denominations[0]?.key} className="mono" inputMode="numeric" pattern="[0-9]*" value={counts[d.key] ?? ''} placeholder="0"
                          onChange={(e) => setCounts({ ...counts, [d.key]: e.target.value.replace(/[^0-9]/g, '') })} aria-label={`Number of ${d.label}s`} />
                        <span className="denom-amount mono" aria-hidden="true">{n > 0 ? money(n * d.value) : ''}</span>
                      </label>
                    )
                  })}
                </fieldset>
              ))}
          </div>
          <p className="denoms-total"><span>Total counted</span> <strong className="mono">{money(total)}</strong></p>
        </>
      ) : (
        <label>
          Cash counted ({boot.company.currency})
          <input autoFocus className="big mono" inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} required />
        </label>
      )}
      <fieldset className="other-checks">
        <legend>Mobile money and card</legend>
        <p className="muted small">Type what the merchant phone (or MoMo statement) shows for this shift, and the card terminal's total. Leave a box empty if you do not take it.</p>
        {CLOSE_CHECKS.map((c) => (
          <label key={c.key}>
            {c.label} ({boot.company.currency})
            <input className="mono" inputMode="decimal" value={other[c.key] ?? ''} placeholder="not checked"
              onChange={(e) => setOther({ ...other, [c.key]: e.target.value.replace(/[^0-9.]/g, '') })} />
          </label>
        ))}
      </fieldset>
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
