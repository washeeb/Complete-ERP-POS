import { Dropdown, MessageBar, MessageBarBody, Option } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { Pager } from '../list/DataTable'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Panel, useProcStyles } from './ProcurementParts'
import { AlarmTable } from './SensorParts'

const OPEN = [{ id: '1', label: 'Not closed' }, { id: '0', label: 'Closed' }, { id: 'all', label: 'All' }]

/**
 * Sensors → Alarms (part 32): every alarm, not closed by default, refreshed every minute. People on duty are told at once
 * and every 15 minutes until someone acknowledges with what was done; managers after 30 minutes. An alarm closes when it
 * is acknowledged and the reading is back in range.
 */
export function SensorAlarmsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [tick, setTick] = useState(0)
  const { data, error } = useList(api.sensorAlarms, serialize(params), tick)
  const [done, setDone] = useState('')
  useEffect(() => { const t = setInterval(() => setTick((n) => n + 1), 60000); return () => clearInterval(t) }, [])
  const open = data?.open ?? (str(params, 'open') || '1')
  return (
    <div className={page.page}>
      <PageHead title="Sensor alarms" classic="/admin/iot-alarms"
        hint="People on duty are told at once and every 15 minutes until someone acknowledges with what was done; managers after 30 minutes. An alarm closes when it is acknowledged and the reading is back in range." />
      <div className={s.bar}>
        <Dropdown aria-label="Closed or not" value={OPEN.find((x) => x.id === open)?.label ?? 'Not closed'} selectedOptions={[open]} style={{ minWidth: 140 }}
          onOptionSelect={(_, d) => set({ open: d.optionValue ?? '1' })}>
          {OPEN.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Alarms">
        {data && <AlarmTable rows={data.rows} withSensor actions={data.actions} onDone={(m) => { setDone(m); setTick((n) => n + 1) }} />}
      </Panel>
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
