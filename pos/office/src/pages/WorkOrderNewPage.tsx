import { Button, Checkbox, MessageBar, MessageBarBody, Spinner } from '@fluentui/react-components'
import { ArrowLeftRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type WorkOrderForm, type WorkOrderValues } from '../api'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

/**
 * Maintenance → New work order (part 31), as the current form: the equipment (chosen already when it comes from the
 * equipment's page), kind, priority, what is to be done, by when, who does it, whether the equipment has stopped working,
 * and details.
 */
export function WorkOrderNewPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<WorkOrderForm | null>(null)
  const [v, setV] = useState<WorkOrderValues | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    api.workOrderForm(new URLSearchParams(location.search).get('asset') ?? '').then((x) => { setD(x); setV(x.values) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the form…" /></div>
  const put = <K extends keyof WorkOrderValues>(k: K) => (x: WorkOrderValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const back = () => (history.length > 1 ? history.back() : navigate('/office/maintenance/orders'))
  const save = () => {
    setBusy(true)
    setError('')
    api.openWorkOrder(v).then((x) => navigate(`/office/maintenance/orders/${x.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Work orders</Button>
      <h1 className={s.title} style={{ marginBottom: 8 }}>New work order</h1>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Panel title="Work order">
        <div className={f.grid}>
          <Choose label="Equipment" required options={d.assets} value={v.asset_id} onChange={put('asset_id')} />
          <Choose label="Kind" required options={d.kinds} value={v.kind} onChange={put('kind')} />
          <Choose label="Priority" required options={d.priorities} value={v.priority} onChange={put('priority')} />
          <TextField label="What is to be done" required className={f.spanAll} maxLength={255} value={v.title} onChange={put('title')} />
          <TextField label="By" type="date" value={v.due_on} onChange={put('due_on')} />
          <Choose label="Who does it" options={d.people} value={v.assigned_to} onChange={put('assigned_to')} clearable />
          <Checkbox label="The equipment is not working" checked={v.down} onChange={(_, e) => put('down')(!!e.checked)} />
          <LongText label="Details" className={f.spanAll} maxLength={5000} value={v.details} onChange={put('details')} />
        </div>
      </Panel>
      <SaveBar value={v}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !v.asset_id || v.title.trim() === ''} onClick={save}>{busy ? 'Opening…' : 'Open work order'}</Button>
      </SaveBar>
    </div>
  )
}
