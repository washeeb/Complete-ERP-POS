import {
  Badge, Button, Field, Input, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkCircleRegular, DismissRegular, PrintRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type OfficeReturnDoc } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { RETURN_TONE } from './OfficeReturnsPage'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '10px 18px' },
})

/**
 * One back-office return (part 16): the receipt, the refund and how it is paid, the reason, where the goods go back,
 * who entered and approved it, and the goods (good or damaged). Approve and refund (never one's own; it cannot be
 * undone), Cancel with a reason, and the return slip once posted.
 */
export function OfficeReturnPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [d, setD] = useState<OfficeReturnDoc | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<'approve' | 'cancel' | null>(null)
  const [reason, setReason] = useState('')
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.officeReturn(id).then((x) => { setD(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the return…" /></div>
  const m = (v: number) => money(v, d.currency, d.exponent)
  const step = (st: 'approve' | 'cancel') => api.officeReturnStep(d.id, st, reason).then((r) => { setD(r.doc); setDialog(null); setDone(r.message) })

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/office-returns'))}>Back-office returns</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span><Badge appearance="tint" color={RETURN_TONE[d.status] ?? 'informative'}>{d.status_label}</Badge></h1>
        <div className={s.actions}>
          {d.actions.approve && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => { setDone(''); setDialog('approve') }}>Approve and refund</Button>}
          {d.actions.cancel && <Button icon={<DismissRegular />} onClick={() => { setDone(''); setReason(''); setDialog('cancel') }}>Cancel</Button>}
          {d.actions.slip && <Button icon={<PrintRegular />} onClick={() => window.open(d.actions.slip!, '_blank', 'noopener')}>Return slip</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {d.actions.why_not && d.status === 'submitted' && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>{d.actions.why_not}</MessageBarBody></MessageBar>}

      <section className={s.panel} aria-label="Details">
        <h2 className={s.h2}>Details</h2>
        <div className={s.facts}>
          <Fact label="Receipt">{d.original ? <Link onClick={() => navigate(d.original!)}>{d.receipt}</Link> : d.receipt ?? '—'}</Fact>
          <Fact label="Refund"><Text weight="semibold">{m(d.refund)}</Text></Fact>
          <Fact label="Refund as">{d.refund_as}</Fact>
          <Fact label="Paid from">{d.paid_from ?? '—'}</Fact>
          <Fact label="Reason">{d.reason}</Fact>
          <Fact label="Details">{d.details ?? '—'}</Fact>
          <Fact label="Reference">{d.reference ?? '—'}</Fact>
          <Fact label="Goes back to">{d.goes_to ?? '—'}</Fact>
          <Fact label="Entered by">{d.entered_by ?? '—'}</Fact>
          <Fact label="Approved by">{d.approved_by ?? '—'}</Fact>
          <Fact label="Return no.">{d.return_no ?? '—'}</Fact>
          {d.cancel_reason && <Fact label="Cancelled because">{d.cancel_reason}</Fact>}
        </div>
      </section>
      <section className={s.panel} aria-label="Goods">
        <h2 className={s.h2}>Goods</h2>
        <Table aria-label="Goods" size="small" style={{ minWidth: 480 }}>
          <TableHeader><TableRow><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell>Quantity</TableHeaderCell><TableHeaderCell>Condition</TableHeaderCell></TableRow></TableHeader>
          <TableBody>{d.lines.map((l, i) => <TableRow key={i}><TableCell>{l.item}</TableCell><TableCell>{l.qty}</TableCell><TableCell>{l.condition}</TableCell></TableRow>)}</TableBody>
        </Table>
      </section>

      {dialog === 'approve' && (
        <Ask title="Approve and refund?" go="Approve and refund" ready onClose={() => setDialog(null)} run={() => step('approve')}>
          <Text>Books the return and refunds {m(d.refund)} as {d.refund_as.toLowerCase()}. This cannot be undone.</Text>
        </Ask>
      )}
      {dialog === 'cancel' && (
        <Ask title="Cancel the return?" go="Cancel the return" ready={reason.trim() !== ''} onClose={() => setDialog(null)} run={() => step('cancel')}>
          <Field label="Reason" required><Input maxLength={255} value={reason} onChange={(_, x) => setReason(x.value)} /></Field>
        </Ask>
      )}
    </div>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text>{children}</Text></div>
}
