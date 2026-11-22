import { Button, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type TableList, type TableValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, useFieldStyles } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ShowFilter, Status } from './CompanyParts'
import { Pick } from './CrmLeadsPage'
import { useProcStyles } from './ProcurementParts'
import { ArchiveMenu } from './RestaurantParts'

type Row = TableList['rows'][number]

/**
 * Restaurant → Floor plan (part 30): the tables per branch, with their area, seats and place on the till's floor view.
 * New table (the next number is suggested), change, archive and restore — a table with an open order cannot be archived.
 */
export function FloorPlanPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.restTables, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [edit, setEdit] = useState<{ id: string | null; v: TableValues } | null>(null)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (m: string) => { setEdit(null); setDone(m); setRefresh((n) => n + 1) }
  const put = <K extends keyof TableValues>(k: K) => (x: TableValues[K]) => setEdit((o) => (o ? { ...o, v: { ...o.v, [k]: x } } : o))
  const startNew = () => {
    if (!data) return
    const b = str(params, 'branch') || data.branches[0]?.id || ''
    setDone('')
    setEdit({ id: null, v: { branch_id: b, code: data.next[b] ?? '1', area: '', seats: '4', pos_x: '0', pos_y: '0' } })
  }
  return (
    <div className={page.page}>
      <PageHead title="Floor plan" hint="Tables per branch, with their area, seats and place on the till's floor view." classic="/admin/dining-tables" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Table" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search tables" />
        <Pick label="Branch" any="Every branch" options={data?.branches ?? []} value={str(params, 'branch')} onChange={(v) => set({ branch: v })} />
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={startNew}>New table</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Floor plan" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No tables yet." onSort={() => undefined}
        onOpen={(r) => r.can_edit && !r.archived && setEdit({ id: r.id, v: { ...r.values } })}
        columns={[
          { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
          { key: 'code', label: 'Table', render: (r) => <Text weight="semibold">{r.code}</Text> },
          { key: 'area', label: 'Area', render: (r) => r.area ?? '—' },
          { key: 'seats', label: 'Seats', end: true, render: (r) => r.seats },
          { key: 'position', label: 'Floor view', render: (r) => r.position },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'more', label: '', render: (r) => <ArchiveMenu kind="table" row={r} name={`table ${r.code}`} onDone={finish} /> },
        ]} />
      {edit && data && (
        <Ask title={edit.id ? `Change table ${edit.v.code}` : 'New table'} go="Save" width={600} onClose={() => setEdit(null)}
          ready={!!edit.v.branch_id && edit.v.code.trim() !== '' && Number(edit.v.seats) >= 1}
          run={() => api.saveTable(edit.id, { ...edit.v, seats: Number(edit.v.seats), pos_x: Number(edit.v.pos_x) || 0, pos_y: Number(edit.v.pos_y) || 0 }).then((x) => finish(x.message))}>
          <div className={f.grid}>
            <Choose label="Branch" required disabled={!!edit.id} options={data.branches} value={edit.v.branch_id}
              onChange={(b) => setEdit({ ...edit, v: { ...edit.v, branch_id: b, code: edit.id ? edit.v.code : data.next[b] ?? edit.v.code } })} />
            <TextField label="Table" required maxLength={20} hint="The number on the table. The next one is suggested." value={edit.v.code} onChange={put('code')} />
            <TextField label="Area" maxLength={60} placeholder="Inside, Terrace, Bar…" value={edit.v.area} onChange={put('area')} />
            <TextField label="Seats" required type="number" min={1} max={60} value={edit.v.seats} onChange={put('seats')} />
            <TextField label="Column on the floor view" type="number" min={0} max={99} value={edit.v.pos_x} onChange={put('pos_x')} />
            <TextField label="Row on the floor view" type="number" min={0} max={99} value={edit.v.pos_y} onChange={put('pos_y')} />
          </div>
        </Ask>
      )}
    </div>
  )
}
