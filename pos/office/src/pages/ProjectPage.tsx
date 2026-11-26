import { Button, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowDownloadRegular, ArrowLeftRegular, ArrowUndoRegular, ArrowUploadRegular, CheckmarkCircleRegular, DeleteRegular, DismissCircleRegular, DocumentTextRegular,
  SaveRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type MilestoneValues, type ProjectDetail, type TaskValues, type UnitOption } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { UnitPicker } from '../form/UnitPicker'
import { money } from '../home/format'
import { DataTable } from '../list/DataTable'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { day, END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

type Stock = { return: boolean; warehouse_id: string; note: string; lines: { unit: UnitOption | null; qty: string }[] }
const blankTask: TaskValues = { name: '', assigned_to: '', status: 'todo', due_on: '', estimate_hours: '', billable: true, sort: '0' }
const blankMilestone: MilestoneValues = { name: '', amount: '', due_on: '', status: 'pending', sort: '0' }

/**
 * One project (part 27), as the current page: how it is doing (cost against budget, invoiced or hours, not yet invoiced,
 * profit or tasks done), the project itself, its tasks, its milestones (fixed price) and the costs collected (stock,
 * bills, expenses, approved time). Issue and return stock, invoice time and materials (all, or the costs ticked),
 * invoice a milestone, finish, cancel and reopen. A closed project is shown, not changed.
 */
export function ProjectPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<ProjectDetail | null>(null)
  const [v, setV] = useState<ProjectDetail['values'] | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState<'invoice_time' | 'finish' | 'cancel' | 'reopen' | null>(null)
  const [stock, setStock] = useState<Stock | null>(null)
  const [task, setTask] = useState<{ id: string | null; v: TaskValues } | null>(null)
  const [mile, setMile] = useState<{ id: string | null; v: MilestoneValues } | null>(null)
  const [billMile, setBillMile] = useState<string | null>(null)
  const [kind, setKind] = useState('')
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const load = useCallback(() => {
    api.project(id, location.search.replace(/^\?/, '')).then((x) => { setD(x); setV(x.values); setTicked(new Set()); onTitle(x.id ? `${x.number} · ${x.values.name}` : 'New project') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the project…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  const put = <K extends keyof ProjectDetail['values']>(k: K) => (x: ProjectDetail['values'][K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const a = d.actions
  const edit = a.edit
  const back = () => (history.length > 1 ? history.back() : navigate('/office/projects'))
  const fail = (e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const after = (x: { message: string; open?: string }) => {
    if (x.open) { navigate(x.open); return }
    setDialog(null); setStock(null); setTask(null); setMile(null); setBillMile(null); setError(''); setDone(x.message); load()
  }
  const save = () => {
    setBusy(true)
    setError('')
    const { customer_name: _c, service_name: _s, ...body } = v
    api.saveProject(d.id, body).then((x) => { if (d.id) after(x); else navigate(`/office/projects/${x.id}`, true) }).catch(fail).finally(() => setBusy(false))
  }
  const billed = v.billing !== 'internal'
  const ready = v.name.trim() !== '' && !!v.branch_id && (!billed || (!!v.customer_id && !!v.service_product_id))
  const fig = d.figures
  const costs = (d.costs ?? []).filter((c) => !kind || c.kind === kind)
  const canTick = !!a.invoice_time && d.values.billing === 'time'
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Projects</Button>
      <div className={s.head}>
        <h1 className={s.title}>{d.id && <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>}{d.id ? d.values.name : 'New project'}
          {d.id && d.status_label && <ToneBadge tone={d.tone ?? 'subtle'} label={d.status_label} />}</h1>
        {d.id && <div className={s.actions}>
          {a.issue && <Button icon={<ArrowUploadRegular />} onClick={() => setStock({ return: false, warehouse_id: d.warehouses?.[0]?.id ?? '', note: '', lines: [{ unit: null, qty: '' }] })}>Issue stock</Button>}
          {a.return && <Button icon={<ArrowDownloadRegular />} onClick={() => setStock({ return: true, warehouse_id: d.warehouses?.[0]?.id ?? '', note: '', lines: [{ unit: null, qty: '' }] })}>Return stock</Button>}
          {a.invoice_time && <Button icon={<DocumentTextRegular />} disabled={!fig?.unbilled} onClick={() => setDialog('invoice_time')}>Invoice time and materials</Button>}
          {a.finish && <Button icon={<CheckmarkCircleRegular />} onClick={() => setDialog('finish')}>Finish</Button>}
          {a.cancel && <Button icon={<DismissCircleRegular />} onClick={() => setDialog('cancel')}>Cancel project</Button>}
          {a.reopen && <Button icon={<ArrowUndoRegular />} onClick={() => setDialog('reopen')}>Reopen</Button>}
        </div>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      {fig && (
        <Panel title="How it is doing">
          <div className={s.facts}>
            <Fact label="Cost so far">{m(fig.cost)}{fig.budget ? <Text size={200} block className={s.muted}>Budget {m(fig.budget)} ({Math.round(fig.cost * 100 / Math.max(1, fig.budget))} % used)</Text> : null}</Fact>
            <Fact label={d.billed ? 'Invoiced (before tax)' : 'Hours'}>{d.billed ? m(fig.invoiced) : fig.hours.toFixed(1)}
              {d.values.billing === 'milestones' && d.contract_minor ? <Text size={200} block className={s.muted}>of {m(d.contract_minor)} agreed</Text> : null}</Fact>
            <Fact label={d.values.billing === 'time' ? 'Not yet invoiced' : 'Hours logged'}>{d.values.billing === 'time' ? m(fig.unbilled) : fig.hours.toFixed(1)}</Fact>
            <Fact label={d.billed ? 'Profit so far' : 'Tasks done'}>{d.billed ? `${m(fig.profit)}${fig.margin !== null ? ` (${fig.margin} %)` : ''}` : fig.progress === null ? '—' : `${fig.progress} %`}</Fact>
          </div>
        </Panel>
      )}

      <Panel title="Project">
        <div className={f.grid}>
          <TextField label="Name" required className={f.spanAll} maxLength={255} disabled={!edit} value={v.name} onChange={put('name')} />
          {d.id && <Choose label="Status" required disabled={!edit} options={d.statuses} value={v.status} onChange={put('status')} />}
          <Choose label="How it is billed" required disabled={!edit} options={d.billings} value={v.billing} onChange={put('billing')} />
          {billed && <SearchPicker label="Customer" required value={v.customer_id} text={v.customer_name} search={api.projectCustomers} placeholder="Type a name or phone"
            onChange={(x) => setV({ ...v, customer_id: x?.id ?? '', customer_name: x?.label ?? null })} />}
          {!billed && <SearchPicker label="Customer (optional)" value={v.customer_id} text={v.customer_name} search={api.projectCustomers} placeholder="Type a name or phone"
            onChange={(x) => setV({ ...v, customer_id: x?.id ?? '', customer_name: x?.label ?? null })} />}
          <Choose label="Branch" required disabled={!edit} options={d.branches} value={v.branch_id} onChange={put('branch_id')} />
          <Choose label="Project manager" disabled={!edit} options={d.managers} value={v.manager_id} onChange={put('manager_id')} clearable />
          <TextField label="Starts" type="date" disabled={!edit} value={v.starts_on} onChange={put('starts_on')} />
          <TextField label="Due to finish" type="date" disabled={!edit} value={v.ends_on} onChange={put('ends_on')} />
          <TextField label="Budget (what it should cost)" type="number" min={0} step="any" prefix={d.currency} disabled={!edit} value={v.budget_cost} onChange={put('budget_cost')} />
          {v.billing === 'milestones' && <TextField label="Agreed price (before tax)" type="number" min={0} step="any" prefix={d.currency} disabled={!edit} value={v.contract} onChange={put('contract')} />}
          {v.billing === 'time' && <TextField label="Hourly rate billed" type="number" min={0} step="any" prefix={d.currency} disabled={!edit} value={v.hourly_rate} onChange={put('hourly_rate')}
            hint="For people without a billing rate of their own (Hourly rates)." />}
          {v.billing === 'time' && <TextField label="Materials billed at cost + (%)" type="number" min={0} max={500} step="any" disabled={!edit} value={v.markup} onChange={put('markup')} />}
          {billed && <SearchPicker label="Service on invoices" required value={v.service_product_id} text={v.service_name} search={api.projectServices} placeholder="e.g. Professional services"
            onChange={(x) => setV({ ...v, service_product_id: x?.id ?? '', service_name: x?.label ?? null })} />}
          <LongText label="Description" className={f.spanAll} maxLength={5000} value={v.description} onChange={put('description')} />
        </div>
        {billed && <Text size={200} block className={s.muted}>The service is a product without stock, e.g. “Professional services”: time, milestones and bought-in costs show as it on invoices, with its tax.</Text>}
      </Panel>
      {edit && (
        <SaveBar value={v}>
          <Button onClick={back}>Cancel</Button>
          <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : d.id ? 'Save' : 'Add project'}</Button>
        </SaveBar>
      )}

      {d.id && (
        <Panel title="Tasks" actions={a.tasks && <Button icon={<AddRegular />} onClick={() => setTask({ id: null, v: { ...blankTask, billable: !!d.billed } })}>New task</Button>}>
          {(d.tasks ?? []).length === 0 ? <Text className={s.muted}>No tasks.</Text> : (
            <Table size="small" aria-label="Tasks" style={{ minWidth: 560 }}>
              <TableHeader><TableRow><TableHeaderCell>Task</TableHeaderCell><TableHeaderCell>Who</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell>Due</TableHeaderCell>
                <TableHeaderCell button={END}>Hours</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
              <TableBody>{(d.tasks ?? []).map((t) => (
                <TableRow key={t.id}><TableCell><Text weight="semibold">{t.name}</Text></TableCell><TableCell>{t.who ?? '—'}</TableCell>
                  <TableCell><ToneBadge tone={t.tone} label={t.status_label} /></TableCell>
                  <TableCell><span style={t.late ? { color: tokens.colorPaletteRedForeground1 } : undefined}>{day(t.due)}</span></TableCell>
                  <TableCell className={s.end}>{t.hours}</TableCell>
                  <TableCell>{a.tasks && <Button size="small" onClick={() => setTask({ id: t.id, v: { ...t.values } })}>Change</Button>}</TableCell></TableRow>
              ))}</TableBody>
            </Table>
          )}
        </Panel>
      )}

      {d.id && d.values.billing === 'milestones' && (
        <Panel title="Milestones" about="Split the agreed price into parts invoiced when reached, e.g. 30 % deposit, 50 % on delivery, 20 % at handover."
          actions={a.milestones && <Button icon={<AddRegular />} onClick={() => setMile({ id: null, v: { ...blankMilestone } })}>New milestone</Button>}>
          {(d.milestones ?? []).length === 0 ? <Text className={s.muted}>No milestones.</Text> : (
            <Table size="small" aria-label="Milestones" style={{ minWidth: 560 }}>
              <TableHeader><TableRow><TableHeaderCell>Milestone</TableHeaderCell><TableHeaderCell button={END}>Amount</TableHeaderCell><TableHeaderCell>Expected</TableHeaderCell>
                <TableHeaderCell>Status</TableHeaderCell><TableHeaderCell>Invoice</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
              <TableBody>{(d.milestones ?? []).map((x) => (
                <TableRow key={x.id}><TableCell><Text weight="semibold">{x.name}</Text></TableCell><TableCell className={s.end}>{m(x.amount)}</TableCell><TableCell>{day(x.due)}</TableCell>
                  <TableCell><ToneBadge tone={x.tone} label={x.status_label} /></TableCell>
                  <TableCell>{x.invoice ? <Link onClick={() => navigate(x.invoice!.open)}>{x.invoice.number}</Link> : '—'}</TableCell>
                  <TableCell><div className={s.actions}>
                    {x.can_edit && <Button size="small" onClick={() => setMile({ id: x.id, v: { ...x.values } })}>Change</Button>}
                    {x.can_invoice && <Button size="small" icon={<DocumentTextRegular />} onClick={() => setBillMile(x.id)}>Invoice</Button>}
                  </div></TableCell></TableRow>
              ))}</TableBody>
            </Table>
          )}
        </Panel>
      )}

      {d.id && (
        <Panel title="Costs" about="Costs arrive by themselves: stock issued to the project, bills and expense claims charged to it, approved time."
          actions={<div className={s.actions}>
            <Pick label="What" any="Every kind" options={d.cost_kinds ?? []} value={kind} onChange={setKind} />
            {canTick && <Button icon={<DocumentTextRegular />} disabled={ticked.size === 0} onClick={() => setDialog('invoice_time')}>Invoice the ticked ({ticked.size})</Button>}
          </div>}>
          <DataTable<NonNullable<ProjectDetail['costs']>[number]> label="Costs" rows={costs} sort="date" dir="desc" loading={false} empty="No costs yet." onSort={() => undefined} onOpen={() => undefined}
            selected={canTick ? ticked : undefined}
            onSelect={canTick ? (ids, on) => setTicked((o) => { const n = new Set(o); ids.forEach((i) => { if (on && costs.find((c) => c.id === i)?.unbilled) n.add(i); else n.delete(i) }); return n }) : undefined}
            columns={[
              { key: 'date', label: 'Date', render: (c) => day(c.date) },
              { key: 'kind', label: 'What', render: (c) => c.kind_label },
              { key: 'description', label: 'Description', render: (c) => c.description ?? '—' },
              { key: 'qty', label: 'Qty / hours', end: true, render: (c) => c.qty },
              { key: 'cost', label: 'Cost', end: true, render: (c) => m(c.cost) },
              ...(d.values.billing === 'time' ? [{ key: 'bill', label: 'Billed at', end: true, render: (c: NonNullable<ProjectDetail['costs']>[number]) => (c.bill === null ? 'Not billed' : m(c.bill)) }] : []),
              { key: 'invoice', label: 'Invoice', render: (c) => (c.invoice ? <Link onClick={() => navigate(c.invoice!.open)}>{c.invoice.number}</Link> : d.values.billing === 'time' && c.bill !== null ? 'Not yet' : '—') },
            ]} />
        </Panel>
      )}

      {dialog === 'invoice_time' && (
        <Ask title="Invoice time and materials?" go="Invoice" ready onClose={() => setDialog(null)}
          run={() => api.projectAction(d.id!, 'invoice_time', ticked.size ? { cost_ids: [...ticked] } : {}).then(after)}>
          <Text>{ticked.size ? `The ${ticked.size} ticked cost(s) go on one invoice.` : `Everything not yet invoiced (${m(fig?.unbilled ?? 0)} before tax) goes on one invoice. To leave some out, tick the costs to invoice in the list instead.`}</Text>
        </Ask>
      )}
      {dialog === 'finish' && (
        <Ask title="Finish this project?" go="Finish" ready onClose={() => setDialog(null)} run={() => api.projectAction(d.id!, 'finish').then(after)}>
          <Text>Its open tasks are closed, and for a billed project, costs not yet invoiced move from work in progress to project costs.</Text>
        </Ask>
      )}
      {dialog === 'cancel' && (
        <Ask title="Cancel this project?" go="Cancel project" ready onClose={() => setDialog(null)} run={() => api.projectAction(d.id!, 'cancel').then(after)}>
          <Text>It is closed as cancelled. Costs not yet invoiced move from work in progress to project costs.</Text>
        </Ask>
      )}
      {dialog === 'reopen' && (
        <Ask title="Reopen this project?" go="Reopen" ready onClose={() => setDialog(null)} run={() => api.projectAction(d.id!, 'reopen').then(after)}>
          <Text>It is in progress again: time can be logged and costs charged to it.</Text>
        </Ask>
      )}
      {stock && (
        <Ask title={stock.return ? 'Return stock' : 'Issue stock'} go={stock.return ? 'Return' : 'Issue'} width={680} onClose={() => setStock(null)}
          ready={!!stock.warehouse_id && stock.lines.length > 0 && stock.lines.every((l) => l.unit && Number(l.qty) > 0)}
          run={() => api.projectAction(d.id!, stock.return ? 'return' : 'issue', { warehouse_id: stock.warehouse_id, note: stock.note,
            lines: stock.lines.map((l) => ({ product_uom_id: l.unit!.id, qty: l.qty })) }).then(after)}>
          <div className={s.one}>
            <Text>{stock.return ? 'Items not used, back into a store at what the project was charged for them.' : 'Items taken out of a store for this project, at their cost.'}</Text>
            <Choose label="Store" required options={d.warehouses ?? []} value={stock.warehouse_id} onChange={(x) => setStock({ ...stock, warehouse_id: x })} />
            {stock.lines.map((l, i) => (
              <div key={i} className={s.grid} role="group" aria-label={`Item ${i + 1}`}>
                <UnitPicker value={l.unit?.id ?? ''} label={l.unit?.label ?? null} search={api.projectUnits}
                  onChange={(u) => setStock({ ...stock, lines: stock.lines.map((x, j) => (j === i ? { ...x, unit: u } : x)) })} />
                <TextField label="Quantity" required type="number" min={0.001} step="any" value={l.qty} onChange={(q) => setStock({ ...stock, lines: stock.lines.map((x, j) => (j === i ? { ...x, qty: q } : x)) })} />
                <div style={{ display: 'flex', alignItems: 'flex-end', height: '100%' }}>
                  {stock.lines.length > 1 && <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove item ${i + 1}`} onClick={() => setStock({ ...stock, lines: stock.lines.filter((_, j) => j !== i) })} />}
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => setStock({ ...stock, lines: [...stock.lines, { unit: null, qty: '' }] })}>Add an item</Button></div>
            <TextField label="Note" maxLength={255} value={stock.note} onChange={(x) => setStock({ ...stock, note: x })} />
          </div>
        </Ask>
      )}
      {task && (
        <Ask title={task.id ? 'Change the task' : 'New task'} go="Save" width={600} ready={task.v.name.trim() !== ''} onClose={() => setTask(null)}
          run={() => api.saveProjectTask(d.id!, task.id, { ...task.v, estimate_hours: task.v.estimate_hours || null, sort: Number(task.v.sort) || 0 }).then(after)}>
          <div className={f.grid}>
            <TextField label="Name" required className={f.spanAll} maxLength={255} value={task.v.name} onChange={(x) => setTask({ ...task, v: { ...task.v, name: x } })} />
            <Choose label="Who does it" options={d.workers ?? []} value={task.v.assigned_to} onChange={(x) => setTask({ ...task, v: { ...task.v, assigned_to: x } })} clearable />
            <Choose label="Status" required options={d.task_statuses ?? []} value={task.v.status} onChange={(x) => setTask({ ...task, v: { ...task.v, status: x } })} />
            <TextField label="Due" type="date" value={task.v.due_on} onChange={(x) => setTask({ ...task, v: { ...task.v, due_on: x } })} />
            <TextField label="Hours planned" type="number" min={0} step="any" value={task.v.estimate_hours} onChange={(x) => setTask({ ...task, v: { ...task.v, estimate_hours: x } })} />
            <TextField label="Order" type="number" value={task.v.sort} onChange={(x) => setTask({ ...task, v: { ...task.v, sort: x } })} />
            {d.billed && <Toggle label="Time on it is billed" value={task.v.billable} onChange={(x) => setTask({ ...task, v: { ...task.v, billable: x } })} />}
          </div>
        </Ask>
      )}
      {mile && (
        <Ask title={mile.id ? 'Change the milestone' : 'New milestone'} go="Save" width={560} ready={mile.v.name.trim() !== '' && Number(mile.v.amount) > 0} onClose={() => setMile(null)}
          run={() => api.saveMilestone(d.id!, mile.id, { ...mile.v, sort: Number(mile.v.sort) || 0 }).then(after)}>
          <div className={f.grid}>
            <TextField label="Name" required className={f.spanAll} maxLength={255} placeholder="e.g. Deposit, Delivery, Handover" value={mile.v.name} onChange={(x) => setMile({ ...mile, v: { ...mile.v, name: x } })} />
            <TextField label="Amount (before tax)" required type="number" min={0.01} step="any" prefix={d.currency} value={mile.v.amount} onChange={(x) => setMile({ ...mile, v: { ...mile.v, amount: x } })} />
            <TextField label="Expected" type="date" value={mile.v.due_on} onChange={(x) => setMile({ ...mile, v: { ...mile.v, due_on: x } })} />
            <Choose label="Status" required options={[{ id: 'pending', label: 'Not yet' }, { id: 'ready', label: 'Ready to invoice' }]} value={mile.v.status} onChange={(x) => setMile({ ...mile, v: { ...mile.v, status: x } })} />
            <TextField label="Order" type="number" value={mile.v.sort} onChange={(x) => setMile({ ...mile, v: { ...mile.v, sort: x } })} />
          </div>
        </Ask>
      )}
      {billMile && (
        <Ask title="Invoice this milestone?" go="Invoice" ready onClose={() => setBillMile(null)} run={() => api.invoiceMilestone(d.id!, billMile).then(after)}>
          <Text>An invoice for this milestone goes to the customer's account; the project's costs so far move from work in progress to project costs.</Text>
        </Ask>
      )}
    </div>
  )
}
