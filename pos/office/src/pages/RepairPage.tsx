import { Button, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, ArrowSyncRegular, DeleteRegular, HandRightRegular, ListRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type RepairDetail, type UnitOption } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { UnitPicker } from '../form/UnitPicker'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { day, END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Action = 'progress' | 'lines' | 'hand_over'
type Line = { unit: Pick<UnitOption, 'id' | 'label'> | null; qty: string }

/**
 * One repair job (part 36), as the current page: customer, device and serial, what is wrong, what was left with it,
 * warranty, supplier RMA; parts and labour (priced from the price list; nothing under warranty) and the progress log.
 * Update progress (sent to the supplier with the RMA number), Parts and labour, Hand over (no charge).
 */
export function RepairPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<RepairDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [ask, setAsk] = useState<Action | null>(null)
  const [f, setF] = useState({ status: '', note: '', supplier: '', supplier_name: null as string | null, rma: '', warehouse: '' })
  const [lines, setLines] = useState<Line[]>([])
  const load = useCallback(() => {
    api.repair(id).then((x) => { setD(x); onTitle(`${x.number} · ${x.device}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the repair job…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  const a = d.actions
  const show = (x: Action) => {
    setDone('')
    setF({ status: '', note: '', supplier: '', supplier_name: null, rma: '', warehouse: d.warehouses[0]?.id ?? '' })
    setLines(d.line_values.map((l) => ({ unit: { id: l.product_uom_id, label: l.unit }, qty: l.qty })))
    setAsk(x)
  }
  const run = (action: Action, body: Record<string, unknown>) => api.repairAction(d.id, action, body).then((x) => { setAsk(null); setError(''); setDone(x.message); load() })
  const back = () => (history.length > 1 ? history.back() : navigate('/office/electronics/repairs'))
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Repairs</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>{d.device}<ToneBadge tone={d.tone} label={d.status_label} /></h1>
        <div className={s.actions}>
          {a.progress && <Button appearance="primary" icon={<ArrowSyncRegular />} onClick={() => show('progress')}>Update progress</Button>}
          {a.lines && <Button icon={<ListRegular />} onClick={() => show('lines')}>Parts and labour</Button>}
          {a.hand_over && <Button icon={<HandRightRegular />} onClick={() => show('hand_over')}>Hand over (no charge)</Button>}
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Repair job">
        <div className={s.facts}>
          <Fact label="Customer">{d.customer}{d.phone && <Text size={200} block className={s.muted}>{d.phone}</Text>}</Fact>
          <Fact label="Device">{d.device}{d.serial && <Text size={200} block className={s.muted}>{d.serial_open ? <Link onClick={() => navigate(d.serial_open!)}>{d.serial}</Link> : d.serial}</Text>}</Fact>
          <Fact label="In">{d.received ?? '—'}</Fact>
          <Fact label="Promised for">{day(d.promised)}</Fact>
          <Fact label="Warranty">{d.warranty ? 'In warranty (no charge)' : 'Out of warranty'}</Fact>
          <Fact label="Left with it">{d.accessories ?? '—'}</Fact>
          <Fact label="Supplier RMA">{d.supplier ? `${d.supplier}${d.rma ? ` · ${d.rma}` : ''}` : '—'}</Fact>
        </div>
        <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}><b>What is wrong:</b> {d.fault}</Text>
      </Panel>
      <Panel title="Parts and labour" about={d.charge > 0 ? 'Collected by paying for these at the till.' : undefined}>
        {d.lines.length === 0 ? <Text className={s.muted}>None yet.</Text> : (
          <Table size="small" aria-label="Parts and labour">
            <TableHeader><TableRow><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Quantity</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Price</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Amount</TableHeaderCell></TableRow></TableHeader>
            <TableBody>
              {d.lines.map((l) => <TableRow key={l.id}><TableCell>{l.name}</TableCell><TableCell className={s.end}>{l.qty}</TableCell><TableCell className={s.end}>{m(l.price)}</TableCell>
                <TableCell className={s.end}>{m(l.amount)}</TableCell></TableRow>)}
              <TableRow><TableCell><b>Total</b></TableCell><TableCell /><TableCell /><TableCell className={s.end}><b>{m(d.charge)}</b></TableCell></TableRow>
            </TableBody>
          </Table>
        )}
      </Panel>
      <Panel title="Progress">
        <Table size="small" aria-label="Progress"><TableBody>{d.log.map((e, i) => (
          <TableRow key={i}><TableCell>{e.when}</TableCell><TableCell>{e.status}</TableCell><TableCell>{e.by}</TableCell><TableCell>{e.note ?? ''}</TableCell></TableRow>
        ))}</TableBody></Table>
      </Panel>

      {ask === 'progress' && (
        <Ask title="Update progress" go="Update" ready={!!f.status && (f.status !== 'at_supplier' || !!f.supplier)} onClose={() => setAsk(null)}
          run={() => run('progress', { status: f.status, note: f.note, supplier_id: f.status === 'at_supplier' ? f.supplier : null, rma_reference: f.status === 'at_supplier' ? f.rma : null })}>
          <div className={s.one}>
            <Choose label="Now" required options={d.next} value={f.status} onChange={(x) => setF({ ...f, status: x })} />
            {f.status === 'at_supplier' && <>
              <SearchPicker label="Back to supplier" required value={f.supplier} text={f.supplier_name} search={api.repairSuppliers} placeholder="Supplier name"
                onChange={(x) => setF({ ...f, supplier: x?.id ?? '', supplier_name: x?.label ?? null })} />
              <TextField label="Supplier RMA number" maxLength={80} value={f.rma} onChange={(x) => setF({ ...f, rma: x })} />
            </>}
            <TextField label="Note" maxLength={500} value={f.note} onChange={(x) => setF({ ...f, note: x })} />
          </div>
        </Ask>
      )}
      {ask === 'lines' && (
        <Ask title="Parts and labour" go="Save" width={680} onClose={() => setAsk(null)} ready={lines.every((l) => l.unit && Number(l.qty) > 0)}
          run={() => run('lines', { lines: lines.map((l) => ({ product_uom_id: l.unit!.id, qty: l.qty })) })}>
          <div className={s.one}>
            <Text>{d.warranty ? 'Under warranty: nothing is charged.' : 'Priced from the price list when saved.'} Add labour as a service product.</Text>
            {lines.map((l, i) => (
              <div key={i} className={s.grid} role="group" aria-label={`Line ${i + 1}`}>
                <UnitPicker value={l.unit?.id ?? ''} label={l.unit?.label ?? null} search={api.repairUnits}
                  onChange={(u) => setLines(lines.map((x, j) => (j === i ? { ...x, unit: u } : x)))} />
                <TextField label="Quantity" required type="number" min={0.001} step="any" value={l.qty} onChange={(q) => setLines(lines.map((x, j) => (j === i ? { ...x, qty: q } : x)))} />
                <div style={{ display: 'flex', alignItems: 'flex-end', height: '100%' }}>
                  <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${i + 1}`} onClick={() => setLines(lines.filter((_, j) => j !== i))} />
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => setLines([...lines, { unit: null, qty: '1' }])}>Add part or labour</Button></div>
          </div>
        </Ask>
      )}
      {ask === 'hand_over' && (
        <Ask title="Hand over (no charge)?" go="Hand over" ready={!!f.warehouse} onClose={() => setAsk(null)}
          run={() => run('hand_over', { warehouse_id: f.warehouse })}>
          <div className={s.one}>
            <Text>Under warranty, or nothing to charge: any parts used are written off to the repair.</Text>
            <Choose label="Parts taken from" required options={d.warehouses} value={f.warehouse} onChange={(x) => setF({ ...f, warehouse: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}
