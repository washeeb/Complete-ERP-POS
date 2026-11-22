import { Button, MessageBar, MessageBarBody, Text } from '@fluentui/react-components'
import { useState } from 'react'
import { api, type ServiceLevelData } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useList } from '../list/useList'
import { useProcStyles } from './ProcurementParts'

type Row = ServiceLevelData['rows'][number]

/**
 * Service desk → Deadlines (part 28): time to answer and to fix a ticket, by its priority, counted around the clock; the
 * clock stops while waiting for the customer. The person handling it is warned when 75 % of the time is gone, managers
 * when it is missed.
 */
export function ServiceLevelsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.serviceLevels, '', refresh)
  const [edit, setEdit] = useState<{ row: Row; respond: string; resolve: string } | null>(null)
  const [done, setDone] = useState('')
  return (
    <div className={page.page}>
      <PageHead title="Deadlines" classic="/admin/service-levels"
        hint="Time to answer and to fix a ticket, by its priority, counted around the clock; the clock stops while you wait for the customer. The person handling it is warned when 75 % of the time is gone, managers when it is missed." />
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Deadlines" rows={data?.rows ?? []} sort="priority" dir="asc" loading={loading} empty="No deadlines." onSort={() => undefined}
        onOpen={(r) => { setDone(''); setEdit({ row: r, ...r.values }) }}
        columns={[
          { key: 'priority', label: 'Priority', render: (r) => <Text weight="semibold">{r.priority}</Text> },
          { key: 'respond', label: 'Answer within', render: (r) => r.respond },
          { key: 'resolve', label: 'Fix within', render: (r) => r.resolve },
          { key: 'act', label: '', render: (r) => <Button size="small" onClick={(e) => { e.stopPropagation(); setDone(''); setEdit({ row: r, ...r.values }) }}>Change</Button> },
        ]} />
      {edit && (
        <Ask title={`${edit.row.priority} tickets`} go="Save" ready={Number(edit.respond) >= 0.25 && Number(edit.resolve) >= 0.25} onClose={() => setEdit(null)}
          run={() => api.saveServiceLevel(edit.row.id, { respond: edit.respond, resolve: edit.resolve }).then((x) => { setEdit(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <div className={s.one}>
            <TextField label="Answer within (hours)" required type="number" min={0.25} step="any" value={edit.respond} onChange={(x) => setEdit({ ...edit, respond: x })} />
            <TextField label="Fix within (hours)" required type="number" min={0.25} step="any" hint="e.g. 72 for three days" value={edit.resolve} onChange={(x) => setEdit({ ...edit, resolve: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}
