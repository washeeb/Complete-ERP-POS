import { Button, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, CartRegular, DismissRegular, ProhibitedRegular, ScalesRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type PrDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, ChooseMany, LongText, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles, when } from '../list/ListParts'
import { navigate } from '../nav'
import { day, END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Dialog = 'order' | 'quotes' | 'reject' | 'cancel' | null

/**
 * One purchase request (part 18): the store, when it is needed, who asked, the budget check (this year's budget for the
 * branch, what is spent and ordered, this request at its estimated prices), and the lines. Order (a buyer chooses the
 * supplier: a draft purchase order), Ask for quotes (a request for quotation to several suppliers), Reject (a buyer;
 * the person who asked sees why), Cancel (the person who asked).
 */
export function PurchaseRequestPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [r, setR] = useState<PrDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<Dialog>(null)
  const [v, setV] = useState({ supplier_id: '', supplier_ids: [] as string[], closes_at: '', terms: '', reason: '' })
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.purchaseRequest(id).then((x) => { setR(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !r) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!r) return <div className={page.page}><Spinner label="Opening the request…" /></div>
  const m = (x: number) => money(x, r.currency, r.exponent)
  const a = r.actions
  const open = (d: Dialog) => { setDone(''); setV({ supplier_id: '', supplier_ids: [], closes_at: a.quotes?.closes_at ?? '', terms: '', reason: '' }); setDialog(d) }
  const run = (action: 'order' | 'quotes' | 'reject' | 'cancel', body: Record<string, unknown>) => api.purchaseRequestAction(r.id, action, body).then((x) => {
    if (x.open) { navigate(x.open); return }
    if (x.request) setR(x.request)
    setDialog(null)
    setDone(x.message)
  })

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/purchase-requests'))}>Purchase requests</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{r.number}</span><ToneBadge tone={r.tone} label={r.status_label} /></h1>
        <div className={s.actions}>
          {a.order && <Button appearance="primary" icon={<CartRegular />} onClick={() => open('order')}>Order</Button>}
          {a.quotes && <Button icon={<ScalesRegular />} onClick={() => open('quotes')}>Ask for quotes</Button>}
          {a.reject && <Button icon={<DismissRegular />} onClick={() => open('reject')}>Reject</Button>}
          {a.cancel && <Button icon={<ProhibitedRegular />} onClick={() => open('cancel')}>Cancel</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      <Panel title="Details">
        <div className={s.facts}>
          <Fact label="For the store">{r.store ?? '—'}</Fact>
          <Fact label="Needed by">{day(r.needed_by)}</Fact>
          <Fact label="Asked by">{r.by ?? '—'}<Text block size={200} className={s.muted}>{when(r.asked_at)}</Text></Fact>
          <Fact label="Purchase order">{r.order ? <Link onClick={() => navigate(r.order!.open)}>{r.order.number}</Link> : '—'}</Fact>
          {r.rfq && <Fact label="Request for quotation"><Link onClick={() => navigate(r.rfq!.open)}>{r.rfq.number}</Link></Fact>}
          {r.closed_reason && <Fact label="Reason">{r.closed_reason}</Fact>}
        </div>
        {r.note && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{r.note}</Text>}
      </Panel>

      {r.budget.length > 0 && (
        <Panel title="Budget" about="The year's budget for this branch, what is spent and already ordered, and this request at its estimated prices.">
          <Table size="small" aria-label="Budget" style={{ minWidth: 640 }}>
            <TableHeader><TableRow><TableHeaderCell>Kind of spending</TableHeaderCell>{['Budget', 'Spent', 'Ordered', 'This request', 'Left'].map((h) => <TableHeaderCell key={h} button={END}>{h}</TableHeaderCell>)}</TableRow></TableHeader>
            <TableBody>{r.budget.map((b, i) => (
              <TableRow key={i}><TableCell>{b.account}</TableCell><TableCell className={s.end}>{m(b.budget)}</TableCell><TableCell className={s.end}>{m(b.spent)}</TableCell>
                <TableCell className={s.end}>{m(b.committed)}</TableCell><TableCell className={s.end}>{m(b.this)}</TableCell>
                <TableCell className={s.end} style={b.left < 0 ? { color: 'var(--zx-bad)' } : undefined}>{b.left < 0 ? 'Over by ' : ''}{m(Math.abs(b.left))}</TableCell></TableRow>
            ))}</TableBody>
          </Table>
        </Panel>
      )}

      <Panel title="Lines">
        <Table size="small" aria-label="Lines" style={{ minWidth: 520 }}>
          <TableHeader><TableRow><TableHeaderCell>#</TableHeaderCell><TableHeaderCell>Product</TableHeaderCell><TableHeaderCell button={END}>Quantity</TableHeaderCell><TableHeaderCell>Note</TableHeaderCell></TableRow></TableHeader>
          <TableBody>{r.lines.map((l) => (
            <TableRow key={l.no}><TableCell>{l.no}</TableCell><TableCell>{l.sku ? `${l.sku} · ` : ''}{l.name}</TableCell><TableCell className={s.end}>{l.qty}</TableCell><TableCell>{l.note ?? '—'}</TableCell></TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      {dialog === 'order' && a.order && (
        <Ask title="Order" go="Make the draft order" ready={!!v.supplier_id} onClose={() => setDialog(null)} run={() => run('order', { supplier_id: v.supplier_id })}>
          <div className={s.one}>
            <Text>Makes a draft purchase order to this supplier with the requested lines, priced from the supplier's list. Check it, then submit it as usual.</Text>
            <Choose label="Supplier" required options={a.order.suppliers} value={v.supplier_id} onChange={(x) => setV({ ...v, supplier_id: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'quotes' && a.quotes && (
        <Ask title="Ask for quotes" go="Make the request for quotation" width={600} ready={v.supplier_ids.length > 0 && !!v.closes_at} onClose={() => setDialog(null)}
          run={() => run('quotes', { supplier_ids: v.supplier_ids, closes_at: v.closes_at, terms: v.terms })}>
          <div className={s.one}>
            <Text>Ask several suppliers for prices on these lines first. Their prices stay sealed until the closing time; then compare and award.</Text>
            <ChooseMany label="Suppliers to ask" options={a.quotes.suppliers} value={v.supplier_ids} onChange={(x) => setV({ ...v, supplier_ids: x })} hint="Three or more give a fair comparison." />
            <TextField label="Quotes close" required type="datetime-local" value={v.closes_at} onChange={(x) => setV({ ...v, closes_at: x })} />
            <LongText label="What the suppliers are told (optional)" maxLength={2000} value={v.terms} onChange={(x) => setV({ ...v, terms: x })} />
          </div>
        </Ask>
      )}
      {(dialog === 'reject' || dialog === 'cancel') && (
        <Ask title={dialog === 'reject' ? 'Reject the request' : 'Cancel the request'} go={dialog === 'reject' ? 'Reject' : 'Cancel the request'} ready={v.reason.trim() !== ''}
          onClose={() => setDialog(null)} run={() => run(dialog, { reason: v.reason })}>
          <TextField label={dialog === 'reject' ? 'Why (the person who asked sees it)' : 'Why'} required maxLength={200} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
        </Ask>
      )}
    </div>
  )
}
