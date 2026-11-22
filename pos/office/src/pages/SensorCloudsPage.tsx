import { Button, MessageBar, MessageBarBody, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowSyncRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type CloudList, type CloudValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, Toggle } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useList } from '../list/useList'
import { useProcStyles } from './ProcurementParts'

type Row = CloudList['rows'][number]
const blank: CloudValues = { provider: 'sensorpush', name: '', region: 'eu', is_active: true, email: '', client_id: '', password: '', secret: '' }

/**
 * Sensors → Sensor clouds (part 32): accounts for sensors that report to their maker's cloud instead of to ZionEX; their
 * readings are fetched every 5 minutes. Add, change (a password or secret left empty keeps the saved one — they are never
 * shown again) and Read now.
 */
export function SensorCloudsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.sensorClouds, '', refresh)
  const [edit, setEdit] = useState<{ id: string | null; v: CloudValues } | null>(null)
  const [done, setDone] = useState('')
  const [fail, setFail] = useState('')
  const [reading, setReading] = useState<string | null>(null)
  const put = <K extends keyof CloudValues>(k: K) => (x: CloudValues[K]) => setEdit((o) => (o ? { ...o, v: { ...o.v, [k]: x } } : o))
  const read = (r: Row) => {
    setReading(r.id); setDone(''); setFail('')
    api.readCloud(r.id).then((x) => setDone(x.message)).catch((e: unknown) => setFail(e instanceof Error ? e.message : String(e)))
      .finally(() => { setReading(null); setRefresh((n) => n + 1) })
  }
  const sp = edit?.v.provider === 'sensorpush'
  const ready = !!edit && (sp ? edit.v.email.trim() !== '' && (!!edit.id || edit.v.password !== '') : edit.v.client_id.trim() !== '' && (!!edit.id || edit.v.secret !== '') && !!edit.v.region)
  return (
    <div className={page.page}>
      <PageHead title="Sensor clouds" classic="/admin/iot-clouds"
        hint="For sensors that report to their maker’s cloud instead of to ZionEX: their readings are fetched every 5 minutes. Most sensors can send to ZionEX directly (see a sensor’s “Sensor address”)." />
      <div className={s.bar}>
        <span className={s.grow} />
        <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setEdit({ id: null, v: { ...blank } }) }}>Add a sensor cloud</Button>
      </div>
      {(error || fail) && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error || fail}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Sensor clouds" rows={data?.rows ?? []} sort="name" dir="asc" loading={loading} empty="No sensor clouds." onSort={() => undefined}
        onOpen={(r) => setEdit({ id: r.id, v: { ...r.values } })}
        columns={[
          { key: 'name', label: 'Name', render: (r) => <><Text weight="semibold">{r.name}</Text><Text size={200} block className={s.muted}>{r.provider}</Text></> },
          { key: 'sensors', label: 'Sensors', end: true, render: (r) => r.sensors },
          { key: 'last', label: 'Last read', render: (r) => r.last ?? 'Not yet' },
          { key: 'problem', label: 'Problem', render: (r) => (r.problem ? <span style={{ color: tokens.colorPaletteRedForeground1 }}>{r.problem}</span> : '—') },
          { key: 'active', label: 'In use', render: (r) => (r.active ? 'Yes' : 'No') },
          { key: 'act', label: '', render: (r) => <div className={s.actions}>
            <Button size="small" onClick={(e) => { e.stopPropagation(); setEdit({ id: r.id, v: { ...r.values } }) }}>Change</Button>
            <Button size="small" icon={<ArrowSyncRegular />} disabled={reading === r.id} onClick={(e) => { e.stopPropagation(); read(r) }}>{reading === r.id ? 'Reading…' : 'Read now'}</Button>
          </div> },
        ]} />
      {edit && data && (
        <Ask title={edit.id ? `Change ${edit.v.name}` : 'Add a sensor cloud'} go="Save" width={560} ready={ready} onClose={() => setEdit(null)}
          run={() => api.saveCloud(edit.id, edit.v).then((x) => { setEdit(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <div className={s.one}>
            <Choose label="Cloud" required options={data.providers} value={edit.v.provider} onChange={put('provider')} />
            <TextField label="Name (for you)" maxLength={255} value={edit.v.name} onChange={put('name')} />
            {sp && <TextField label="SensorPush account e-mail" required maxLength={255} value={edit.v.email} onChange={put('email')} />}
            {sp && <TextField label="SensorPush password" required={!edit.id} type="password" maxLength={255} value={edit.v.password} onChange={put('password')}
              hint={edit.id ? 'Leave empty to keep the one saved. The account needs the SensorPush gateway.' : 'The account needs the SensorPush gateway.'} />}
            {!sp && <TextField label="Tuya Access ID" required maxLength={255} value={edit.v.client_id} onChange={put('client_id')} />}
            {!sp && <TextField label="Tuya Access Secret" required={!edit.id} type="password" maxLength={255} value={edit.v.secret} onChange={put('secret')}
              hint={`From a cloud project at iot.tuya.com, with the Smart Life app account linked.${edit.id ? ' Leave empty to keep the one saved.' : ''}`} />}
            {!sp && <Choose label="Tuya data centre" required options={data.regions} value={edit.v.region} onChange={put('region')} />}
            <Toggle label="In use" value={edit.v.is_active} onChange={put('is_active')} />
          </div>
        </Ask>
      )}
    </div>
  )
}
