import { Button, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { ArchiveRegular, ArrowLeftRegular, CheckmarkCircleRegular, DismissCircleRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type OnlineOrderDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * One online order (part 33), as the current page: channel, placed, customer, pickup or delivery and branch, payment,
 * total and whether the stock is reserved, and its items. Mark ready, Hand over (issues the stock and closes the order;
 * asks how it was paid when not paid online) and Cancel order (releases the reserved stock).
 */
export function OnlineOrderPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<OnlineOrderDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [ask, setAsk] = useState<'ready' | 'fulfil' | 'cancel' | null>(null)
  const [f, setF] = useState({ method: '', reference: '', reason: '' })
  const load = useCallback(() => {
    api.onlineOrder(id).then((x) => { setD(x); onTitle(`Online order ${x.number}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the order…" /></div>
  const a = d.actions
  const open = (x: 'ready' | 'fulfil' | 'cancel') => { setDone(''); setF({ method: '', reference: '', reason: '' }); setAsk(x) }
  const run = (action: 'ready' | 'fulfil' | 'cancel', body: Record<string, unknown> = {}) =>
    api.onlineOrderAction(d.id, action, body).then((x) => { setAsk(null); setError(''); setDone(x.message); load() })
  const back = () => (history.length > 1 ? history.back() : navigate('/office/online/orders'))
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Online orders</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span><ToneBadge tone={d.tone} label={d.status_label} /></h1>
        <div className={s.actions}>
          {a.ready && <Button icon={<ArchiveRegular />} onClick={() => open('ready')}>Mark ready</Button>}
          {a.fulfil && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => open('fulfil')}>Hand over</Button>}
          {a.cancel && <Button icon={<DismissCircleRegular />} onClick={() => open('cancel')}>Cancel order</Button>}
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {d.short && d.status !== 'cancelled' && d.status !== 'fulfilled' && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>Not all the stock could be reserved — see the review queue.</MessageBarBody></MessageBar>}
      <Panel title="Order">
        <div className={s.facts}>
          <Fact label="Channel">{d.channel ?? '—'}</Fact>
          <Fact label="Placed">{d.placed ? new Date(d.placed).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'}</Fact>
          <Fact label="Customer">{d.customer ?? '—'}</Fact>
          <Fact label="Phone">{d.phone ?? '—'}</Fact>
          <Fact label="Email">{d.email ?? '—'}</Fact>
          <Fact label="Fulfilment">{d.fulfilment}</Fact>
          <Fact label="Payment"><ToneBadge tone={d.paid ? 'success' : 'warning'} label={d.payment} />{d.payment_reference && <Text size={200} block className={s.muted}>{d.payment_reference}</Text>}</Fact>
          <Fact label="Total"><b>{d.total}</b></Fact>
          <Fact label="Stock">{d.short ? 'Not all reserved' : 'Reserved'}</Fact>
        </div>
        {d.note && <Text block style={{ marginTop: 10 }}><b>Note:</b> {d.note}</Text>}
        {d.cancel_reason && <Text block style={{ marginTop: 6 }}><b>Cancelled because:</b> {d.cancel_reason}</Text>}
      </Panel>
      <Panel title="Items">
        <Table size="small" aria-label="Items" style={{ minWidth: 520 }}>
          <TableHeader><TableRow><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell button={END}>Quantity</TableHeaderCell><TableHeaderCell button={END}>Unit price</TableHeaderCell>
            <TableHeaderCell button={END}>Line total</TableHeaderCell></TableRow></TableHeader>
          <TableBody>{d.lines.map((l) => (
            <TableRow key={l.id}><TableCell>{l.item}</TableCell><TableCell className={s.end}>{l.qty}</TableCell><TableCell className={s.end}>{l.price}</TableCell><TableCell className={s.end}>{l.total}</TableCell></TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      {ask === 'ready' && (
        <Ask title="Mark ready for pickup?" go="Mark ready" ready onClose={() => setAsk(null)} run={() => run('ready')}>
          <Text>The order is packed and waiting for the customer.</Text>
        </Ask>
      )}
      {ask === 'fulfil' && (
        <Ask title="Hand over" go="Hand over" ready={d.paid || !!f.method} onClose={() => setAsk(null)}
          run={() => run('fulfil', d.paid ? {} : { payment_method: f.method, payment_reference: f.reference })}>
          <div className={s.one}>
            <Text>Issues the stock and closes the order. Total {d.total}.</Text>
            {!d.paid && <Choose label="Paid with" required options={d.methods} value={f.method} onChange={(x) => setF({ ...f, method: x })} />}
            {!d.paid && ['momo', 'card'].includes(f.method) && <TextField label="Reference (MoMo/card)" maxLength={80} value={f.reference} onChange={(x) => setF({ ...f, reference: x })} />}
          </div>
        </Ask>
      )}
      {ask === 'cancel' && (
        <Ask title="Cancel this order?" go="Cancel order" ready={f.reason.trim() !== ''} onClose={() => setAsk(null)} run={() => run('cancel', { reason: f.reason })}>
          <div className={s.one}>
            <Text>Releases the reserved stock. The customer is not told automatically yet.</Text>
            <TextField label="Reason" required maxLength={255} value={f.reason} onChange={(x) => setF({ ...f, reason: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}
