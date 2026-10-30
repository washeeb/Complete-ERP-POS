import { clock } from '../clock'
import { Fragment, useEffect, useRef, useState } from 'react'
import { api, ApiError, type ShiftGrant } from '../api'
import { db } from '../db'
import { createDeviceKey } from '../deviceKey'
import { formatMoney, parseMoney } from '../money'
import { offlineSignIn, saveGrant } from '../offline'
import { applyUpdateIfWaiting, UPDATE_READY } from '../swUpdate'
import { closeShift, openShift } from '../till'
import { isStaffCard, personColor } from '../person'
import { CLOSE_CHECKS, type Bootstrap, type OtherCheck, type Shift } from '../types'
import { SignedIn } from './SignedIn'

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
  const pinRef = useRef<HTMLInputElement>(null)
  const card = isStaffCard(username) // a scanned staff card stands in for the username; the PIN is still needed

  // A new till version waiting: switch to it here, where nobody is in the middle of a sale (not while someone types).
  const typing = username !== '' || pin !== ''
  useEffect(() => {
    if (!typing) applyUpdateIfWaiting()
    const ready = () => { if (!typing) applyUpdateIfWaiting() }
    window.addEventListener(UPDATE_READY, ready)
    return () => window.removeEventListener(UPDATE_READY, ready)
  }, [typing])

  async function submit(e?: React.FormEvent) {
    e?.preventDefault()
    if (card && pin.length < 4) return pinRef.current?.focus() // the scanner's Enter after the card: now the PIN
    if (!username || pin.length < 4) return
    setBusy(true)
    setError('')
    try {
      try {
        const r = await api.login(username.trim(), pin)
        if (r.offline_grant) await saveGrant(r.offline_grant, card ? username : null) // lets this person keep selling here if the server goes away (tills only)
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
      {card ? (
        <div className="card-scanned" role="status">
          <span>Staff card scanned. Type your PIN.</span>
          <button type="button" onClick={() => { setUsername(''); setPin('') }}>Not my card</button>
        </div>
      ) : (
        <label>
          Username, or scan your staff card
          <input autoFocus className="big" value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="off" autoCapitalize="none" spellCheck={false} required />
        </label>
      )}
      <label>
        PIN
        <input ref={pinRef} className="big mono pin pin-mask" type="text" inputMode="numeric" value={pin} autoComplete="off"
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

/**
 * One shift per person: someone signs in on a till where another person's shift is still open. That shift is closed
 * first (the drawer counted), so each person's sales and cash stay apart; then they open their own.
 */
export function Handover({ boot, onClose, onLogout }: { boot: Bootstrap; onClose: () => void; onLogout: () => void }) {
  const s = boot.shift!
  const since = new Date(s.opened_at).toLocaleString(undefined, { weekday: 'short', hour: '2-digit', minute: '2-digit' })
  return (
    <div className="card narrow">
      <h1>{s.opened_by}’s shift is still open</h1>
      <p>{s.opened_by} opened a shift on this till ({since}) and it was not closed. You sell in your own shift, so your sales and cash
        are not counted as {s.opened_by}’s.</p>
      <p><strong>Close {s.opened_by}’s shift:</strong> count all the cash in the drawer now, then open your own shift with the cash you start with.
        If {s.opened_by} is still here, they can sign in and close it themselves instead.</p>
      <div className="row">
        <button type="button" onClick={onLogout}>Sign out</button>
        <button className="primary big" onClick={onClose}>Close {s.opened_by}’s shift</button>
      </div>
    </div>
  )
}

export function OpenShift({ boot, online, onDone, onLogout }: { boot: Bootstrap; online: boolean; onDone: () => void; onLogout: () => void }) {
  const [float, setFloat] = useState('')
  const [error, setError] = useState('')
  const vaults = boot.cash?.vaults === true
  const issued = (boot.cash?.sessions ?? []).filter((v) => v.status === 'issued')
  const [vaultId, setVaultId] = useState<string>(issued[0]?.id ?? '')
  const [checked, setChecked] = useState(false)
  // Personal vaults: she opens only on her own vault (or one lent to her today); without one, a manager lends one by PIN.
  const personal = boot.personal_vaults ?? null
  const mine = personal?.mine ?? []
  const [ownId, setOwnId] = useState<string>(mine.find((v) => !v.in_use)?.id ?? '')
  const [lending, setLending] = useState(false)
  // Automatic shifts: outside trading hours, or again after this till's shift was closed, a manager allows it.
  const [needsGrant, setNeedsGrant] = useState(false)
  const [grant, setGrant] = useState<ShiftGrant>({ username: '', pin: '', reason: '' })
  const money = (m: number) => formatMoney(m, boot.company.currency, boot.company.exponent)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    const allowed = needsGrant ? grant : null
    try {
      if (personal) {
        const v = mine.find((x) => x.id === ownId)
        if (!v) throw new Error('Choose your vault.')
        await openShift(v.float_minor, online, null, allowed, { id: v.id, name: v.name })
      } else if (vaults) {
        const v = issued.find((x) => x.id === vaultId)
        if (!v) throw new Error('Choose your vault.')
        await openShift(v.float_minor, online, v.id, allowed)
      } else {
        await openShift(parseMoney(float || '0', boot.company.exponent), online, null, allowed)
      }
      onDone()
    } catch (err) {
      if (err instanceof ApiError && (err.code === 'outside_hours' || err.code === 'till_closed')) setNeedsGrant(true)
      setError(err instanceof Error ? err.message : 'Could not open the shift.')
    }
  }

  if (personal && (mine.length === 0 || lending)) {
    return <LendVault boot={boot} online={online} onLent={onDone} onBack={mine.length ? () => setLending(false) : null} onLogout={onLogout} />
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
      <p className="signed-in">This shift will be <strong>{boot.user.name}</strong>’s. Not you? <button type="button" className="link" onClick={onLogout}>Sign out</button></p>
      {personal ? (
        <>
          <fieldset className="vaults">
            <legend>Your vault</legend>
            {mine.map((v) => (
              <label key={v.id} className={`vault-choice ${v.in_use ? 'taken' : ''}`}>
                <input type="radio" name="vault" value={v.id} checked={ownId === v.id} disabled={!!v.in_use} onChange={() => setOwnId(v.id)} />
                <span><strong>{v.name}</strong> · float {money(v.float_minor)}{v.lent_by ? ` · lent to you today by ${v.lent_by}` : ''}{v.in_use ? ` · open on ${v.in_use}` : ''}</span>
              </label>
            ))}
          </fieldset>
          <label className="check">
            <input type="checkbox" checked={checked} onChange={(e) => setChecked(e.target.checked)} required />
            {personal.trays === 'shared' ? 'I have counted the cash in the drawer' : personal.trays === 'till' ? 'I have counted the float in my drawer' : 'My own tray is in the drawer and I have counted its float'}: {money(mine.find((x) => x.id === ownId)?.float_minor ?? 0)}
          </label>
          <p className="muted small">Only your vault is shown, so your sales and cash are never counted under someone else.{' '}
            <button type="button" className="link" onClick={() => setLending(true)}>Covering for someone? A manager lends you their vault</button></p>
        </>
      ) : vaults ? (
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
      {needsGrant && (
        <fieldset className="grant">
          <legend>A manager allows {boot.user.name} to open a shift</legend>
          <SignedIn />
          <label>Manager's username<input autoComplete="off" value={grant.username} onChange={(e) => setGrant({ ...grant, username: e.target.value })} required /></label>
          <label>Manager's PIN<input type="text" className="pin-mask" inputMode="numeric" autoComplete="off" maxLength={8} value={grant.pin} onChange={(e) => setGrant({ ...grant, pin: e.target.value })} required /></label>
          <label>Reason<input maxLength={255} value={grant.reason} onChange={(e) => setGrant({ ...grant, reason: e.target.value })} placeholder="e.g. late delivery to sell, cover for the afternoon" required /></label>
        </fieldset>
      )}
      {error && <p className="error" role="alert">{error}</p>}
      <button className="primary big">{needsGrant ? 'Open shift (manager allows)' : 'Open shift'}</button>
    </form>
  )
}

/** No vault of her own here today: a manager lends her one (theirs is away, or she covers) with their username and PIN. */
function LendVault({ boot, online, onLent, onBack, onLogout }: { boot: Bootstrap; online: boolean; onLent: () => void; onBack: (() => void) | null; onLogout: () => void }) {
  const others = (boot.personal_vaults?.branch ?? []).filter((v) => !(boot.personal_vaults?.mine ?? []).some((m) => m.id === v.id))
  const [vaultId, setVaultId] = useState(others[0]?.id ?? '')
  const [approval, setApproval] = useState({ username: '', pin: '' })
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function lend(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      await api.lendVault(vaultId, reason, approval)
      onLent()
    } catch (err) {
      setApproval({ ...approval, pin: '' })
      setError(err instanceof Error ? err.message : 'Could not lend the vault.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="card narrow" onSubmit={lend}>
      <h1>Open shift</h1>
      <p className="who-band" style={{ background: personColor(boot.user.id) }}>{boot.user.name}</p>
      {!onBack && <p>You have no vault on {boot.till?.name ?? 'this till'} today. Each person sells only from her own vault.</p>}
      <p className="muted">A manager can lend you another person's vault for today (they are away, or you cover their shift). Otherwise ask for a vault of your own (Cash → Shifts and vaults).</p>
      {!online && <p className="error">Lending a vault needs the server. Try again when the till is online.</p>}
      {others.length === 0 ? <p className="muted">There is no other vault at this branch.</p> : (
        <>
          <label>Vault<select value={vaultId} onChange={(e) => setVaultId(e.target.value)}>
            {others.map((v) => <option key={v.id} value={v.id}>{v.name}{v.holder ? ` (${v.holder})` : ''}</option>)}
          </select></label>
          <SignedIn />
          <label>Manager's username<input autoComplete="off" autoCapitalize="none" value={approval.username} onChange={(e) => setApproval({ ...approval, username: e.target.value })} required /></label>
          <label>Manager's PIN<input type="text" className="pin-mask" inputMode="numeric" autoComplete="off" value={approval.pin} onChange={(e) => setApproval({ ...approval, pin: e.target.value.replace(/\D/g, '').slice(0, 8) })} required /></label>
          <label>Reason<input maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Ama is on leave" required /></label>
        </>
      )}
      {error && <p className="error" role="alert">{error}</p>}
      <div className="row">
        {onBack ? <button type="button" onClick={onBack}>Back</button> : <button type="button" onClick={onLogout}>Sign out</button>}
        <button className="primary big" disabled={busy || !online || others.length === 0 || approval.pin.length < 4}>{busy ? 'Checking…' : 'Lend it to me for today'}</button>
      </div>
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
  const [result, setResult] = useState<null | { expected: number | null; counted: number; diff: number | null; provisional: boolean; other: OtherCheck[]; otherSent: boolean
    takings: Shift['takings'] }>(null)
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
      const read = Object.fromEntries(CLOSE_CHECKS.filter((c) => (other[c.key] ?? '').trim() !== '')
        .map((c) => [c.key, c.pieces ? parseInt(other[c.key], 10) || 0 : parseMoney(other[c.key], boot.company.exponent)]))
      const { shift, provisional } = await closeShift(amount, note, online, denoms, Object.keys(read).length ? read : null)
      setResult({ expected: shift.expected_cash_minor, counted: shift.counted_cash_minor ?? amount, diff: shift.over_short_minor, provisional,
        other: shift.other ?? [], otherSent: Object.keys(read).length > 0, takings: shift.takings ?? null })
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
            <h2>Mobile money, card, vouchers and coupons</h2>
            <dl className="totals">
              {result.other.map((c) => (
                <Fragment key={c.key}>
                  <dt>{c.label}</dt>
                  <dd className={(c.diff ?? 0) < 0 ? 'error' : ''}>
                    {(() => {
                      const v = (n: number) => (c.pieces ? String(n) : money(n))
                      return c.counted === null ? `${v(c.expected)} expected · not checked`
                        : c.diff === 0 ? `${v(c.counted)} · balanced` : `${v(c.counted)} entered · ${v(c.expected)} expected · ${(c.diff ?? 0) < 0 ? 'short' : 'over'} ${v(Math.abs(c.diff ?? 0))}`
                    })()}
                  </dd>
                </Fragment>
              ))}
            </dl>
            {result.other.some((c) => (c.diff ?? 0) !== 0) && <p className="muted">A difference is listed for the manager on Till shifts. Check the MoMo transaction IDs on the receipts against the merchant statement.</p>}
          </>
        )}
        {result.provisional && result.otherSent && <p className="muted">Mobile money and card are checked when the till reconnects.</p>}
        {result.takings && (result.takings.lines.length > 0 || (result.takings.refunds ?? []).length > 0) && (
          <>
            <h2>Sales this shift</h2>
            <dl className="totals">
              {result.takings.lines.map((l) => <Fragment key={l.label}><dt>{l.label}</dt><dd>{money(l.amount_minor)}</dd></Fragment>)}
              {(result.takings.refunds ?? []).map((l) => (
                <Fragment key={`r-${l.label}`}><dt>Returns refunded · {l.label}</dt><dd className="error">−{money(l.amount_minor)}</dd></Fragment>
              ))}
              <dt><strong>Total sales</strong></dt><dd><strong>{money(result.takings.total_minor)}</strong></dd>
            </dl>
            {(result.takings.refunds ?? []).length > 0 && (
              <p className="muted small">Returns refunded are taken off what should be in the {boot.cash?.vaults ? 'tray' : 'drawer'} and on the MoMo phone. If a customer did not
                get that money back, it explains an amount over: tell a manager.</p>
            )}
            <p className="muted small">Cash above is what should be in the {boot.cash?.vaults ? 'tray' : 'drawer'} (with the float); the total is everything sold, however it was paid.</p>
          </>
        )}
        {result.provisional && <p className="muted">The sales by payment method show on Till shifts when the till reconnects.</p>}
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
        <legend>Mobile money, card, vouchers and coupons</legend>
        <p className="muted small">Type what the merchant phone (or MoMo statement) shows for this shift, the card terminal's total, the value of the gift vouchers and credit notes you took, and how many coupons you collected. Leave a box empty if you do not take it.</p>
        {CLOSE_CHECKS.map((c) => (
          <label key={c.key}>
            {c.label}{c.pieces ? ' collected' : ` (${boot.company.currency})`}
            <input className="mono" inputMode={c.pieces ? 'numeric' : 'decimal'} value={other[c.key] ?? ''} placeholder="not checked"
              onChange={(e) => setOther({ ...other, [c.key]: e.target.value.replace(c.pieces ? /[^0-9]/g : /[^0-9.]/g, '') })} />
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
