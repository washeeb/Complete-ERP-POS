import { Button, MessageBar, MessageBarBody, Text } from '@fluentui/react-components'
import { useState } from 'react'
import { api, type ProjectRateData } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { money } from '../home/format'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useList } from '../list/useList'
import { useProcStyles } from './ProcurementParts'

type Row = ProjectRateData['rows'][number]

/**
 * Projects → Hourly rates (part 27): what an hour of each person who logs time costs the company (pay and overheads) —
 * it values approved time on projects — and what it is billed at (else the project's own rate).
 */
export function ProjectRatesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.projectRates, '', refresh)
  const [edit, setEdit] = useState<{ row: Row; cost: string; bill: string } | null>(null)
  const [done, setDone] = useState('')
  const m = (x: number) => (data ? money(x, data.currency, data.exponent) : '')
  return (
    <div className={page.page}>
      <PageHead title="Hourly rates" hint="Cost per hour: pay plus overheads, divided by the hours a person works. It values approved time on projects." classic="/admin/project-rates" />
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Hourly rates" rows={data?.rows ?? []} sort="name" dir="asc" loading={loading}
        empty="Nobody logs time on projects yet. Give people the right “Log your own time on projects” (People → Roles)." onSort={() => undefined}
        onOpen={(r) => { setDone(''); setEdit({ row: r, ...r.values }) }}
        columns={[
          { key: 'name', label: 'Name', render: (r) => <Text weight="semibold">{r.name}</Text> },
          { key: 'cost', label: 'Costs per hour', end: true, render: (r) => (r.cost === null ? <Text className={s.muted}>Not set</Text> : m(r.cost)) },
          { key: 'bill', label: 'Billed per hour', end: true, render: (r) => (r.bill === null ? <Text className={s.muted}>Project's rate</Text> : m(r.bill)) },
          { key: 'act', label: '', render: (r) => <Button size="small" onClick={(e) => { e.stopPropagation(); setDone(''); setEdit({ row: r, ...r.values }) }}>Set rates</Button> },
        ]} />
      {edit && data && (
        <Ask title={`Rates for ${edit.row.name}`} go="Save" ready={edit.cost !== '' && Number(edit.cost) >= 0} onClose={() => setEdit(null)}
          run={() => api.saveProjectRate(edit.row.id, { cost: edit.cost, bill: edit.bill }).then((x) => { setEdit(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <div className={s.one}>
            <TextField label="Costs the company per hour" required type="number" min={0} step="any" prefix={data.currency} hint="e.g. 3,000 a month ÷ 176 hours ≈ 17"
              value={edit.cost} onChange={(x) => setEdit({ ...edit, cost: x })} />
            <TextField label="Billed per hour (empty: the project's rate)" type="number" min={0} step="any" prefix={data.currency} value={edit.bill} onChange={(x) => setEdit({ ...edit, bill: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}
