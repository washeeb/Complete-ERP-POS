import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Text } from '@fluentui/react-components'
import { AddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api } from '../api'
import type { CrmStageList } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ShowFilter, Status } from './CompanyParts'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = CrmStageList['rows'][number]
const TONE: Record<string, string> = { won: 'success', lost: 'subtle', open: 'informative' }

/**
 * CRM → Pipeline stages (part 25): the steps a deal goes through, each with its chance of winning (for the weighted value
 * of the pipeline); one Won and one Lost. Add, change, archive (when no deal sits in it) and restore.
 */
export function CrmStagesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.crmStages, serialize(params), refresh)
  const [edit, setEdit] = useState<{ row: Row | null; name: string; kind: string; probability: string; sort: string } | null>(null)
  const [arch, setArch] = useState<{ row: Row; restore: boolean } | null>(null)
  const [why, setWhy] = useState('')
  const [done, setDone] = useState('')
  const finish = (m: string) => { setEdit(null); setArch(null); setDone(m); setRefresh((n) => n + 1) }
  const open = (r: Row | null) => { setDone(''); setEdit({ row: r, name: r?.name ?? '', kind: r?.kind ?? 'open', probability: String(r?.probability ?? 10), sort: r ? String(r.sort) : '' }) }
  return (
    <div className={page.page}>
      <PageHead title="Pipeline stages" hint="The steps a deal goes through, each with its chance of winning; one Won and one Lost." />
      <div className={s.bar}>
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        <Button appearance="primary" icon={<AddRegular />} onClick={() => open(null)}>New stage</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Pipeline stages" rows={data?.rows ?? []} sort="sort" dir="asc" loading={loading} empty="No stages." onSort={() => undefined} onOpen={(r) => open(r)}
        columns={[
          { key: 'sort', label: 'Order', render: (r) => <Text className={s.muted}>{r.sort}</Text> },
          { key: 'name', label: 'Name', render: (r) => <Text weight="semibold">{r.name}</Text> },
          { key: 'kind', label: 'Kind', render: (r) => <ToneBadge tone={TONE[r.kind] ?? 'informative'} label={r.kind_label} /> },
          { key: 'chance', label: 'Chance', end: true, render: (r) => `${r.probability} %` },
          { key: 'deals', label: 'Open deals', end: true, render: (r) => r.deals },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'more', label: '', render: (r) => (r.can_archive || r.can_restore) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${r.name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_archive && <MenuItem onClick={() => { setWhy(''); setArch({ row: r, restore: false }) }}>Archive</MenuItem>}
                {r.can_restore && <MenuItem onClick={() => setArch({ row: r, restore: true })}>Restore</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />

      {edit && data && (
        <Ask title={edit.row ? `Change ${edit.row.name}` : 'New stage'} go="Save" ready={edit.name.trim() !== ''} onClose={() => setEdit(null)}
          run={() => api.saveCrmStage(edit.row?.id ?? null, { name: edit.name, kind: edit.kind, probability: edit.kind === 'open' ? Number(edit.probability || 0) : null,
            sort: edit.sort === '' ? null : Number(edit.sort) }).then((x) => finish(x.message))}>
          <div className={s.one}>
            <TextField label="Name" required maxLength={60} value={edit.name} onChange={(x) => setEdit({ ...edit, name: x })} />
            <Choose label="Kind" required options={data.kinds} value={edit.kind} onChange={(x) => setEdit({ ...edit, kind: x })} />
            {edit.kind === 'open' && <TextField label="Chance of winning (%)" type="number" min={0} max={100} hint="Used for the weighted value of the pipeline." value={edit.probability}
              onChange={(x) => setEdit({ ...edit, probability: x })} />}
            <TextField label="Order" type="number" hint="Stages show from the lowest number." value={edit.sort} onChange={(x) => setEdit({ ...edit, sort: x })} />
          </div>
        </Ask>
      )}
      {arch && (
        <Ask title={`${arch.restore ? 'Restore' : 'Archive'} ${arch.row.name}?`} go={arch.restore ? 'Restore' : 'Archive'}
          ready={arch.restore || (arch.row.archive_blockers.length === 0 && why.trim().length >= 3)} onClose={() => setArch(null)}
          run={() => api.archiveCrmStage(arch.row.id, arch.restore, why).then((x) => finish(x.message))}>
          {arch.restore ? <Text>It comes back into the lists people choose from.</Text>
            : arch.row.archive_blockers.length ? <Text>It cannot be archived yet. {arch.row.archive_blockers.join(' ')}</Text>
              : <div className={s.one}><Text>It leaves the pipeline. Deals closed in it keep showing it.</Text><TextField label="Why" required maxLength={255} value={why} onChange={setWhy} /></div>}
        </Ask>
      )}
    </div>
  )
}
