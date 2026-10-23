import { useEffect, useRef, useState } from 'react'
import { v7 as uuidv7 } from 'uuid'
import { api } from '../api'
import { POLL_MS, finished } from '../momo'
import type { MomoCharge } from '../momo'

/**
 * Payment request to the customer's phone: the customer approves with their mobile money PIN (or gives the code some
 * Telecel / AT payments send), and the payment is added only when the provider confirms it.
 */
export function MomoRequest({ network, amountMinor, money, disabled, onPaid }: {
  network: string; amountMinor: number; money: (m: number) => string; disabled: boolean; onPaid: (c: MomoCharge) => void
}) {
  const [phone, setPhone] = useState('')
  const [otp, setOtp] = useState('')
  const [charge, setCharge] = useState<MomoCharge | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const timer = useRef<number | null>(null)
  const id = useRef<string | null>(null)

  const stop = () => { if (timer.current) window.clearTimeout(timer.current); timer.current = null }
  useEffect(() => stop, [])

  function follow(c: MomoCharge) {
    setCharge(c)
    if (c.status === 'success') { stop(); id.current = null; onPaid(c); return }
    if (finished(c)) { stop(); id.current = null; return }
    stop()
    timer.current = window.setTimeout(async () => {
      try { follow((await api.momoStatus(c.id)).charge) } catch { follow(c) } // a slow network: keep asking
    }, POLL_MS)
  }

  async function send() {
    setError('')
    setBusy(true)
    try {
      id.current ??= uuidv7() // the same id when retried, so the phone is asked only once
      follow((await api.momoRequest({ id: id.current, network, phone: phone.trim(), amount_minor: amountMinor })).charge)
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  async function sendOtp() {
    if (!charge) return
    setBusy(true)
    try { follow((await api.momoOtp(charge.id, otp.trim())).charge); setOtp('') } catch (e) { setError(e instanceof Error ? e.message : String(e)) } finally { setBusy(false) }
  }

  const waiting = charge && (charge.status === 'pending' || charge.status === 'otp')
  return (
    <div className="momo-request" aria-live="polite">
      {!waiting && (
        <form className="row wrap" onSubmit={(e) => { e.preventDefault(); void send() }}>
          <label className="inline">Customer’s MoMo number
            <input inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} maxLength={20} placeholder="024 123 4567" aria-label="Customer’s MoMo number" />
          </label>
          <button className="primary big" disabled={disabled || busy || phone.replace(/\D/g, '').length < 9}>
            {busy ? 'Sending…' : `Send payment request (${money(amountMinor)})`}</button>
        </form>
      )}
      {waiting && charge.status === 'pending' && (
        <p className="notice"><span className="spinner" aria-hidden="true" /> Waiting for the customer to approve {money(charge.amount_minor)} on their phone ({charge.phone})…
          {charge.message ? ` ${charge.message}` : ''}</p>
      )}
      {waiting && charge.status === 'otp' && (
        <form className="row wrap" onSubmit={(e) => { e.preventDefault(); void sendOtp() }}>
          <label className="inline">{charge.message ?? 'Code the customer received'}
            <input inputMode="numeric" value={otp} onChange={(e) => setOtp(e.target.value)} maxLength={12} aria-label="Code from the customer" /></label>
          <button className="primary" disabled={busy || otp.trim().length < 4}>Confirm code</button>
        </form>
      )}
      {waiting && <button type="button" className="link" onClick={() => { stop(); id.current = null; setCharge(null) }}>Stop waiting</button>}
      {charge && (charge.status === 'failed' || charge.status === 'expired') && <p className="error" role="alert">{charge.message ?? 'Not paid.'} You can send the request again.</p>}
      {error && <p className="error" role="alert">{error}</p>}
    </div>
  )
}
