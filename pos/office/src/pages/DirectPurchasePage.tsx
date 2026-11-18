import {
  Badge, Button, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, AttachRegular, CheckmarkCircleRegular, DismissRegular, EditRegular, SendRegular, TagRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type DpDetail, type ProductsOptions } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { ProductAction } from './ProductActions'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  tiles: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: '10px', margin: '12px 0' },
  tile: { padding: '12px 14px', borderRadius: tokens.borderRadiusLarge, backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}` },
  tileValue: { display: 'block', fontSize: '20px', fontWeight: tokens.fontWeightBold, fontVariantNumeric: 'tabular-nums' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '10px 18px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  pages: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
})

const END = { style: { justifyContent: 'flex-end' } }
const TONE: Record<string, 'success' | 'warning' | 'subtle' | 'informative'> = { posted: 'success', submitted: 'warning', cancelled: 'subtle', draft: 'informative' }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * One direct purchase (release 5): the invoice, how it is paid, the totals against the printed total, what to check
 * before posting (a short-dated batch, a low margin…), each line with its new selling price and margin, and what posting
 * made (goods received, bill, payment). The buttons of the current page: Send for approval, Post, Cancel, Edit (or
 * correct while it waits for approval), the invoice's pages, and labels once posted.
 */
export function DirectPurchasePage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [d, setD] = useState<DpDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<'submit' | 'post' | 'cancel' | null>(null)
  const [labels, setLabels] = useState<ProductsOptions | null>(null)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.directPurchase(id).then((x) => { setD(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the purchase…" /></div>
  const m = (v: number) => money(v, d.currency, d.exponent)
  const a = d.actions
  const act = (action: 'submit' | 'post' | 'cancel', text: string) => (reason?: string) =>
    api.directPurchaseAction(d.id, action, reason).then((x) => { setD(x); setDialog(null); setDone(text) })
  const off = d.printed_total !== null && Math.abs(d.printed_total - d.total) > 10

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/direct-purchases'))}>Direct purchases</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>
          <Badge appearance="tint" color={TONE[d.status] ?? 'informative'}>{d.status_label}</Badge>
        </h1>
        <div className={s.actions}>
          {a.post && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => setDialog('post')}>Post</Button>}
          {a.submit && <Button appearance="primary" icon={<SendRegular />} onClick={() => setDialog('submit')}>Send for approval</Button>}
          {a.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/direct-purchases/${d.id}/edit`)}>{a.correcting ? 'Correct' : 'Edit'}</Button>}
          {a.labels && <Button icon={<TagRegular />} onClick={() => api.productsOptions().then(setLabels).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))}>Print labels</Button>}
          {a.cancel && <Button icon={<DismissRegular />} onClick={() => setDialog('cancel')}>Cancel</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {a.why_not && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>{a.why_not}</MessageBarBody></MessageBar>}
      {d.reason && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>Cancelled: {d.reason}</MessageBarBody></MessageBar>}
      {d.warnings.length > 0 && (
        <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>
          <Text weight="semibold" block>Check before posting</Text>
          {d.warnings.map((w) => <Text block key={w}>• {w}</Text>)}
        </MessageBarBody></MessageBar>
      )}

      <div className={s.tiles}>
        <Tile label="Total" value={m(d.total)} />
        <Tile label="Before tax" value={m(d.net)} />
        <Tile label="VAT and levies" value={m(d.tax)} />
        <Tile label="Printed total" value={d.printed_total === null ? 'Not entered' : m(d.printed_total)} tone={off ? 'var(--zx-warn)' : undefined} />
      </div>

      <Panel title="The invoice">
        <div className={s.facts}>
          <Fact label="Supplier">{d.supplier.open ? <Link onClick={() => navigate(d.supplier.open!)}>{d.supplier.name}</Link> : (d.supplier.name ?? '—')}</Fact>
          <Fact label="Invoice">{d.invoice_no}</Fact>
          <Fact label="Invoice date">{day(d.invoice_date)}</Fact>
          <Fact label="Store">{d.warehouse ?? '—'}</Fact>
          <Fact label="Payment">{d.settlement_label}{d.due_on && <Text block size={200} className={s.muted}>Due {day(d.due_on)}</Text>}</Fact>
          {d.method && <Fact label="Paid by">{d.method}{d.reference && <Text block size={200} className={s.muted}>{d.reference}</Text>}</Fact>}
          <Fact label="Prices">{d.prices_include_tax ? 'Include VAT and levies' : 'Before VAT'}</Fact>
          <Fact label="Entered by">{d.created_by ?? '—'}</Fact>
          {d.posted_by && <Fact label="Posted by">{d.posted_by}</Fact>}
          {d.goods_receipt && <Fact label="Goods received">{d.goods_receipt.open ? <Link onClick={() => navigate(d.goods_receipt!.open!)}>{d.goods_receipt.number}</Link> : d.goods_receipt.number}</Fact>}
          {d.bill && <Fact label="Bill">{d.bill}</Fact>}
          {d.payment && <Fact label="Payment no.">{d.payment}</Fact>}
        </div>
        {d.pages > 0 && (
          <div className={s.pages} style={{ marginTop: 12 }}>
            {Array.from({ length: d.pages }, (_, i) => (
              <Button key={i} size="small" icon={<AttachRegular />} onClick={() => window.open(api.dpPageUrl(d.id, i + 1), '_blank', 'noopener')}>Invoice page {i + 1}</Button>
            ))}
          </div>
        )}
        {d.note && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{d.note}</Text>}
      </Panel>

      <Panel title="Products">
        <Table size="small" aria-label="Products">
          <TableHeader><TableRow>
            <TableHeaderCell>Product</TableHeaderCell><TableHeaderCell>Qty</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Unit price</TableHeaderCell>
            <TableHeaderCell>Batch</TableHeaderCell><TableHeaderCell>Expiry</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Net</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Tax</TableHeaderCell><TableHeaderCell className={s.end} button={END}>New price</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Margin</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>{d.lines.map((l, i) => (
            <TableRow key={`${l.product}-${i}`}>
              <TableCell>{l.product}</TableCell><TableCell>{l.qty}</TableCell><TableCell className={s.end}>{m(l.unit_price)}</TableCell>
              <TableCell>{l.batch ?? '—'}</TableCell><TableCell>{day(l.expires_on)}</TableCell>
              <TableCell className={s.end}>{m(l.net)}</TableCell><TableCell className={s.end}>{m(l.tax)}</TableCell>
              <TableCell className={s.end}>{l.new_price === null ? '—' : `${m(l.new_price)} / ${l.sell_unit ?? ''}`}</TableCell>
              <TableCell className={s.end}>{l.margin === null ? '—' : `${l.margin}%`}</TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      {dialog === 'post' && a.post && (
        <Ask title="Post this purchase?" go="Post" ready onClose={() => setDialog(null)} run={() => act('post', 'Purchase posted.')()}>
          <Text>{a.post.what}</Text>
        </Ask>
      )}
      {dialog === 'submit' && (
        <Ask title="Send for approval?" go="Send" ready onClose={() => setDialog(null)} run={() => act('submit', 'Sent for approval.')()}>
          <Text>It is above the amount someone may post alone. A manager who did not prepare it posts it.</Text>
        </Ask>
      )}
      {dialog === 'cancel' && <CancelDialog onClose={() => setDialog(null)} run={act('cancel', 'Cancelled.')} />}
      {labels && <ProductAction kind="labels" pick={{ ids: d.product_ids }} count={d.product_ids.length} options={labels} onClose={() => setLabels(null)} onDone={() => setLabels(null)} />}
    </div>
  )
}

function Tile({ label, value, tone }: { label: string; value: string; tone?: string }) {
  const s = useStyles()
  return <div className={s.tile}><Text size={200} className={s.muted}>{label}</Text><span className={s.tileValue} style={tone ? { color: tone } : undefined}>{value}</span></div>
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text weight="semibold">{children}</Text></div>
}

function CancelDialog({ onClose, run }: { onClose: () => void; run: (reason: string) => Promise<unknown> }) {
  const [reason, setReason] = useState('')
  return (
    <Ask title="Cancel this purchase?" go="Cancel it" ready={reason.trim().length >= 3} onClose={onClose} run={() => run(reason.trim())}>
      <TextField label="Reason" required maxLength={255} value={reason} onChange={setReason} />
    </Ask>
  )
}
