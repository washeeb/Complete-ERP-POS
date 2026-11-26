import { Button, MessageBar, MessageBarBody, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type StationList, type StationValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, Toggle } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'
import { ArchiveMenu } from './RestaurantParts'

type Row = StationList['rows'][number]

/**
 * Restaurant → Kitchen stations (part 30): where dishes and drinks are prepared; each has a kitchen screen, or prints its
 * tickets. The code is made from the name (Kitchen → KITCHEN) and products name their station by it. New, change,
 * archive and restore.
 */
export function KitchenStationsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.restStations, serialize(params), refresh)
  const [edit, setEdit] = useState<{ id: string | null; code: string | null; v: StationValues } | null>(null)
  const [done, setDone] = useState('')
  const finish = (m: string) => { setEdit(null); setDone(m); setRefresh((n) => n + 1) }
  return (
    <div className={page.page}>
      <PageHead title="Kitchen stations" hint="Where dishes and drinks are prepared; each has a kitchen screen, or prints its tickets." />
      <div className={s.bar}>
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />}
          onClick={() => { setDone(''); setEdit({ id: null, code: null, v: { branch_id: data.branches[0]?.id ?? '', name: '', auto_print: false } }) }}>New kitchen station</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Kitchen stations" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No kitchen stations yet." onSort={() => undefined}
        onOpen={(r) => r.can_edit && !r.archived && setEdit({ id: r.id, code: r.code, v: { ...r.values } })}
        columns={[
          { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
          { key: 'code', label: 'Code', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
          { key: 'name', label: 'Name', render: (r) => <Text weight="semibold">{r.name}</Text> },
          { key: 'print', label: 'Prints tickets', render: (r) => (r.auto_print ? 'Yes' : 'No') },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'more', label: '', render: (r) => <ArchiveMenu kind="station" row={r} name={r.name} onDone={finish} /> },
        ]} />
      {edit && data && (
        <Ask title={edit.id ? `Change ${edit.v.name}` : 'New kitchen station'} go="Save" onClose={() => setEdit(null)} ready={!!edit.v.branch_id && edit.v.name.trim() !== ''}
          run={() => api.saveStation(edit.id, edit.v).then((x) => finish(x.message))}>
          <div className={s.one}>
            <Choose label="Branch" required disabled={!!edit.id} options={data.branches} value={edit.v.branch_id} onChange={(x) => setEdit({ ...edit, v: { ...edit.v, branch_id: x } })} />
            <TextField label="Name" required maxLength={255} value={edit.v.name} onChange={(x) => setEdit({ ...edit, v: { ...edit.v, name: x } })} />
            <Text size={200} className={s.muted}>Code: {edit.code ?? 'given automatically when saved'} — made from the name (Kitchen → KITCHEN). Products name their station by this code.</Text>
            <Toggle label="Print tickets (no screen)" hint="The kitchen device at this station prints each new ticket instead of only showing it."
              value={edit.v.auto_print} onChange={(x) => setEdit({ ...edit, v: { ...edit.v, auto_print: x } })} />
          </div>
        </Ask>
      )}
    </div>
  )
}
