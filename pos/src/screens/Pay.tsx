import { useMemo, useState } from 'react'
import { formatMoney, parseMoney } from '../money'
import type { Payment, TillCustomer } from '../types'

const LABEL: Record<string, string> = { cash: 'Cash', momo: 'Mobile money', card: 'Card', account: 'On account', points: 'Points', gift_card: 'Gift card', store_credit: 'Store credit' }

const MOMO = [
  { id: 'mtn', name: 'MTN MoMo' },
  { id: 'telecel', name: 'Telecel Cash' },
  { id: 'at', name: 'AT Money' },
]

/**
 * Split tender. Cash may exceed what is owed (change); mobile money and card are entered with
 * the reference from the confirmation message / terminal slip (manual until Paystack/Hubtel are wired in).
 */
export function Pay({ total, currency, exponent, busy, error, customer, onPay, onCancel }: {
  total: number; currency: string; exponent: number; busy: boolean; error: string; customer?: TillCustomer | null
  onPay: (payments: Payment[]) => void; onCancel: () => void
}) {
  const [payments, setPayments] = useState<Payment[]>([])
  const [method, setMethod] = useState<Payment['method']>('cash')
  const [amount, setAmount] = useState('')
  const [provider, setProvider] = useState('mtn')
  const [reference, setReference] = useState('')
  const [phone, setPhone] = useState('')
  const [formError, setFormError] = useState('')
  const money = (m: number) => formatMoney(m, currency, exponent)

  const applied = payments.reduce((a, p) => a + p.amount_minor, 0)
  const due = total - applied
  const change = payments.reduce((a, p) => a + (p.tendered_minor - p.amount_minor), 0)
  const quick = useMemo(() => {
    const unit = 10 ** exponent
    const steps = [1, 5, 10, 20, 50, 100, 200].map((n) => n * unit)
    return [...new Set([due, ...steps.map((s) => Math.ceil(due / s) * s)])].filter((v) => v >= due).slice(0, 4)
  }, [due, exponent])

  function add(tenderedOverride?: number) {
    setFormError('')
    try {
      const tendered = tenderedOverride ?? (amount ? parseMoney(amount, exponent) : due)
      if (tendered <= 0) throw new Error('Enter an amount.')
      if (method !== 'cash' && tendered > due) throw new Error('Only cash can be more than the amount due.')
      if (method === 'momo' && reference.trim().length < 4) throw new Error('Enter the transaction ID from the MoMo confirmation.')
      const applyAmount = Math.min(tendered, due)
      const p: Payment = { method, amount_minor: applyAmount, tendered_minor: method === 'cash' ? tendered : applyAmount }
      if (method === 'momo') Object.assign(p, { provider, reference: reference.trim(), payer_phone: phone.trim() || undefined })
      if (method === 'card' && reference.trim()) p.reference = reference.trim()
      if (method === 'gift_card' || method === 'store_credit') {
        if (reference.trim().length < 6) throw new Error('Scan or type the card code.')
        p.reference = reference.trim().toUpperCase()
      }
      if (method === 'points' && applyAmount > (customer?.points ?? 0)) throw new Error(`${customer?.name} has ${customer?.points ?? 0} points (worth ${money(customer?.points ?? 0)}).`)
      const next = [...payments, p]
      setPayments(next)
      setAmount('')
      setReference('')
      setPhone('')
      if (next.reduce((a, x) => a + x.amount_minor, 0) === total) onPay(next)
    } catch (e) {
      setFormError(e instanceof Error ? e.message : String(e))
    }
  }

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Payment" onKeyDown={(e) => e.key === 'Escape' && !busy && onCancel()}>
      <div className="card pay">
        <h2>Payment</h2>
        <dl className="totals">
          <dt>Total</dt><dd>{money(total)}</dd>
          {applied > 0 && <><dt>Paid</dt><dd>{money(applied)}</dd></>}
          <dt className="strong">Due</dt><dd className="strong">{money(due)}</dd>
          {change > 0 && <><dt>Change</dt><dd className="strong">{money(change)}</dd></>}
        </dl>

        {payments.length > 0 && (
          <ul className="payments">
            {payments.map((p, i) => (
              <li key={i}>
                {p.method === 'momo' ? MOMO.find((m) => m.id === p.provider)?.name : p.method === 'account' ? `On account (${customer?.name ?? ''})` : LABEL[p.method]} {money(p.amount_minor)}
                {p.reference ? ` · ${p.reference}` : ''}
                {!busy && <button type="button" className="link" onClick={() => setPayments(payments.filter((_, j) => j !== i))}>remove</button>}
              </li>
            ))}
          </ul>
        )}

        {due > 0 && (
          <>
            <div className="segmented" role="tablist">
              {(customer ? (['cash', 'momo', 'card', 'account', 'points', 'gift_card', 'store_credit'] as const) : (['cash', 'momo', 'card', 'gift_card', 'store_credit'] as const)).map((m) => (
                <button key={m} type="button" role="tab" aria-selected={method === m} className={method === m ? 'active' : ''} onClick={() => setMethod(m)}>
                  {LABEL[m]}
                </button>
              ))}
            </div>
            {method === 'cash' && (
              <div className="row wrap">
                {quick.map((q) => <button key={q} type="button" className="big" onClick={() => add(q)} disabled={busy}>{money(q)}</button>)}
              </div>
            )}
            {method === 'account' && customer && (
              <p className={customer.credit_limit_minor === null || customer.credit_hold || customer.overdue_minor > 0 ? 'error' : 'muted'}>
                {customer.credit_limit_minor === null ? `${customer.name} has no credit account — a supervisor must approve.`
                  : customer.credit_hold ? 'This account is on credit hold — a supervisor must approve.'
                    : `Credit available ${money(customer.available_minor ?? 0)}${customer.overdue_minor > 0 ? ` · ${money(customer.overdue_minor)} overdue — a supervisor must approve` : ''}.`}
              </p>
            )}
            {method === 'points' && customer && (
              <p className="muted">{customer.name} has {customer.points ?? 0} points, worth {money(customer.points ?? 0)} (100 points = {money(100)}).</p>
            )}
            {method === 'momo' && (
              <div className="row wrap">
                {MOMO.map((m) => (
                  <label key={m.id} className="chip">
                    <input type="radio" name="net" checked={provider === m.id} onChange={() => setProvider(m.id)} /> {m.name}
                  </label>
                ))}
              </div>
            )}
            <form className="grid2" onSubmit={(e) => { e.preventDefault(); add() }}>
              <label>
                {method === 'cash' ? 'Cash received' : 'Amount'} <span className="muted">(blank = {money(due)})</span>
                <input autoFocus className="big mono" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
              </label>
              {(method === 'momo' || method === 'card' || method === 'gift_card' || method === 'store_credit') && (
                <label>
                  {method === 'momo' ? 'Transaction ID' : method === 'card' ? 'Approval code (optional)' : 'Card code'}
                  <input className="big mono" value={reference} onChange={(e) => setReference(e.target.value)} maxLength={80} />
                </label>
              )}
              {method === 'momo' && (
                <label>
                  Customer phone (optional)
                  <input inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} maxLength={20} />
                </label>
              )}
              <button className="primary big" disabled={busy}>Add {method === 'account' ? 'to account' : LABEL[method].toLowerCase()}</button>
            </form>
          </>
        )}
        {(formError || error) && <p className="error" role="alert">{formError || error}</p>}
        <div className="row">
          <button type="button" onClick={onCancel} disabled={busy}>Back to cart (Esc)</button>
          {due === 0 && <button type="button" className="primary big" disabled={busy} onClick={() => onPay(payments)}>{busy ? 'Saving…' : 'Complete sale'}</button>}
        </div>
      </div>
    </div>
  )
}
