import {
  Badge, Button, Input, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import {
  ArrowLeftRegular, CheckmarkCircleRegular, DismissCircleRegular, DocumentTextRegular, EditRegular, LockOpenRegular, PrintRegular, VehicleTruckProfileRegular,
} from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type CoDetail } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles, when } from '../list/ListParts'
import { navigate } from '../nav'
import { appPathFor } from '../paths'

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
  one: { display: 'grid', gap: '10px' },
  deliver: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 120px', gap: '8px 10px', alignItems: 'center', padding: '8px 0',
    borderBottom: `1px solid ${tokens.colorNeutralStroke2}` },
})

const END = { style: { justifyContent: 'flex-end' } }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
type Dialog = 'confirm' | 'release' | 'deliver' | 'cancel' | null

/**
 * One quote or order (part 15): the customer, the store it is supplied from, the lines (ordered and delivered), who
 * entered it and released its credit hold; and every button of the current page — Turn into order (credit customers are
 * checked against their limit and overdue invoices), Release credit hold (never one's own order), Deliver (takes the
 * goods out of stock and raises the invoice for what is delivered now), Print, Waybill, Invoices, Mark lost / Cancel
 * order, and Edit while it is a quotation.
 */
export function CustomerOrderPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [o, setO] = useState<CoDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<Dialog>(null)
  const [reason, setReason] = useState('')
  const [qtys, setQtys] = useState<Record<string, string>>({})
  const [to, setTo] = useState({ delivered_to: '', vehicle: '' })
  const [done, setDone] = useState<{ text: string; intent: 'success' | 'warning' } | null>(null)
  const load = useCallback(() => {
    api.customerOrder(id).then((x) => { setO(x); onTitle(x.order ?? x.quote) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !o) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!o) return <div className={page.page}><Spinner label="Opening…" /></div>
  const m = (v: number) => money(v, o.currency, o.exponent)
  const a = o.actions
  const open = (d: Dialog) => {
    setDone(null)
    setReason('')
    if (d === 'deliver' && a.deliver) setQtys(Object.fromEntries(a.deliver.lines.map((l) => [l.id, l.qty])))
    setTo({ delivered_to: '', vehicle: '' })
    setDialog(d)
  }
  const run = (action: 'confirm' | 'release' | 'deliver' | 'cancel', body: Record<string, unknown> = {}) => api.customerOrderAction(o.id, action, body).then((r) => {
    setO(r.order)
    onTitle(r.order.order ?? r.order.quote)
    setDialog(null)
    setDone({ text: r.message, intent: r.held ? 'warning' : 'success' })
  })

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/customer-orders'))}>Quotes & orders</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{o.label}</span>
          <Badge appearance="tint" color={o.tone}>{o.status_label}</Badge>
        </h1>
        <div className={s.actions}>
          {a.confirm && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => open('confirm')}>Turn into order</Button>}
          {a.release && <Button appearance="primary" icon={<LockOpenRegular />} onClick={() => open('release')}>Release credit hold</Button>}
          {a.deliver && <Button appearance="primary" icon={<VehicleTruckProfileRegular />} onClick={() => open('deliver')}>Deliver</Button>}
          <Button icon={<PrintRegular />} onClick={() => window.open(a.print, '_blank', 'noopener')}>Print</Button>
          {a.waybill && <Button icon={<VehicleTruckProfileRegular />} onClick={() => navigate(appPathFor(a.waybill!))}>Waybill</Button>}
          {a.invoices && <Button icon={<DocumentTextRegular />} onClick={() => navigate(appPathFor(a.invoices!))}>Invoices</Button>}
          {a.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/customer-orders/${o.id}/edit`)}>Edit</Button>}
          {a.cancel && <Button icon={<DismissCircleRegular />} onClick={() => open('cancel')}>{a.cancel}</Button>}
        </div>
      </div>
      {done && <MessageBar intent={done.intent} style={{ marginBottom: 12 }}><MessageBarBody>{done.text}</MessageBarBody></MessageBar>}
      {o.hold_reason && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>On credit hold because: {o.hold_reason}</MessageBarBody></MessageBar>}

      <div className={s.tiles}>
        <Tile label="Total" value={m(o.total)} />
        <Tile label="Before tax" value={m(o.net)} />
        <Tile label="Tax" value={m(o.tax)} />
        <Tile label="Quote valid until" value={day(o.valid_until)} />
      </div>

      <Panel title="Details">
        <div className={s.facts}>
          <Fact label="Customer">{o.customer.open ? <Link onClick={() => navigate(o.customer.open!)}>{o.customer.name}</Link> : o.customer.name ?? '—'}</Fact>
          <Fact label="Supplied from">{o.warehouse ?? '—'}</Fact>
          <Fact label="Customer's reference">{o.customer_ref ?? '—'}</Fact>
          <Fact label="Entered by">{o.created_by ?? '—'}{o.created_at && <Text block size={200} className={s.muted}>{when(o.created_at)}</Text>}</Fact>
          {o.released_by && <Fact label="Credit hold released by">{o.released_by}</Fact>}
          {o.close_reason && <Fact label="Closed because">{o.close_reason}</Fact>}
        </div>
        {o.note && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{o.note}</Text>}
      </Panel>

      <Panel title="Lines">
        <Table aria-label="Lines" size="small" style={{ minWidth: 640 }}>
          <TableHeader><TableRow>
            <TableHeaderCell>Item</TableHeaderCell><TableHeaderCell button={END}>Ordered</TableHeaderCell><TableHeaderCell button={END}>Delivered</TableHeaderCell>
            <TableHeaderCell button={END}>Price</TableHeaderCell><TableHeaderCell button={END}>Line total</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>
            {o.lines.map((l, i) => (
              <TableRow key={i}>
                <TableCell>{l.name}<Text block size={200} className={s.muted}>{l.sku}</Text></TableCell>
                <TableCell className={s.end}>{l.ordered}</TableCell><TableCell className={s.end}>{l.delivered}</TableCell>
                <TableCell className={s.end}>{m(l.price)}</TableCell><TableCell className={s.end}>{m(l.total)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      {dialog === 'confirm' && (
        <Ask title="Turn into order?" go="Turn into order" ready onClose={() => setDialog(null)} run={() => run('confirm')}>
          <Text>The customer accepted. Credit customers are checked against their limit and overdue invoices; an order over the limit waits on a credit hold.</Text>
        </Ask>
      )}
      {(dialog === 'release' || dialog === 'cancel') && (
        <Ask title={dialog === 'release' ? 'Release the credit hold' : `${a.cancel}?`} go={dialog === 'release' ? 'Release' : a.cancel ?? 'Cancel'} ready={reason.trim() !== ''}
          onClose={() => setDialog(null)} run={() => run(dialog, { reason })}>
          <div className={s.one}>
            {dialog === 'release' && o.hold_reason && <Text>{o.hold_reason}</Text>}
            <TextField label={dialog === 'release' ? 'Why it is safe to go ahead' : 'Reason'} required maxLength={255} value={reason} onChange={setReason}
              placeholder={dialog === 'release' ? 'e.g. payment promised Friday' : undefined} />
          </div>
        </Ask>
      )}
      {dialog === 'deliver' && a.deliver && (
        <Ask title="Deliver" go="Deliver" width={640} ready={Object.values(qtys).some((q) => Number(q) > 0)} onClose={() => setDialog(null)}
          run={() => run('deliver', { qtys, ...to })}>
          <Text block style={{ marginBottom: 8 }}>Takes the goods out of stock and raises the invoice for what is delivered now. Put 0 on a line not delivered now.</Text>
          {a.deliver.lines.map((l) => (
            <div key={l.id} className={s.deliver}>
              <span>{l.item}<Text block size={200} className={s.muted}>Ordered {l.ordered} · delivered so far {l.delivered}</Text></span>
              <Input type="number" min={0} step="any" aria-label={`Deliver now: ${l.item}`} value={qtys[l.id] ?? ''} onChange={(_, d) => setQtys({ ...qtys, [l.id]: d.value })} />
            </div>
          ))}
          <div className={s.one} style={{ marginTop: 10 }}>
            <TextField label="Received by" maxLength={255} value={to.delivered_to} onChange={(x) => setTo({ ...to, delivered_to: x })} />
            <TextField label="Vehicle" maxLength={40} value={to.vehicle} onChange={(x) => setTo({ ...to, vehicle: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}

function Tile({ label, value }: { label: string; value: string }) {
  const s = useStyles()
  return <div className={s.tile}><Text size={200} className={s.muted}>{label}</Text><span className={s.tileValue}>{value}</span></div>
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text>{children}</Text></div>
}
