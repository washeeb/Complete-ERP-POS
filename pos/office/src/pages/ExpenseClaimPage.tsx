import { Badge, Button, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkRegular, DismissRegular, MoneyRegular, OpenRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, ApiError, type EcDetail } from '../api'
import { Ask } from '../form/Ask'
import { ConfirmItsYou } from '../form/ConfirmItsYou'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { EC_TONE } from './ExpenseClaimsPage'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '10px 18px' },
  two: { display: 'grid', gap: '10px', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' },
})

const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const today = () => new Date().toISOString().slice(0, 10)

/**
 * One expense claim (release 6): who spent what, on what, and the receipt; who approved or rejected it and who paid it
 * back. The buttons of the current page: Approve and Reject (someone other than whoever claimed it), and Pay (from a cash,
 * bank or mobile money account; above the company's limit it asks to confirm it is you first).
 */
export function ExpenseClaimPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [c, setC] = useState<EcDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<'approve' | 'reject' | 'pay' | null>(null)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.expenseClaim(id).then((x) => { setC(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !c) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!c) return <div className={page.page}><Spinner label="Opening the claim…" /></div>
  const m = (v: number) => money(v, c.currency, c.exponent)
  const a = c.actions
  const finish = (next: EcDetail, text: string) => { setC(next); setDialog(null); setDone(text) }

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/expense-claims'))}>Expense claims</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{c.number}</span>
          <Badge appearance="tint" color={EC_TONE[c.status] ?? 'informative'}>{c.status_label}</Badge>
        </h1>
        <div className={s.actions}>
          {a.approve && <Button appearance="primary" icon={<CheckmarkRegular />} onClick={() => setDialog('approve')}>Approve</Button>}
          {a.pay && <Button appearance="primary" icon={<MoneyRegular />} onClick={() => setDialog('pay')}>Pay</Button>}
          {a.reject && <Button icon={<DismissRegular />} onClick={() => setDialog('reject')}>Reject</Button>}
          {c.receipt && <Button icon={<OpenRegular />} onClick={() => window.open(c.receipt!, '_blank', 'noopener')}>Open the receipt</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {!c.receipt && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>No receipt attached.</MessageBarBody></MessageBar>}
      {c.rejection_reason && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>Rejected because: {c.rejection_reason}</MessageBarBody></MessageBar>}

      <section className={s.panel} aria-label="The claim">
        <div className={s.facts}>
          <Fact label="Amount">{m(c.amount)}</Fact>
          <Fact label="Claimed by">{c.by ?? '—'}</Fact>
          <Fact label="Branch">{c.branch ?? '—'}</Fact>
          <Fact label="Date spent">{day(c.spent_on)}</Fact>
          <Fact label="What for">{c.for ?? '—'}</Fact>
          <Fact label="Paid to">{c.payee ?? '—'}</Fact>
          <Fact label="Approved / rejected by">{c.decided_by ?? '—'}</Fact>
          <Fact label="Paid">{c.paid ? `${c.paid.by}, ${day(c.paid.on)} from ${c.paid.from ?? '—'}` : '—'}</Fact>
        </div>
        <Text block style={{ marginTop: 12 }}>{c.description}</Text>
      </section>

      {dialog === 'approve' && (
        <Ask title={`Approve ${c.number}?`} go="Approve" ready onClose={() => setDialog(null)}
          run={() => api.expenseClaimAction(c.id, 'approve').then((x) => finish(x, 'Claim approved.'))}>
          <Text>{m(c.amount)} for {c.description}, claimed by {c.by}. Then it is paid back.</Text>
        </Ask>
      )}
      {dialog === 'reject' && <Reject onClose={() => setDialog(null)} run={(reason) => api.expenseClaimAction(c.id, 'reject', { reason }).then((x) => finish(x, 'Claim rejected.'))} />}
      {dialog === 'pay' && a.pay && <Pay c={c} onClose={() => setDialog(null)} onDone={(x) => finish(x, 'Claim paid.')} />}
    </div>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text weight="semibold">{children}</Text></div>
}

function Reject({ onClose, run }: { onClose: () => void; run: (reason: string) => Promise<unknown> }) {
  const [reason, setReason] = useState('')
  return (
    <Ask title="Reject this claim?" go="Reject" ready={reason.trim().length >= 3} onClose={onClose} run={() => run(reason.trim())}>
      <TextField label="Reason" required maxLength={255} value={reason} onChange={setReason} />
    </Ask>
  )
}

/** Pay it back: from which account, on which day. Above the company's limit, confirm it is you, then press Pay now. */
function Pay({ c, onClose, onDone }: { c: EcDetail; onClose: () => void; onDone: (next: EcDetail) => void }) {
  const s = useStyles()
  const p = c.actions.pay!
  const [account, setAccount] = useState(p.default)
  const [on, setOn] = useState(today())
  const [confirmUrl, setConfirmUrl] = useState<string | null>(null)
  const pay = () => api.expenseClaimAction(c.id, 'pay', { account_id: account, paid_on: on }).then(onDone).catch((e: unknown) => {
    if (e instanceof ApiError && e.code === 'reconfirm' && e.url) { setConfirmUrl(e.url); return }
    throw e
  })
  if (confirmUrl) {
    return <ConfirmItsYou url={confirmUrl} why="Paying out money above the company's limit is protected." go="Pay now"
      onCancel={() => setConfirmUrl(null)} onGo={() => { setConfirmUrl(null); void pay() }} />
  }
  return (
    <Ask title={`Pay ${c.by} back`} go="Pay" ready={!!account && !!on} onClose={onClose} run={pay}>
      <Text block style={{ marginBottom: 10 }}>{money(c.amount, c.currency, c.exponent)} for {c.description}.</Text>
      <div className={s.two}>
        <Choose label="Paid from" required options={p.accounts} value={account} onChange={setAccount} />
        <TextField label="Paid on" required type="date" value={on} onChange={setOn} />
      </div>
    </Ask>
  )
}
