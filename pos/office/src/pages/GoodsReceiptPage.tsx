import {
  Badge, Button, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, ArrowUndoRegular, CheckmarkRegular, PrintRegular, ProhibitedRegular, TagRegular, VehicleTruckProfileRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type GrnDetail, type ProductsOptions } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles, when } from '../list/ListParts'
import { navigate } from '../nav'
import { appPathFor } from '../paths'
import { ProductAction } from './ProductActions'

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
const TONE: Record<string, 'success' | 'warning' | 'subtle'> = { posted: 'success', held: 'warning', reversed: 'subtle' }
type Dialogs = 'accept' | 'refuse' | 'reverse'

/**
 * One goods received note (release 5): the order and supplier, who received it and when, what came on each line (cost,
 * bin, serial numbers); and the current page's buttons — Print, Accept or Refuse a delivery above the order (held for a
 * manager), Reverse a posted one, labels for what came in, and the supplier waybill.
 */
export function GoodsReceiptPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [g, setG] = useState<GrnDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<Dialogs | null>(null)
  const [labels, setLabels] = useState<ProductsOptions | null>(null)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.goodsReceipt(id).then((x) => { setG(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !g) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!g) return <div className={page.page}><Spinner label="Opening the goods received note…" /></div>
  const m = (v: number) => money(v, g.currency, g.exponent)
  const a = g.actions
  const act = (action: Dialogs, text: string) => (reason?: string) => api.goodsReceiptAction(g.id, action, reason).then((x) => { setG(x); setDialog(null); setDone(text) })

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/goods-received'))}>Goods received</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{g.number}</span>
          <Badge appearance="tint" color={TONE[g.status] ?? 'informative'}>{g.status_label}</Badge>
        </h1>
        <div className={s.actions}>
          {a.accept && <Button appearance="primary" icon={<CheckmarkRegular />} onClick={() => setDialog('accept')}>Accept delivery</Button>}
          {a.refuse && <Button icon={<ProhibitedRegular />} onClick={() => setDialog('refuse')}>Refuse delivery</Button>}
          {a.reverse && <Button icon={<ArrowUndoRegular />} onClick={() => setDialog('reverse')}>Reverse</Button>}
          <Button icon={<PrintRegular />} onClick={() => window.open(a.print, '_blank', 'noopener')}>Print</Button>
          {a.labels && <Button icon={<TagRegular />} onClick={() => api.productsOptions().then(setLabels).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))}>Print labels</Button>}
          {a.waybill && <Button icon={<VehicleTruckProfileRegular />} onClick={() => navigate(appPathFor(a.waybill!.open))}>{a.waybill.label}</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {g.status === 'held' && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>
        More than the over-delivery tolerance was received: nothing is in stock until a manager accepts it, or refuses it and the right quantity is received again.
      </MessageBarBody></MessageBar>}
      {g.reversal && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>Reversed or refused because: {g.reversal.reason}{g.reversal.by ? ` (${g.reversal.by})` : ''}.</MessageBarBody></MessageBar>}

      <Panel title="Details">
        <div className={s.facts}>
          <Fact label="Purchase order">{g.order ? (g.order.open ? <Link onClick={() => navigate(g.order!.open!)}>{g.order.number}</Link> : g.order.number) : 'none'}</Fact>
          <Fact label="Supplier">{g.supplier.open ? <Link onClick={() => navigate(g.supplier.open!)}>{g.supplier.name}</Link> : (g.supplier.name ?? '—')}</Fact>
          <Fact label="Warehouse">{g.warehouse ?? '—'}</Fact>
          <Fact label="Supplier ref.">{g.ref ?? '—'}</Fact>
          <Fact label="Received by">{g.received_by ?? '—'}{g.received_at && <Text block size={200} className={s.muted}>{when(g.received_at)}</Text>}</Fact>
          <Fact label="Over-delivery accepted by">{g.approved_by ?? '—'}</Fact>
          <Fact label="Value before tax">{m(g.value)}</Fact>
        </div>
        {g.note && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{g.note}</Text>}
      </Panel>

      <Panel title="Lines">
        <Table size="small" aria-label="Lines">
          <TableHeader><TableRow>
            <TableHeaderCell>Item</TableHeaderCell><TableHeaderCell>Quantity</TableHeaderCell><TableHeaderCell>Bin</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Cost</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Value</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>{g.lines.map((l, i) => (
            <TableRow key={`${l.sku}-${i}`}>
              <TableCell><Text font="monospace" size={200} className={s.muted}>{l.sku}</Text> {l.name}
                {l.serials.length > 0 && <Text block size={200} className={s.muted}>Serials: {l.serials.join(', ')}</Text>}</TableCell>
              <TableCell>{l.qty}</TableCell><TableCell>{l.bin ?? '—'}</TableCell>
              <TableCell className={s.end}>{m(l.cost)}</TableCell><TableCell className={s.end}>{m(l.value)}</TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      {dialog === 'accept' && (
        <Ask title="Accept delivery" go="Accept" ready onClose={() => setDialog(null)} run={() => act('accept', 'Accepted and posted: the goods are in stock.')()}>
          <Text>The full delivery, including the quantity above the order, goes into stock.</Text>
        </Ask>
      )}
      {dialog === 'refuse' && <Reason title="Refuse delivery" go="Refuse" about="Nothing goes into stock. Receive the correct quantity again on the purchase order."
        run={act('refuse', 'Refused.')} onClose={() => setDialog(null)} />}
      {dialog === 'reverse' && <Reason title={`Reverse ${g.number}`} go="Reverse" about="Takes these goods back out of stock and reopens the order quantities. The note itself is kept, marked reversed."
        run={act('reverse', 'Reversed.')} onClose={() => setDialog(null)} />}
      {labels && <ProductAction kind="labels" pick={{ ids: g.product_ids }} count={g.product_ids.length} options={labels} onClose={() => setLabels(null)} onDone={() => setLabels(null)} />}
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text weight="semibold">{children}</Text></div>
}

function Reason({ title, go, about, run, onClose }: { title: string; go: string; about: string; run: (reason: string) => Promise<unknown>; onClose: () => void }) {
  const [reason, setReason] = useState('')
  return (
    <Ask title={title} go={go} ready={reason.trim().length >= 3} onClose={onClose} run={() => run(reason.trim())}>
      <Text block style={{ marginBottom: 10 }}>{about}</Text>
      <TextField label="Reason" required maxLength={255} value={reason} onChange={setReason} />
    </Ask>
  )
}
