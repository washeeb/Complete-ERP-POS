import { useState } from 'react'
import { ApiError } from '../api'
import { recordDrawer, shrinkPhoto, type DrawerBody } from '../drawer'
import { openDrawer } from '../hardware'
import { formatMoney, parseMoney } from '../money'
import { offlineSignIn } from '../offline'
import type { DrawerSlip } from '../slip'
import type { Bootstrap } from '../types'
import { SignedIn } from './SignedIn'

type Tab = 'payout' | 'payin' | 'drop' | 'no_sale'
const TABS: { key: Tab; label: string }[] = [
  { key: 'payout', label: 'Pay out (expense)' }, { key: 'payin', label: 'Pay in' }, { key: 'drop', label: 'Cash drop' }, { key: 'no_sale', label: 'Open drawer (no sale)' },
]

/**
 * The drawer besides sales. Pay out: an expense paid from the drawer (what it was spent on, who was paid, why, the
 * receipt number and an optional photo); above the till's limit a supervisor approves with their PIN. Pay in: cash
 * added, with the reason. Cash drop: a supervisor takes cash to the safe (their PIN). Open drawer: no sale, with the reason and,
 * unless the company switched it off, a manager's PIN.
 */
export function DrawerScreen({ boot, online, initial = 'payout', onDone, onCancel }: {
  boot: Bootstrap; online: boolean; initial?: Tab; onDone: (message: string, slip: DrawerSlip | null) => void; onCancel: () => void
}) {
  const [tab, setTab] = useState<Tab>(initial)
  const [amount, setAmount] = useState('')
  const [accountId, setAccountId] = useState('')
  const [payee, setPayee] = useState('')
  const [reason, setReason] = useState('')
  const [reference, setReference] = useState('')
  const [photo, setPhoto] = useState<string | null>(null)
  const [supUser, setSupUser] = useState('')
  const [supPin, setSupPin] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const money = (m: number) => formatMoney(m, boot.company.currency, boot.company.exponent)
  const drawer = boot.drawer ?? { payout_approval_minor: 0, cash_limit_minor: 0, categories: [] }
  const minor = amount.trim() ? parseMoney(amount, boot.company.exponent) : 0
  const noSaleApproval = tab === 'no_sale' && drawer.no_sale_approval !== false
  const needsSupervisor = tab === 'drop' || (tab === 'payout' && minor > drawer.payout_approval_minor) || noSaleApproval
  const approver = noSaleApproval ? 'Manager' : 'Supervisor'

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setBusy(true)
    const body: DrawerBody = {
      kind: tab, amount_minor: tab === 'no_sale' ? 0 : minor, account_id: tab === 'payout' ? accountId : null, payee: tab === 'payout' ? payee.trim() || null : null,
      reason: reason.trim() || null, reference: tab === 'payout' ? reference.trim() || null : null, photo: tab === 'payout' ? photo : null,
      approval: needsSupervisor ? { username: supUser.trim(), pin: supPin } : null,
    }
    try {
      let r: Awaited<ReturnType<typeof recordDrawer>>
      let approver: string | null = null
      try {
        r = await recordDrawer(online, body)
      } catch (err) {
        if (!(err instanceof ApiError && err.code === 'offline_approval_needed') || !needsSupervisor) throw err
        // The server cannot be reached: the supervisor's PIN is checked here against their offline sign-in.
        const g = await offlineSignIn(supUser, supPin)
        if (g.user_id === boot.user.id) throw new Error('Another person must approve this.')
        r = await recordDrawer(false, { ...body, approved_by: g.user_id })
        approver = g.user_name
      }
      if (tab === 'no_sale' || tab === 'payout' || tab === 'payin') await openDrawer().catch(() => undefined)
      // A slip for the money that moved (not for opening the drawer).
      const slip: DrawerSlip | null = tab === 'no_sale' ? null : {
        id: r.id, kind: tab, amountMinor: minor, at: r.at, cashier: boot.user.name, approvedBy: r.approvedBy ?? approver, queued: r.queued,
        category: tab === 'payout' ? drawer.categories.find((c) => c.id === accountId)?.name ?? null : null,
        payee: body.payee ?? null, reason: body.reason ?? null, reference: body.reference ?? null,
      }
      onDone(tab === 'payout' ? `Paid out ${money(minor)}.` : tab === 'payin' ? `Paid in ${money(minor)}.` : tab === 'drop' ? `Cash drop of ${money(minor)} recorded.` : 'Drawer opened.', slip)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  async function pickPhoto(file: File | undefined) {
    if (!file) return setPhoto(null)
    try {
      setPhoto(await shrinkPhoto(file))
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }

  const ready = (tab === 'no_sale' || minor > 0) && (tab !== 'payout' || accountId) && (tab === 'drop' || reason.trim() !== '')
    && (!needsSupervisor || (supUser.trim() !== '' && supPin.length >= 4))

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Drawer">
      <form className="card pay drawer" onSubmit={submit}>
        <h2>Drawer</h2>
        <div className="row tabs" role="tablist" aria-label="What are you doing">
          {TABS.map((t) => (
            <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} className={tab === t.key ? 'primary' : ''}
              onClick={() => { setTab(t.key); setError('') }}>{t.label}</button>
          ))}
        </div>
        {tab !== 'no_sale' && (
          <label>Amount ({boot.company.currency})
            <input autoFocus className="big mono" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^0-9.]/g, ''))} required />
          </label>
        )}
        {tab === 'payout' && (
          <>
            <label>Spent on
              <select value={accountId} onChange={(e) => setAccountId(e.target.value)} required>
                <option value="">Choose…</option>
                {drawer.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </label>
            <label>Paid to (optional)<input value={payee} onChange={(e) => setPayee(e.target.value)} maxLength={255} placeholder="e.g. Kofi (cleaner), Shell Adenta" /></label>
          </>
        )}
        <label>{tab === 'payout' ? 'What for' : tab === 'drop' ? 'Note (optional)' : 'Reason'}
          <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} required={tab !== 'drop'}
            placeholder={tab === 'payout' ? 'e.g. Taxi to the bank, drinking water' : tab === 'payin' ? 'e.g. Change from the safe' : tab === 'no_sale' ? 'e.g. Change for a customer' : ''} />
        </label>
        {tab === 'payout' && (
          <>
            <label>Receipt number (optional)<input value={reference} onChange={(e) => setReference(e.target.value)} maxLength={80} /></label>
            <label>Photo of the receipt (optional)
              <input type="file" accept="image/*" capture="environment" onChange={(e) => pickPhoto(e.target.files?.[0])} />
            </label>
            {photo && <img src={photo} alt="Receipt photo" className="receipt-photo" />}
            <p className="muted small">Keep the paper receipt with the cash. Above {money(drawer.payout_approval_minor)} a supervisor approves.</p>
          </>
        )}
        {tab === 'drop' && <p className="muted small">The supervisor counts the cash in front of you and takes it to the safe.</p>}
        {noSaleApproval && <p className="muted small">A manager approves opening the drawer without a sale.</p>}
        {needsSupervisor && (
          <fieldset className="supervisor">
            <legend>{approver}</legend>
            <SignedIn />
            <label>{approver} username<input autoComplete="off" value={supUser} onChange={(e) => setSupUser(e.target.value)} autoCapitalize="none" /></label>
            <label>{approver} PIN<input type="text" className="pin-mask" autoComplete="off" inputMode="numeric" value={supPin} onChange={(e) => setSupPin(e.target.value.replace(/\D/g, '').slice(0, 8))} /></label>
          </fieldset>
        )}
        {error && <p className="error" role="alert">{error}</p>}
        <div className="row">
          <button type="button" onClick={onCancel} disabled={busy}>Back</button>
          <button className="primary big" disabled={busy || !ready}>{busy ? 'Saving…' : TABS.find((t) => t.key === tab)!.label}</button>
        </div>
      </form>
    </div>
  )
}
