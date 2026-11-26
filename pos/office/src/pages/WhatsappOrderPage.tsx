import { Button, Link, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, ChatRegular, DeleteRegular, DismissCircleRegular, PersonSupportRegular, ShoppingBagRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type UnitOption, type WhatsappDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField } from '../form/Fields'
import { UnitPicker } from '../form/UnitPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Dialog = 'make_order' | 'reply' | 'decline' | 'ticket'
const FULFIL = [{ id: 'pickup', label: 'Picks it up' }, { id: 'delivery', label: 'Gets it delivered' }]

/**
 * One WhatsApp message (part 33), as the current page: who it is from (and the customer record matched by phone number),
 * the message, its photos and documents. Make the order (stock is held and the customer is sent the items and the
 * total), Reply (within 24 hours of their last message), Decline (with the reason they see) and Make a ticket (not an
 * order but a problem).
 */
export function WhatsappOrderPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<WhatsappDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const [f, setF] = useState({ branch_id: '', fulfilment: 'pickup', address: '', note: '', text: '', reason: '', kind: 'support', priority: 'normal' })
  const [lines, setLines] = useState<{ unit: UnitOption | null; qty: string }[]>([])
  const load = useCallback(() => {
    api.whatsappOrder(id).then((x) => { setD(x); onTitle(`WhatsApp ${x.number}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the message…" /></div>
  const a = d.actions
  const show = (x: Dialog) => {
    setDone('')
    setF({ branch_id: d.branches.length === 1 ? d.branches[0].id : '', fulfilment: 'pickup', address: '', note: '', text: '', reason: '', kind: 'support', priority: 'normal' })
    setLines([{ unit: null, qty: '1' }])
    setDialog(x)
  }
  const run = (action: Dialog, body: Record<string, unknown>) => api.whatsappAction(d.id, action, body).then((x) => {
    if (x.open) { navigate(x.open); return }
    setDialog(null); setError(''); setDone(x.message); load()
  })
  const back = () => (history.length > 1 ? history.back() : navigate('/office/online/whatsapp'))
  const orderReady = !!f.branch_id && (f.fulfilment !== 'delivery' || f.address.trim() !== '') && lines.length > 0 && lines.every((l) => l.unit && Number(l.qty) > 0)
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>WhatsApp orders</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>{d.from}<ToneBadge tone={d.tone} label={d.status_label} /></h1>
        <div className={s.actions}>
          {a.make_order && <Button appearance="primary" icon={<ShoppingBagRegular />} onClick={() => show('make_order')}>Make the order</Button>}
          {a.reply && <Button icon={<ChatRegular />} onClick={() => show('reply')}>Reply</Button>}
          {a.decline && <Button icon={<DismissCircleRegular />} onClick={() => show('decline')}>Decline</Button>}
          {a.ticket && <Button icon={<PersonSupportRegular />} onClick={() => show('ticket')}>Make a ticket</Button>}
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="From">
        <div className={s.facts}>
          <Fact label="From">{d.from}</Fact>
          <Fact label="Received">{d.received ? new Date(d.received).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'}</Fact>
          <Fact label="Customer record">{d.customer ?? <Text className={s.muted}>Not a known customer (matched by phone number)</Text>}</Fact>
          <Fact label="Order">{d.order ? <Link onClick={() => navigate(d.order!.open)}>{d.order.number}</Link> : '—'}</Fact>
          <Fact label="Handled by">{d.handled ?? '—'}</Fact>
          {d.decline_reason && <Fact label="Declined because">{d.decline_reason}</Fact>}
        </div>
      </Panel>
      <Panel title="Message">
        <Text block style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{d.body || <span className={s.muted}>(no text)</span>}</Text>
        {d.media.length > 0 && (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 10 }}>
            {d.media.map((m, i) => !m.url ? <Text key={i} className={s.muted}>{m.name}</Text>
              : m.image ? <a key={i} href={m.url} target="_blank" rel="noopener noreferrer"><img src={m.url} alt="Photo from the customer" style={{ maxHeight: 260, maxWidth: '100%', borderRadius: 8 }} /></a>
                : <Link key={i} href={m.url} target="_blank" rel="noopener noreferrer">{m.name}</Link>)}
          </div>
        )}
      </Panel>

      {dialog === 'make_order' && (
        <Ask title="Make the order" go="Make the order" width={720} ready={orderReady} onClose={() => setDialog(null)}
          run={() => run('make_order', { branch_id: f.branch_id, fulfilment: f.fulfilment, address: f.address, note: f.note, lines: lines.map((l) => ({ product_uom_id: l.unit!.id, qty: l.qty })) })}>
          <div className={s.one}>
            <Text>Stock is held for it, and the customer is sent the items and the total on WhatsApp. Their message: {(d.body ?? '').slice(0, 300)}</Text>
            <Choose label="Branch that fills it" required options={d.branches} value={f.branch_id} onChange={(x) => setF({ ...f, branch_id: x })} />
            <Choose label="The customer" required options={FULFIL} value={f.fulfilment} onChange={(x) => setF({ ...f, fulfilment: x })} />
            {f.fulfilment === 'delivery' && <LongText label="Deliver to" maxLength={500} value={f.address} onChange={(x) => setF({ ...f, address: x })} />}
            {lines.map((l, i) => (
              <div key={i} className={s.grid} role="group" aria-label={`Item ${i + 1}`}>
                <UnitPicker value={l.unit?.id ?? ''} label={l.unit?.label ?? null} search={api.whatsappUnits}
                  onChange={(u) => setLines(lines.map((x, j) => (j === i ? { ...x, unit: u } : x)))} />
                <TextField label="Quantity" required type="number" min={0.001} step="any" value={l.qty} onChange={(q) => setLines(lines.map((x, j) => (j === i ? { ...x, qty: q } : x)))} />
                <div style={{ display: 'flex', alignItems: 'flex-end', height: '100%' }}>
                  {lines.length > 1 && <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove item ${i + 1}`} onClick={() => setLines(lines.filter((_, j) => j !== i))} />}
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => setLines([...lines, { unit: null, qty: '1' }])}>Add an item</Button></div>
            <TextField label="Note on the order (optional)" maxLength={255} value={f.note} onChange={(x) => setF({ ...f, note: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'reply' && (
        <Ask title="Reply on WhatsApp" go="Send" ready={f.text.trim() !== ''} onClose={() => setDialog(null)} run={() => run('reply', { text: f.text })}>
          <div className={s.one}>
            <Text>E.g. to ask which strength or to give a price. WhatsApp allows replies within 24 hours of their last message.</Text>
            <LongText label="Message" maxLength={1000} value={f.text} onChange={(x) => setF({ ...f, text: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'decline' && (
        <Ask title="Decline this order?" go="Decline" ready={f.reason.trim() !== ''} onClose={() => setDialog(null)} run={() => run('decline', { reason: f.reason })}>
          <div className={s.one}>
            <Text>The customer is told on WhatsApp, with your reason.</Text>
            <TextField label="Why (the customer sees this)" required maxLength={255} placeholder="e.g. out of stock, or a prescription is needed" value={f.reason} onChange={(x) => setF({ ...f, reason: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'ticket' && (
        <Ask title="Make a ticket" go="Make a ticket" ready onClose={() => setDialog(null)} run={() => run('ticket', { kind: f.kind, priority: f.priority })}>
          <div className={s.one}>
            <Text>Not an order but a problem (a complaint, a return, a question): the service desk takes it, and their next messages.</Text>
            <Choose label="What kind" required options={d.kinds} value={f.kind} onChange={(x) => setF({ ...f, kind: x })} />
            <Choose label="Priority" required options={d.priorities} value={f.priority} onChange={(x) => setF({ ...f, priority: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}
