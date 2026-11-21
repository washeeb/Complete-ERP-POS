import { Button, Input, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { ArchiveRegular, ArrowLeftRegular, CartRegular, CheckmarkCircleRegular, EditRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type AgreementDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { day, END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Dialog = 'activate' | 'release' | 'close' | null

/**
 * One contract or blanket order (part 18): the supplier, kind, period, what is ordered so far against any cap, the
 * signed copy, the items with their agreed prices, ordered and left, and the orders made against it. Put in force
 * (someone other than who made it), Order against it (a draft purchase order at the agreed prices), Close, Edit (a draft).
 */
export function AgreementPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [a, setA] = useState<AgreementDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<Dialog>(null)
  const [v, setV] = useState({ warehouse_id: '', expected_on: '', reason: '' })
  const [qtys, setQtys] = useState<Record<string, string>>({})
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.agreement(id).then((x) => { setA(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !a) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!a) return <div className={page.page}><Spinner label="Opening the agreement…" /></div>
  const m = (x: number) => money(x, a.currency, a.exponent)
  const act = a.actions
  const open = (d: Dialog) => { setDone(''); setV({ warehouse_id: act.release?.warehouses[0]?.id ?? '', expected_on: '', reason: '' }); setQtys({}); setDialog(d) }
  const run = (action: 'activate' | 'release' | 'close', body: Record<string, unknown> = {}) => api.agreementAction(a.id, action, body).then((x) => {
    if (x.open) { navigate(x.open); return }
    if (x.agreement) setA(x.agreement)
    setDialog(null)
    setDone(x.message)
  })

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/agreements'))}>Contracts and blanket orders</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{a.number}</span><ToneBadge tone={a.tone} label={a.status_label} /></h1>
        <div className={s.actions}>
          {act.activate && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => open('activate')}>Put in force</Button>}
          {act.release && <Button appearance="primary" icon={<CartRegular />} onClick={() => open('release')}>Order against it</Button>}
          {act.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/agreements/${a.id}/edit`)}>Edit</Button>}
          {act.close && <Button icon={<ArchiveRegular />} onClick={() => open('close')}>Close</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      <Panel title="Details">
        <div className={s.facts}>
          <Fact label="Supplier">{a.supplier_open ? <Link onClick={() => navigate(a.supplier_open!)}>{a.supplier}</Link> : a.supplier ?? '—'}</Fact>
          <Fact label="Kind">{a.kind}</Fact>
          <Fact label="Title">{a.title}</Fact>
          <Fact label="From">{day(a.starts_on)}</Fact>
          <Fact label="Until">{day(a.ends_on)}</Fact>
          <Fact label="Ordered so far">{m(a.used)}{a.cap !== null ? ` of ${m(a.cap)}` : ''}</Fact>
          <Fact label="Made by">{a.made_by ?? '—'}</Fact>
          <Fact label="Put in force by">{a.approved_by ?? '—'}</Fact>
          <Fact label="Signed agreement">{a.file ? <Link href={a.file.open} target="_blank" rel="noopener">{a.file.name}</Link> : '—'}</Fact>
          {a.closed_reason && <Fact label="Closed because">{a.closed_reason}</Fact>}
        </div>
        {a.terms && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{a.terms}</Text>}
      </Panel>

      <Panel title="Items">
        <Table size="small" aria-label="Items" style={{ minWidth: 560 }}>
          <TableHeader><TableRow><TableHeaderCell>#</TableHeaderCell><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell button={END}>Agreed price</TableHeaderCell>
            <TableHeaderCell button={END}>Ordered</TableHeaderCell><TableHeaderCell button={END}>Left</TableHeaderCell></TableRow></TableHeader>
          <TableBody>{a.lines.map((l) => (
            <TableRow key={l.no}><TableCell>{l.no}</TableCell><TableCell>{l.item}</TableCell><TableCell className={s.end}>{m(l.price)} / {l.uom}</TableCell>
              <TableCell className={s.end}>{l.ordered}</TableCell><TableCell className={s.end}>{l.left}</TableCell></TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      <Panel title="Orders made against it">
        {a.orders.length === 0 ? <Text className={s.muted}>None yet.</Text> : (
          <Table size="small" aria-label="Orders made against it">
            <TableHeader><TableRow><TableHeaderCell>Order</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell button={END}>Total</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{a.orders.map((o) => (
              <TableRow key={o.id}><TableCell><Link onClick={() => navigate(o.open)}>{o.number}</Link></TableCell><TableCell>{o.status}</TableCell><TableCell className={s.end}>{m(o.total)}</TableCell></TableRow>
            ))}</TableBody>
          </Table>
        )}
      </Panel>

      {dialog === 'activate' && (
        <Ask title="Put in force?" go="Put in force" ready onClose={() => setDialog(null)} run={() => run('activate')}>
          <Text>From its start date, orders to this supplier take these prices and never pay more. Someone other than who made it puts it in force.</Text>
        </Ask>
      )}
      {dialog === 'release' && act.release && (
        <Ask title="Order against it" go="Make the draft order" width={640} ready={!!v.warehouse_id && Object.values(qtys).some((q) => Number(q) > 0)} onClose={() => setDialog(null)}
          run={() => run('release', { warehouse_id: v.warehouse_id, expected_on: v.expected_on || null, qtys })}>
          <div className={s.one}>
            <Text>A draft purchase order at the agreed prices. Check it and submit it for approval as usual.</Text>
            <Choose label="Deliver to" required options={act.release.warehouses} value={v.warehouse_id} onChange={(x) => setV({ ...v, warehouse_id: x })} />
            <TextField label="Expected delivery" type="date" value={v.expected_on} onChange={(x) => setV({ ...v, expected_on: x })} />
            {act.release.lines.map((l) => (
              <div key={l.id} className={s.grid}>
                <Text style={{ alignSelf: 'center' }}>{l.item}</Text>
                <Input type="number" min={0} step="any" aria-label={`Quantity now: ${l.item}`} placeholder="0" value={qtys[l.id] ?? ''} onChange={(_, d) => setQtys({ ...qtys, [l.id]: d.value })} />
              </div>
            ))}
          </div>
        </Ask>
      )}
      {dialog === 'close' && (
        <Ask title="Close the agreement?" go="Close" ready={v.reason.trim() !== ''} onClose={() => setDialog(null)} run={() => run('close', { reason: v.reason })}>
          <TextField label="Reason" required maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
        </Ask>
      )}
    </div>
  )
}
