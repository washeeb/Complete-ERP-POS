import { Button, MessageBar, MessageBarBody, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { SensorList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ShowFilter, Status } from './CompanyParts'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = SensorList['rows'][number]

/**
 * Sensors → Sensors (part 32): what each sensor reads now, refreshed every minute, with its range, state, last reading and
 * battery. New sensor; a row opens the sensor with its last 24 hours, alarms and checks.
 */
export function SensorsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [tick, setTick] = useState(0)
  const { data, loading, error } = useList(api.sensors, serialize(params), tick)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { const t = setInterval(() => setTick((n) => n + 1), 60000); return () => clearInterval(t) }, [])
  return (
    <div className={page.page}>
      <PageHead title="Sensors" hint="What each sensor reads now, refreshed every minute. Alarms go to the people on duty until someone says what was done." classic="/admin/sensors" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name or number" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search sensors" />
        <Pick label="Status" any="Any state" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <Pick label="Watches" any="Anything" options={data?.kinds ?? []} value={str(params, 'kind')} onChange={(v) => set({ kind: v })} />
        <Pick label="Branch" any="Every branch" options={data?.branches ?? []} value={str(params, 'branch')} onChange={(v) => set({ branch: v })} />
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/sensors/new')}>New sensor</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Sensors" rows={data?.rows ?? []} sort="name" dir="asc" loading={loading && !data}
        empty="No sensors yet. Add a sensor for each fridge, cold room, freezer or store room you want watched."
        onSort={() => undefined} onOpen={(r) => navigate(`/office/sensors/${r.id}`)}
        columns={[
          { key: 'name', label: 'Sensor', render: (r) => <><Text weight="semibold">{r.name}</Text><Text size={200} block className={s.muted}>{r.where}</Text></> },
          { key: 'reading', label: 'Now', render: (r) => <><Text weight="semibold">{r.reading}</Text><Text size={200} block className={s.muted}>Range {r.range}</Text></> },
          { key: 'status', label: 'State', render: (r) => <><ToneBadge tone={r.tone} label={r.status_label} />{r.archived && <Status r={r} />}</> },
          { key: 'last', label: 'Last reading', render: (r) => r.last ?? 'None yet' },
          { key: 'battery', label: 'Battery', render: (r) => (r.battery === null ? '—'
            : <span style={r.battery < 20 ? { color: tokens.colorPaletteRedForeground1 } : undefined}>{r.battery} %</span>) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
