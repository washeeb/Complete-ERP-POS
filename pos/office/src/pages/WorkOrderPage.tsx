import { Button, Checkbox, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowDownloadRegular, ArrowLeftRegular, ArrowUploadRegular, CheckmarkCircleRegular, ClockRegular, DeleteRegular, DismissCircleRegular, DocumentTextRegular,
  PauseRegular, PlayRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type UnitOption, type WorkOrderDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { UnitPicker } from '../form/UnitPicker'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { day, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Action = 'start' | 'labour' | 'parts' | 'parts_back' | 'wait' | 'complete' | 'invoice' | 'cancel'
type Parts = { warehouse_id: string; lines: { unit: UnitOption | null; qty: string }[] }

/**
 * One work order (part 31), as the current page: the equipment, kind, status, who, by when, downtime, who pays, invoice,
 * details and outcome; its cost (time, parts, outside technicians), checklist and parts used. Start, Log time, Use parts,
 * Parts back, Waiting for parts, Done (the checklist, the meter, what was done), Invoice the customer and Cancel.
 */
export function WorkOrderPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<WorkOrderDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [ask, setAsk] = useState<Action | null>(null)
  const [f, setF] = useState({ hours: '', note: '', why: '', outcome: '', meter: '', skip: false, labour: '0', service: '', service_name: null as string | null })
  const [checklist, setChecklist] = useState<{ item: string; done: boolean }[]>([])
  const [parts, setParts] = useState<Parts>({ warehouse_id: '', lines: [] })
  const load = useCallback(() => {
    api.workOrder(id).then((x) => { setD(x); onTitle(`${x.number} · ${x.title}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the work order…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  const a = d.actions
  const show = (x: Action) => {
    setDone('')
    setF({ hours: '', note: '', why: '', outcome: '', meter: '', skip: false, labour: '0', service: '', service_name: null })
    setChecklist(d.checklist.map((c) => ({ ...c })))
    setParts({ warehouse_id: d.warehouses[0]?.id ?? '', lines: [{ unit: null, qty: '' }] })
    setAsk(x)
  }
  const run = (action: Action, body: Record<string, unknown> = {}) => api.workOrderAction(d.id, action, body).then((x) => {
    if (x.open) { navigate(x.open); return }
    setAsk(null); setError(''); setDone(x.message); load()
  })
  const back = () => (history.length > 1 ? history.back() : navigate('/office/maintenance/orders'))
  const unchecked = checklist.some((c) => !c.done)
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Work orders</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>{d.title}<ToneBadge tone={d.tone} label={d.status_label} /></h1>
        <div className={s.actions}>
          {a.start && <Button icon={<PlayRegular />} onClick={() => run('start').catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))}>Start</Button>}
          {a.labour && <Button icon={<ClockRegular />} onClick={() => show('labour')}>Log time</Button>}
          {a.parts && <Button icon={<ArrowUploadRegular />} onClick={() => show('parts')}>Use parts</Button>}
          {a.parts_back && <Button icon={<ArrowDownloadRegular />} onClick={() => show('parts_back')}>Parts back</Button>}
          {a.wait && <Button icon={<PauseRegular />} onClick={() => show('wait')}>Waiting for parts</Button>}
          {a.complete && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => show('complete')}>Done</Button>}
          {a.invoice && <Button appearance="primary" icon={<DocumentTextRegular />} onClick={() => show('invoice')}>Invoice the customer</Button>}
          {a.cancel && <Button icon={<DismissCircleRegular />} onClick={() => show('cancel')}>Cancel</Button>}
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Work order">
        <div className={s.facts}>
          <Fact label="Equipment">{d.asset ? <Link onClick={() => navigate(d.asset!.open)}>{d.asset.name}</Link> : '—'}{d.asset?.where && <Text size={200} block className={s.muted}>{d.asset.where}</Text>}</Fact>
          <Fact label="Kind">{d.kind}</Fact>
          <Fact label="Who">{d.who ?? 'Nobody yet'}</Fact>
          <Fact label="By">{day(d.due)}</Fact>
          <Fact label="Down for">{d.down}</Fact>
          <Fact label="Paid by">{d.paid_by}</Fact>
          <Fact label="Invoice">{d.invoice ? <Link onClick={() => navigate(d.invoice!.open)}>{d.invoice.number}</Link> : '—'}</Fact>
        </div>
        {d.details && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}><b>Details:</b> {d.details}</Text>}
        {d.outcome && <Text block style={{ marginTop: 6, whiteSpace: 'pre-wrap' }}><b>What was done:</b> {d.outcome}</Text>}
      </Panel>
      <Panel title="Cost">
        <div className={s.facts}>
          <Fact label="Time">{d.labour} · {m(d.labour_minor)}</Fact>
          <Fact label="Parts">{m(d.parts_minor)}</Fact>
          <Fact label="Outside technicians">{m(d.outside_minor)}</Fact>
          <Fact label="Total"><b>{m(d.total)}</b></Fact>
        </div>
      </Panel>
      {d.checklist.length > 0 && (
        <Panel title="Checklist">
          <Table size="small" aria-label="Checklist"><TableBody>{d.checklist.map((c, i) => (
            <TableRow key={i}><TableCell>{c.item}</TableCell><TableCell>{c.done ? 'Done' : <Text className={s.muted}>Not done</Text>}</TableCell></TableRow>
          ))}</TableBody></Table>
        </Panel>
      )}
      {d.parts.length > 0 && (
        <Panel title="Parts used">
          <Table size="small" aria-label="Parts used"><TableBody>{d.parts.map((p) => (
            <TableRow key={p.id}><TableCell>{p.name ?? '—'}</TableCell><TableCell className={s.end}>{p.qty}</TableCell><TableCell className={s.end}>{m(p.cost)}</TableCell></TableRow>
          ))}</TableBody></Table>
        </Panel>
      )}

      {ask === 'labour' && (
        <Ask title="Log time" go="Log" ready={Number(f.hours) > 0} onClose={() => setAsk(null)} run={() => run('labour', { hours: f.hours, note: f.note })}>
          <div className={s.one}>
            <TextField label="Hours spent" required type="number" min={0.05} max={24} step="any" value={f.hours} onChange={(x) => setF({ ...f, hours: x })} />
            <TextField label="Note" maxLength={120} value={f.note} onChange={(x) => setF({ ...f, note: x })} />
          </div>
        </Ask>
      )}
      {(ask === 'parts' || ask === 'parts_back') && (
        <Ask title={ask === 'parts' ? 'Use parts' : 'Parts back'} go={ask === 'parts' ? 'Use' : 'Put back'} width={680} onClose={() => setAsk(null)}
          ready={!!parts.warehouse_id && parts.lines.length > 0 && parts.lines.every((l) => l.unit && Number(l.qty) > 0)}
          run={() => run(ask, { warehouse_id: parts.warehouse_id, lines: parts.lines.map((l) => ({ product_uom_id: l.unit!.id, qty: l.qty })) })}>
          <div className={s.one}>
            <Text>{ask === 'parts' ? 'Parts taken out of a store for this job, at their cost.' : 'Parts not used, back into a store.'}</Text>
            <Choose label="Store" required options={d.warehouses} value={parts.warehouse_id} onChange={(x) => setParts({ ...parts, warehouse_id: x })} />
            {parts.lines.map((l, i) => (
              <div key={i} className={s.grid} role="group" aria-label={`Part ${i + 1}`}>
                <UnitPicker value={l.unit?.id ?? ''} label={l.unit?.label ?? null} search={api.maintUnits}
                  onChange={(u) => setParts({ ...parts, lines: parts.lines.map((x, j) => (j === i ? { ...x, unit: u } : x)) })} />
                <TextField label="Quantity" required type="number" min={0.001} step="any" value={l.qty} onChange={(q) => setParts({ ...parts, lines: parts.lines.map((x, j) => (j === i ? { ...x, qty: q } : x)) })} />
                <div style={{ display: 'flex', alignItems: 'flex-end', height: '100%' }}>
                  {parts.lines.length > 1 && <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove part ${i + 1}`} onClick={() => setParts({ ...parts, lines: parts.lines.filter((_, j) => j !== i) })} />}
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => setParts({ ...parts, lines: [...parts.lines, { unit: null, qty: '' }] })}>Add a part</Button></div>
          </div>
        </Ask>
      )}
      {ask === 'wait' && (
        <Ask title="Waiting for parts" go="Mark waiting" ready={f.why.trim() !== ''} onClose={() => setAsk(null)} run={() => run('wait', { why: f.why })}>
          <TextField label="For what" required maxLength={255} value={f.why} onChange={(x) => setF({ ...f, why: x })} />
        </Ask>
      )}
      {ask === 'complete' && (
        <Ask title="Done" go="Done" width={600} ready={f.outcome.trim() !== '' && (!unchecked || f.skip)} onClose={() => setAsk(null)}
          run={() => run('complete', { outcome: f.outcome, checklist: checklist.length ? checklist : undefined, skip_checklist: f.skip, meter_reading: f.meter === '' ? null : f.meter })}>
          <div className={s.one}>
            {checklist.length > 0 && <>
              <Text weight="semibold">Checklist</Text>
              {checklist.map((c, i) => <Checkbox key={i} label={c.item} checked={c.done} onChange={(_, e) => setChecklist(checklist.map((x, j) => (j === i ? { ...x, done: !!e.checked } : x)))} />)}
              {unchecked && <Checkbox label="Some steps were not done (say why below)" checked={f.skip} onChange={(_, e) => setF({ ...f, skip: !!e.checked })} />}
            </>}
            {d.meter_unit && <TextField label={`Meter now (${d.meter_unit === 'km' ? 'km' : 'hours'})`} type="number" min={0} step="any" value={f.meter} onChange={(x) => setF({ ...f, meter: x })} />}
            <LongText label="What was done" maxLength={5000} value={f.outcome} onChange={(x) => setF({ ...f, outcome: x })} />
          </div>
        </Ask>
      )}
      {ask === 'invoice' && (
        <Ask title="Invoice the customer" go="Invoice" ready={Number(f.labour) === 0 || !!f.service} onClose={() => setAsk(null)}
          run={() => run('invoice', { labour: f.labour || 0, service: f.service || null })}>
          <div className={s.one}>
            <Text>Parts at their price on the customer's price list; labour as the service you choose.</Text>
            <TextField label="Labour to charge (before tax)" type="number" min={0} step="any" prefix={d.currency} value={f.labour} onChange={(x) => setF({ ...f, labour: x })} />
            <SearchPicker label="Labour shows as" value={f.service} text={f.service_name} search={api.maintServices} placeholder="e.g. Repair labour"
              onChange={(x) => setF({ ...f, service: x?.id ?? '', service_name: x?.label ?? null })} />
          </div>
        </Ask>
      )}
      {ask === 'cancel' && (
        <Ask title="Cancel this work order?" go="Cancel work order" ready={f.why.trim() !== ''} onClose={() => setAsk(null)} run={() => run('cancel', { why: f.why })}>
          <TextField label="Why" required maxLength={255} value={f.why} onChange={(x) => setF({ ...f, why: x })} />
        </Ask>
      )}
    </div>
  )
}
