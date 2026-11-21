import {
  Badge, Button, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkCircleRegular, DismissRegular, PrintRegular, ReceiptRegular, VehicleTruckProfileRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type SupplierReturnDoc } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles, when } from '../list/ListParts'
import { navigate } from '../nav'
import { day, SR_TONE } from './SupplierReturnsPage'

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
  one: { display: 'grid', gap: '10px' },
})

const END = { style: { justifyContent: 'flex-end' } }

/**
 * One return to a supplier (part 17): the supplier, the store, why, what the supplier gives, the value at cost, who
 * prepared and approved it, and the lines with batches and expiry. Approve and send (never one's own: the stock leaves
 * now), Record the supplier's settlement (with the refund's amount and account), Cancel, Waybill and the Return note.
 */
export function SupplierReturnPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [d, setD] = useState<SupplierReturnDoc | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<'post' | 'settle' | 'cancel' | null>(null)
  const [v, setV] = useState({ reason: '', reference: '', refund: '', account_id: '' })
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.supplierReturn(id).then((x) => { setD(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the return…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  const a = d.actions
  const open = (x: 'post' | 'settle' | 'cancel') => {
    setDone('')
    setV({ reason: '', reference: '', refund: a.settle?.refund != null ? (a.settle.refund / 10 ** d.exponent).toFixed(d.exponent) : '', account_id: a.settle?.accounts?.[0]?.id ?? '' })
    setDialog(x)
  }
  const run = (step: 'post' | 'settle' | 'cancel', body: Record<string, unknown> = {}) => api.supplierReturnStep(d.id, step, body).then((r) => { setD(r.doc); setDialog(null); setDone(r.message) })
  const refund = !!a.settle?.accounts

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/supplier-returns'))}>Returns to suppliers</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span><Badge appearance="tint" color={SR_TONE[d.status] ?? 'informative'}>{d.status_label}</Badge></h1>
        <div className={s.actions}>
          {a.post && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => open('post')}>Approve and send</Button>}
          {a.settle && <Button appearance="primary" icon={<ReceiptRegular />} onClick={() => open('settle')}>Record the supplier's settlement</Button>}
          {a.waybill && <Button icon={<VehicleTruckProfileRegular />} onClick={() => navigate(a.waybill!)}>Waybill</Button>}
          <Button icon={<PrintRegular />} onClick={() => window.open(a.print, '_blank', 'noopener')}>Return note</Button>
          {a.cancel && <Button icon={<DismissRegular />} onClick={() => open('cancel')}>Cancel</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      <section className={s.panel} aria-label="Details">
        <h2 className={s.h2}>Details</h2>
        <div className={s.facts}>
          <Fact label="Supplier">{d.supplier_open ? <Link onClick={() => navigate(d.supplier_open!)}>{d.supplier}</Link> : d.supplier ?? '—'}</Fact>
          <Fact label="From store">{d.store ?? '—'}</Fact>
          <Fact label="Reason">{d.reason}</Fact>
          <Fact label="The supplier gives">{d.settlement_label}</Fact>
          <Fact label="Value at cost"><Text weight="semibold">{m(d.value)}</Text></Fact>
          <Fact label="Prepared by">{d.prepared_by ?? '—'}{d.prepared_at && <Text block size={200} className={s.muted}>{when(d.prepared_at)}</Text>}</Fact>
          <Fact label="Approved and sent by">{d.approved_by ?? '—'}{d.approved_at && <Text block size={200} className={s.muted}>{when(d.approved_at)}</Text>}</Fact>
          <Fact label="Supplier's reference">{d.settlement_ref ?? 'not settled yet'}{d.settled && <Text block size={200} className={s.muted}>{d.settled}</Text>}</Fact>
          {d.refund !== null && <Fact label="Refund received">{m(d.refund)}</Fact>}
        </div>
        {d.note && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{d.note}</Text>}
      </section>
      <section className={s.panel} aria-label="Lines">
        <h2 className={s.h2}>Lines</h2>
        <Table aria-label="Lines" size="small" style={{ minWidth: 640 }}>
          <TableHeader><TableRow>
            <TableHeaderCell>#</TableHeaderCell><TableHeaderCell>Product</TableHeaderCell><TableHeaderCell>Batch</TableHeaderCell><TableHeaderCell>Expires</TableHeaderCell>
            <TableHeaderCell button={END}>Quantity</TableHeaderCell><TableHeaderCell button={END}>Value (cost)</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>{d.lines.map((l) => (
            <TableRow key={l.no}><TableCell>{l.no}</TableCell><TableCell>{l.name}<Text block size={200} className={s.muted}>{l.sku}</Text></TableCell>
              <TableCell>{l.batch ?? '—'}</TableCell><TableCell>{day(l.expires_on)}</TableCell><TableCell className={s.end}>{l.qty}</TableCell><TableCell className={s.end}>{m(l.value)}</TableCell></TableRow>
          ))}</TableBody>
        </Table>
      </section>

      {dialog === 'post' && (
        <Ask title="Approve and send?" go="Approve and send" ready onClose={() => setDialog(null)} run={() => run('post')}>
          <Text>The stock leaves the store now, at average cost. With a credit note or replacement, what we owe the supplier goes down now; with no credit, the value is written off as a stock loss. You cannot approve a return you prepared.</Text>
        </Ask>
      )}
      {dialog === 'settle' && a.settle && (
        <Ask title="Record the supplier's settlement" go="Record" ready={v.reference.trim() !== '' && (!refund || (v.refund !== '' && !!v.account_id))} onClose={() => setDialog(null)}
          run={() => run('settle', { reference: v.reference, ...(refund ? { refund: v.refund, account_id: v.account_id } : {}) })}>
          <div className={s.one}>
            {a.settle.replacement && <Text>Receive the replacement stock as a normal delivery (goods received note) at no cost, or with the supplier's bill, then record its reference here.</Text>}
            <TextField label={a.settle.label} required maxLength={120} value={v.reference} onChange={(x) => setV({ ...v, reference: x })} />
            {refund && <TextField label="Amount refunded" required type="number" min={0} step="any" prefix={d.currency} value={v.refund} onChange={(x) => setV({ ...v, refund: x })}
              hint="Less than the value at cost: the difference is booked as a stock loss." />}
            {refund && <Choose label="Received into" required options={a.settle.accounts ?? []} value={v.account_id} onChange={(x) => setV({ ...v, account_id: x })} />}
          </div>
        </Ask>
      )}
      {dialog === 'cancel' && (
        <Ask title="Cancel the return?" go="Cancel the return" ready={v.reason.trim() !== ''} onClose={() => setDialog(null)} run={() => run('cancel', { reason: v.reason })}>
          <TextField label="Reason" required maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
        </Ask>
      )}
    </div>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text>{children}</Text></div>
}
