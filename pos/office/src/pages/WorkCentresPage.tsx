import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Text } from '@fluentui/react-components'
import { AddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type WorkCentreList, type WorkCentreValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, useFieldStyles } from '../form/Fields'
import { money } from '../home/format'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'

type Row = WorkCentreList['rows'][number]

/**
 * Manufacturing → Work centres (part 35): the machines and teams operations run on, with their cost per hour (labour and
 * overhead) and the hours they run a day. New, change, archive and restore.
 */
export function WorkCentresPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.workCentres, serialize(params), refresh)
  const [edit, setEdit] = useState<{ id: string | null; v: WorkCentreValues } | null>(null)
  const [ask, setAsk] = useState<{ row: Row; restore: boolean } | null>(null)
  const [why, setWhy] = useState('')
  const [done, setDone] = useState('')
  const finish = (x: { message: string }) => { setEdit(null); setAsk(null); setDone(x.message); setRefresh((n) => n + 1) }
  const m = (x: number) => (data ? money(x, data.currency, data.exponent) : '')
  const put = <K extends keyof WorkCentreValues>(k: K) => (x: string) => setEdit((o) => (o ? { ...o, v: { ...o.v, [k]: x } } : o))
  return (
    <div className={page.page}>
      <PageHead title="Work centres" hint="Machines and teams that operations run on. Their cost per hour turns minutes into labour and overhead cost." />
      <div className={s.bar}>
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />}
          onClick={() => { setDone(''); setEdit({ id: null, v: { name: '', branch_id: data.branches.length === 1 ? data.branches[0].id : '', hourly_rate: '0', hours_per_day: '8' } }) }}>New work centre</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Work centres" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No work centres yet." onSort={() => undefined}
        onOpen={(r) => r.can_edit && !r.archived && setEdit({ id: r.id, v: { ...r.values } })}
        columns={[
          { key: 'code', label: 'Code', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
          { key: 'name', label: 'Work centre', render: (r) => <Text weight="semibold">{r.name}</Text> },
          { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
          { key: 'rate', label: 'Cost per hour', end: true, render: (r) => m(r.rate) },
          { key: 'hours', label: 'Hours a day', end: true, render: (r) => r.hours },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'act', label: '', render: (r) => (r.can_archive || r.can_restore) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Act on ${r.name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_archive && <MenuItem onClick={() => { setWhy(''); setAsk({ row: r, restore: false }) }}>Archive</MenuItem>}
                {r.can_restore && <MenuItem onClick={() => setAsk({ row: r, restore: true })}>Restore</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />

      {edit && data && (
        <Ask title={edit.id ? `Change ${edit.v.name}` : 'New work centre'} go="Save" width={560} onClose={() => setEdit(null)}
          ready={edit.v.name.trim() !== '' && !!edit.v.branch_id} run={() => api.saveWorkCentre(edit.id, edit.v).then(finish)}>
          <div className={f.grid}>
            <TextField label="Name" required maxLength={255} placeholder="Mixer" value={edit.v.name} onChange={put('name')} />
            <Choose label="Branch" required options={data.branches} value={edit.v.branch_id} onChange={put('branch_id')} />
            <TextField label="Cost per hour (labour + overhead)" type="number" min={0} step="any" prefix={data.currency} value={edit.v.hourly_rate} onChange={put('hourly_rate')} />
            <TextField label="Hours it runs a day" type="number" min={0} max={24} step="any" value={edit.v.hours_per_day} onChange={put('hours_per_day')} />
          </div>
        </Ask>
      )}
      {ask && !ask.restore && (
        <Ask title={`Archive ${ask.row.name}?`} go="Archive" ready={why.trim().length >= 3 && ask.row.archive_blockers.length === 0} onClose={() => setAsk(null)}
          run={() => api.workCentreArchive(ask.row.id, false, why).then(finish)}>
          <div className={s.one}>
            {ask.row.archive_blockers.length > 0 && <Text>It cannot be archived yet. {ask.row.archive_blockers.join(' ')}</Text>}
            <Text>It leaves the lists. Bills of materials and work orders that used it keep their history.</Text>
            <TextField label="Why" required maxLength={255} value={why} onChange={setWhy} />
          </div>
        </Ask>
      )}
      {ask?.restore && (
        <Ask title={`Restore ${ask.row.name}?`} go="Restore" ready onClose={() => setAsk(null)} run={() => api.workCentreArchive(ask.row.id, true, '').then(finish)}>
          <Text>It comes back into the lists.</Text>
        </Ask>
      )}
    </div>
  )
}
