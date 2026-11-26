import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Text } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type DepartmentList } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'

type Row = DepartmentList['rows'][number]

/**
 * People → Employees → Departments (part 38): the departments employees, costs and purchases are put under. New
 * department (its code is made from the name once and never changes), rename, archive and restore.
 */
export function DepartmentsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.departments, serialize(params), refresh)
  const [edit, setEdit] = useState<{ id: string | null; name: string } | null>(null)
  const [ask, setAsk] = useState<{ row: Row; restore: boolean } | null>(null)
  const [why, setWhy] = useState('')
  const [done, setDone] = useState('')
  const finish = (x: { message: string }) => { setEdit(null); setAsk(null); setDone(x.message); setRefresh((n) => n + 1) }
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => navigate('/office/employees')}>Employees</Button>
      <PageHead title="Departments" hint="Employees, costs and purchases are put under a department, so each one's spending can be followed." classic="/admin/departments" />
      <div className={s.bar}>
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setEdit({ id: null, name: '' }) }}>New department</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Departments" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No departments yet." onSort={() => undefined}
        onOpen={(r) => r.can_edit && setEdit({ id: r.id, name: r.name })}
        columns={[
          { key: 'code', label: 'Code', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
          { key: 'name', label: 'Department', render: (r) => <Text weight="semibold">{r.name}</Text> },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'act', label: '', render: (r) => (r.can_archive || r.can_restore) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Act on ${r.name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_archive && <MenuItem onClick={() => { setWhy(''); setDone(''); setAsk({ row: r, restore: false }) }}>Archive</MenuItem>}
                {r.can_restore && <MenuItem onClick={() => { setDone(''); setAsk({ row: r, restore: true }) }}>Restore</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />

      {edit && (
        <Ask title={edit.id ? 'Rename department' : 'New department'} go="Save" ready={edit.name.trim() !== ''} onClose={() => setEdit(null)}
          run={() => api.saveDepartment(edit.id, edit.name).then(finish)}>
          <div className={s.one}>
            {!edit.id && <Text>Its code is made from the name when saved, and never changes.</Text>}
            <TextField label="Name" required maxLength={255} value={edit.name} onChange={(x) => setEdit({ ...edit, name: x })} />
          </div>
        </Ask>
      )}
      {ask && !ask.restore && (
        <Ask title={`Archive ${ask.row.name}?`} go="Archive" ready={why.trim().length >= 3 && ask.row.archive_blockers.length === 0} onClose={() => setAsk(null)}
          run={() => api.departmentArchive(ask.row.id, false, why).then(finish)}>
          <div className={s.one}>
            {ask.row.archive_blockers.length > 0 && <Text>It cannot be archived yet. {ask.row.archive_blockers.join(' ')}</Text>}
            <Text>It leaves the lists people choose from. What was put under it keeps it.</Text>
            <TextField label="Why" required maxLength={255} value={why} onChange={setWhy} />
          </div>
        </Ask>
      )}
      {ask?.restore && (
        <Ask title={`Restore ${ask.row.name}?`} go="Restore" ready onClose={() => setAsk(null)} run={() => api.departmentArchive(ask.row.id, true, '').then(finish)}>
          <Text>It comes back into the lists.</Text>
        </Ask>
      )}
    </div>
  )
}
