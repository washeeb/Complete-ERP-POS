import { Button, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArchiveRegular, ArrowLeftRegular, ArrowUndoRegular, ClockRegular, SaveRegular, WarningRegular, WrenchRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type EquipmentDetail, type PlanValuesM } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, Tags, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { day, END, Panel, ToneBadge, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

const OWNERS = [{ id: 'own', label: 'Ours' }, { id: 'customer', label: 'A customer’s (sold or serviced by us)' }]
const METERS = [{ id: 'hours', label: 'Running hours' }, { id: 'km', label: 'Kilometres' }]
const blankPlan: PlanValuesM = { name: '', every_days: '', every_meter: '', last_done_on: '', lead_days: '7', assigned_to: '', is_active: true, checklist: [] }

/**
 * One piece of equipment (part 31), as the current form: whose it is, where, make, model and serial, where it was bought,
 * warranty and service contract, its line in the books and its meter. Its servicing plans (a work order opens by itself
 * when one is due) and its work orders with cost and downtime. Report a breakdown, New meter reading, New work order,
 * archive and restore.
 */
export function AssetPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<EquipmentDetail | null>(null)
  const [v, setV] = useState<EquipmentDetail['values'] | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)
  const [ask, setAsk] = useState<'breakdown' | 'meter' | 'archive' | 'restore' | null>(null)
  const [form, setForm] = useState({ title: '', details: '', reading: '', why: '' })
  const [plan, setPlan] = useState<{ id: string | null; v: PlanValuesM } | null>(null)
  const load = useCallback(() => {
    api.asset(id).then((x) => { setD(x); setV(x.values); onTitle(x.id ? `${x.number} · ${x.values.name}` : 'New equipment') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the equipment…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  const put = <K extends keyof EquipmentDetail['values']>(k: K) => (x: EquipmentDetail['values'][K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const a = d.actions
  const edit = a.edit
  const own = v.owner === 'own'
  const back = () => (history.length > 1 ? history.back() : navigate('/office/maintenance/equipment'))
  const fail = (e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const after = (x: { message: string; open?: string }) => {
    if (x.open) { navigate(x.open); return }
    setAsk(null); setPlan(null); setError(''); setDone(x.message); load()
  }
  const save = () => {
    setBusy(true)
    setError('')
    const { customer_name: _c, ...body } = v
    api.saveAsset(d.id, body).then((x) => { if (d.id) after(x); else navigate(`/office/maintenance/equipment/${x.id}`, true) }).catch(fail).finally(() => setBusy(false))
  }
  const ready = v.name.trim() !== '' && (own ? !!v.branch_id : !!v.customer_id)
  const putPlan = <K extends keyof PlanValuesM>(k: K) => (x: PlanValuesM[K]) => setPlan((o) => (o ? { ...o, v: { ...o.v, [k]: x } } : o))
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Equipment</Button>
      <div className={s.head}>
        <h1 className={s.title}>{d.id && <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>}{d.id ? d.values.name : 'New equipment'}
          {d.id && d.status_label && <ToneBadge tone={d.tone ?? 'subtle'} label={d.status_label} />}</h1>
        {d.id && <div className={s.actions}>
          {a.breakdown && <Button icon={<WarningRegular />} onClick={() => { setForm({ ...form, title: '', details: '' }); setAsk('breakdown') }}>Report a breakdown</Button>}
          {a.meter && <Button icon={<ClockRegular />} onClick={() => { setForm({ ...form, reading: '' }); setAsk('meter') }}>New meter reading</Button>}
          {a.breakdown && <Button icon={<WrenchRegular />} onClick={() => navigate(`/office/maintenance/orders/new?asset=${d.id}`)}>New work order</Button>}
          {d.archive?.can_archive && <Button icon={<ArchiveRegular />} onClick={() => { setForm({ ...form, why: '' }); setAsk('archive') }}>Archive</Button>}
          {d.archive?.can_restore && <Button icon={<ArrowUndoRegular />} onClick={() => setAsk('restore')}>Restore</Button>}
        </div>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {d.archive?.archived && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>{d.archive.archived_note}</MessageBarBody></MessageBar>}
      {d.meter && <Text block style={{ marginBottom: 10 }}>Meter now: <b>{d.meter}</b></Text>}

      <Panel title="Equipment">
        <div className={f.grid}>
          <TextField label="Name" required className={f.spanAll} maxLength={255} placeholder="e.g. Generator 20 kVA, back yard" disabled={!edit} value={v.name} onChange={put('name')} />
          {d.id && <Choose label="Status" required disabled={!edit} options={d.statuses} value={v.status} onChange={put('status')} />}
          <Choose label="Whose" required disabled={!edit} options={OWNERS} value={v.owner} onChange={put('owner')} />
          <Choose label="Category" required disabled={!edit} options={d.categories} value={v.category} onChange={put('category')} />
          <Choose label="Branch" required={own} disabled={!edit} options={d.branches} value={v.branch_id} onChange={put('branch_id')} clearable={!own} />
          <TextField label="Location" maxLength={255} placeholder="e.g. Store room, Ward A" disabled={!edit} value={v.location} onChange={put('location')} />
          {!own && <SearchPicker label="Customer" required value={v.customer_id} text={v.customer_name} search={api.maintCustomers} placeholder="Type a name or phone"
            onChange={(x) => setV({ ...v, customer_id: x?.id ?? '', customer_name: x?.label ?? null })} />}
          <TextField label="Make" maxLength={80} disabled={!edit} value={v.make} onChange={put('make')} />
          <TextField label="Model" maxLength={80} disabled={!edit} value={v.model} onChange={put('model')} />
          <TextField label="Serial number" maxLength={80} disabled={!edit} value={v.serial_no} onChange={put('serial_no')} />
          <Choose label="Bought from" disabled={!edit} options={d.suppliers} value={v.supplier_id} onChange={put('supplier_id')} clearable />
          <TextField label="Bought / installed on" type="date" disabled={!edit} value={v.acquired_on} onChange={put('acquired_on')} />
          <TextField label="Warranty until" type="date" disabled={!edit} value={v.warranty_until} onChange={put('warranty_until')} />
          {!own && <TextField label="Service contract until" type="date" disabled={!edit} value={v.contract_until} onChange={put('contract_until')} />}
          {own && <Choose label="In the books as" disabled={!edit} options={d.fixed_assets} value={v.fixed_asset_id} onChange={put('fixed_asset_id')} clearable />}
          <Choose label="Meter" disabled={!edit} options={METERS} value={v.meter_unit} onChange={put('meter_unit')} placeholder="No meter" clearable />
          <LongText label="Notes" className={f.spanAll} maxLength={5000} value={v.notes} onChange={put('notes')} />
        </div>
      </Panel>
      {edit && (
        <SaveBar value={v}>
          <Button onClick={back}>Cancel</Button>
          <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : d.id ? 'Save' : 'Add equipment'}</Button>
        </SaveBar>
      )}

      {d.id && (
        <Panel title="Servicing plans" about="Plan regular servicing: a work order opens by itself when it is due."
          actions={a.plans && <Button icon={<AddRegular />} onClick={() => setPlan({ id: null, v: { ...blankPlan } })}>New plan</Button>}>
          {(d.plans ?? []).length === 0 ? <Text className={s.muted}>No servicing plans.</Text> : (
            <Table size="small" aria-label="Servicing plans" style={{ minWidth: 560 }}>
              <TableHeader><TableRow><TableHeaderCell>Servicing</TableHeaderCell><TableHeaderCell>How often</TableHeaderCell><TableHeaderCell>Last done</TableHeaderCell>
                <TableHeaderCell>Next due</TableHeaderCell><TableHeaderCell>Who</TableHeaderCell><TableHeaderCell>In use</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
              <TableBody>{(d.plans ?? []).map((p) => (
                <TableRow key={p.id}><TableCell><Text weight="semibold">{p.name}</Text></TableCell><TableCell>{p.every}</TableCell><TableCell>{day(p.last)}</TableCell>
                  <TableCell><span style={p.late ? { color: tokens.colorPaletteRedForeground1 } : undefined}>{p.next ? day(p.next) : 'By the meter'}</span></TableCell>
                  <TableCell>{p.who ?? '—'}</TableCell><TableCell>{p.active ? 'Yes' : 'No'}</TableCell>
                  <TableCell>{a.plans && <Button size="small" onClick={() => setPlan({ id: p.id, v: { ...p.values, checklist: [...p.values.checklist] } })}>Change</Button>}</TableCell></TableRow>
              ))}</TableBody>
            </Table>
          )}
        </Panel>
      )}
      {d.id && (
        <Panel title="Work orders">
          {(d.orders ?? []).length === 0 ? <Text className={s.muted}>No work orders yet.</Text> : (
            <Table size="small" aria-label="Work orders" style={{ minWidth: 560 }}>
              <TableHeader><TableRow><TableHeaderCell>No.</TableHeaderCell><TableHeaderCell>Work order</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell>
                <TableHeaderCell button={END}>Cost</TableHeaderCell><TableHeaderCell>Down for</TableHeaderCell><TableHeaderCell>Done</TableHeaderCell></TableRow></TableHeader>
              <TableBody>{(d.orders ?? []).map((o) => (
                <TableRow key={o.id} style={{ cursor: 'pointer' }} onClick={() => navigate(`/office/maintenance/orders/${o.id}`)}>
                  <TableCell><Text font="monospace" size={200}>{o.number}</Text></TableCell>
                  <TableCell><Text weight="semibold">{o.title}</Text><Text size={200} block className={s.muted}>{o.kind}</Text></TableCell>
                  <TableCell><ToneBadge tone={o.tone} label={o.status_label} /></TableCell><TableCell className={s.end}>{m(o.cost)}</TableCell>
                  <TableCell>{o.down ?? '—'}</TableCell><TableCell>{day(o.done)}</TableCell></TableRow>
              ))}</TableBody>
            </Table>
          )}
        </Panel>
      )}

      {ask === 'breakdown' && (
        <Ask title="Report a breakdown" go="Open work order" ready={form.title.trim() !== ''} onClose={() => setAsk(null)}
          run={() => api.assetAction(d.id!, 'breakdown', { title: form.title, details: form.details }).then(after)}>
          <div className={s.one}>
            <Text>An urgent work order opens and the equipment is marked as not working.</Text>
            <TextField label="What is wrong" required maxLength={255} value={form.title} onChange={(x) => setForm({ ...form, title: x })} />
            <LongText label="Details" maxLength={5000} value={form.details} onChange={(x) => setForm({ ...form, details: x })} />
          </div>
        </Ask>
      )}
      {ask === 'meter' && (
        <Ask title="New meter reading" go="Save" ready={form.reading !== '' && Number(form.reading) >= 0} onClose={() => setAsk(null)}
          run={() => api.assetAction(d.id!, 'meter', { reading: form.reading }).then(after)}>
          <TextField label={`Reading now (${d.meter_unit === 'km' ? 'km' : 'hours'})`} required type="number" min={0} step="any" value={form.reading} onChange={(x) => setForm({ ...form, reading: x })} />
        </Ask>
      )}
      {ask === 'archive' && (
        <Ask title={`Archive ${d.values.name}?`} go="Archive" ready={form.why.trim().length >= 3 && (d.archive?.archive_blockers.length ?? 0) === 0} onClose={() => setAsk(null)}
          run={() => api.archiveAsset(d.id!, false, form.why).then((x) => { setAsk(null); setDone(x.message); navigate('/office/maintenance/equipment') })}>
          <div className={s.one}>
            {(d.archive?.archive_blockers.length ?? 0) > 0 && <Text>It cannot be archived yet. {d.archive!.archive_blockers.join(' ')}</Text>}
            <Text>It leaves the lists people choose from. Its work orders and history stay. It cannot be archived while a work order is open.</Text>
            <TextField label="Why" required maxLength={255} value={form.why} onChange={(x) => setForm({ ...form, why: x })} />
          </div>
        </Ask>
      )}
      {ask === 'restore' && (
        <Ask title={`Restore ${d.values.name}?`} go="Restore" ready onClose={() => setAsk(null)} run={() => api.archiveAsset(d.id!, true, '').then(after)}>
          <Text>It comes back into the lists people choose from.</Text>
        </Ask>
      )}
      {plan && (
        <Ask title={plan.id ? `Change ${plan.v.name}` : 'New servicing plan'} go="Save" width={640} onClose={() => setPlan(null)}
          ready={plan.v.name.trim() !== '' && (plan.v.every_days !== '' || plan.v.every_meter !== '')}
          run={() => api.saveMaintPlan(d.id!, plan.id, { ...plan.v, every_days: plan.v.every_days || null, every_meter: plan.v.every_meter || null,
            lead_days: plan.v.lead_days === '' ? null : Number(plan.v.lead_days) }).then(after)}>
          <div className={f.grid}>
            <TextField label="Servicing" required className={f.spanAll} maxLength={255} placeholder="e.g. Oil and filter change" value={plan.v.name} onChange={putPlan('name')} />
            <TextField label="Every … days" type="number" min={1} max={3650} value={plan.v.every_days} onChange={putPlan('every_days')} />
            {d.meter_unit && <TextField label={`Every … ${d.meter_unit === 'km' ? 'km' : 'running hours'}`} type="number" min={1} step="any" value={plan.v.every_meter} onChange={putPlan('every_meter')} />}
            <TextField label="Last done on" type="date" value={plan.v.last_done_on} onChange={putPlan('last_done_on')} />
            <TextField label="Open the work order … days early" type="number" min={0} max={60} value={plan.v.lead_days} onChange={putPlan('lead_days')} />
            <Choose label="Who does it" options={d.people ?? []} value={plan.v.assigned_to} onChange={putPlan('assigned_to')} clearable />
            <Toggle label="In use" value={plan.v.is_active} onChange={putPlan('is_active')} />
            <Tags label="Checklist" className={f.spanAll} placeholder="Add a step, then Enter" value={plan.v.checklist} onChange={putPlan('checklist')} />
          </div>
        </Ask>
      )}
    </div>
  )
}
