import {
  Badge, Button, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkRegular, DismissRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type SbDetail } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { SB_TONE } from './SupplierBillsPage'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '10px 18px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
})

const END = { style: { justifyContent: 'flex-end' } }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * One supplier bill (release 6): the supplier's invoice, its lines (with the order price where the bill differs), and
 * the totals; a bill whose prices differ from the order waits for someone other than whoever entered it to Approve (with
 * why the difference is accepted) or Reject it.
 */
export function SupplierBillPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [b, setB] = useState<SbDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<'approve' | 'reject' | null>(null)
  const [reason, setReason] = useState('')
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.supplierBill(id).then((x) => { setB(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !b) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!b) return <div className={page.page}><Spinner label="Opening the bill…" /></div>
  const m = (v: number) => money(v, b.currency, b.exponent)

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/supplier-bills'))}>Supplier bills</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{b.number}</span>
          <Badge appearance="tint" color={SB_TONE[b.status] ?? 'informative'}>{b.status_label}</Badge>
        </h1>
        <div className={s.actions}>
          {b.actions.approve && <Button appearance="primary" icon={<CheckmarkRegular />} onClick={() => { setReason(''); setDialog('approve') }}>Approve</Button>}
          {b.actions.reject && <Button icon={<DismissRegular />} onClick={() => { setReason(''); setDialog('reject') }}>Reject</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {b.variance !== 0 && b.status === 'pending_approval' && (
        <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>The prices differ from the order by {m(b.variance)}: someone else approves it before it is posted.</MessageBarBody></MessageBar>
      )}

      <section className={s.panel} aria-label="The bill">
        <div className={s.facts}>
          <Fact label="Supplier">{b.supplier.open ? <Link onClick={() => navigate(b.supplier.open!)}>{b.supplier.name}</Link> : (b.supplier.name ?? '—')}</Fact>
          <Fact label="Their invoice">{b.invoice_no}</Fact>
          <Fact label="Date">{day(b.bill_date)}</Fact>
          <Fact label="Due">{day(b.due_on)}</Fact>
          <Fact label="Before tax">{m(b.net)}</Fact>
          <Fact label="Tax">{m(b.tax)}</Fact>
          <Fact label="Total">{m(b.total)}</Fact>
          <Fact label="Difference from order prices"><span style={b.variance ? { color: 'var(--zx-warn)' } : undefined}>{m(b.variance)}</span></Fact>
          <Fact label="Entered by">{b.created_by ?? '—'}</Fact>
          <Fact label="Approved / rejected by">{b.decided_by ?? '—'}</Fact>
        </div>
        {b.variance_reason && <Text block style={{ marginTop: 10 }}><Text weight="semibold">Reason: </Text>{b.variance_reason}</Text>}
        {b.note && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{b.note}</Text>}
      </section>

      <section className={s.panel} aria-label="Lines">
        <h2 className={s.h2}>Lines</h2>
        <Table size="small" aria-label="Lines">
          <TableHeader><TableRow>
            <TableHeaderCell>What</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Quantity</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Price</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Tax</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Total</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>{b.lines.map((l, i) => (
            <TableRow key={i}>
              <TableCell>{l.description}</TableCell><TableCell className={s.end}>{l.qty}</TableCell>
              <TableCell className={s.end}>{m(l.price)}{l.order_price !== null && <Text block size={200} className={s.muted}>order {m(l.order_price)}</Text>}</TableCell>
              <TableCell className={s.end}>{m(l.tax)}</TableCell><TableCell className={s.end}>{m(l.total)}</TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </section>

      {dialog && (
        <Ask title={dialog === 'approve' ? `Approve ${b.number}?` : `Reject ${b.number}?`} go={dialog === 'approve' ? 'Approve and post' : 'Reject'}
          ready={dialog === 'approve' || reason.trim().length >= 3} onClose={() => setDialog(null)}
          run={() => api.supplierBillAction(b.id, dialog, reason.trim() || undefined).then((x) => {
            setB(x); setDialog(null); setDone(dialog === 'approve' ? 'Bill approved and posted.' : 'Bill rejected.')
          })}>
          <TextField label={dialog === 'approve' ? 'Why the difference is accepted' : 'Reason'} required={dialog === 'reject'} maxLength={255} value={reason} onChange={setReason} />
        </Ask>
      )}
    </div>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text weight="semibold">{children}</Text></div>
}
