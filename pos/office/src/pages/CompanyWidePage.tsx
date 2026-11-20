import { Badge, Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, Text } from '@fluentui/react-components'
import { BuildingShopRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type UserDone, type WideList, type WideRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose } from '../form/Fields'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { useReconfirm } from './UserForm'

const useStyles = makeStyles({ bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' } })
const SHOW = [['', 'Needs a branch'], ['allowed', 'Allowed'], ['all', 'Everyone']]

/**
 * Company-wide access (settings and admin): everyone who sees the whole company, and who should be given a branch or
 * an industry instead (by the rule in Settings → Security). Give a branch replaces it at once; by IT, it waits for the
 * owner or SyncLytics support.
 */
export function CompanyWidePage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [done, setDone] = useState<UserDone | null>(null)
  const [giving, setGiving] = useState<WideRow | null>(null)
  const { data, loading, error } = useList(api.companyWide, serialize(params), nonce)
  const show = str(params, 'show')
  const columns: Column<WideRow>[] = [
    { key: 'person', label: 'Person', render: (r) => <Text weight="semibold">{r.person ?? '—'}</Text> },
    { key: 'role', label: 'Role', render: (r) => r.role ?? '—' },
    { key: 'applies', label: 'Applies to', render: (r) => r.applies_to },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={r.allowed ? 'success' : 'warning'}>{r.allowed ? 'Allowed' : 'Needs a branch'}</Badge> },
    { key: 'act', label: '', render: (r) => <span onClick={(e) => e.stopPropagation()}><Button size="small" icon={<BuildingShopRegular />} onClick={() => { setDone(null); setGiving(r) }}>Give a branch</Button></span> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Company-wide access" hint="Company-wide access shows every industry and branch. The system cannot know where someone works, so give each flagged person their branch here." classic="/admin/company-wide-access" />
      <div className={s.bar}>
        <Dropdown aria-label="Show" style={{ minWidth: 170 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })}>
          {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        {data && data.needs > 0 && <Badge appearance="tint" color="warning">{data.needs} need a branch</Badge>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent={done.pending ? 'info' : 'success'} style={{ marginBottom: 12 }}><MessageBarBody>{done.message}</MessageBarBody></MessageBar>}
      <DataTable label="Company-wide access" columns={columns} rows={data?.rows ?? []} sort="person" dir="asc" loading={loading}
        empty="Nobody here. Everyone in this list is within the rule in Settings → Security." onSort={() => undefined} onOpen={(r) => navigate(`/office/users/${r.user_id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {giving && data && <GiveBranch row={giving} data={data} onClose={() => setGiving(null)} onDone={(r) => { setGiving(null); setDone(r); setNonce((n) => n + 1) }} />}
    </div>
  )
}

function GiveBranch({ row, data, onClose, onDone }: { row: WideRow; data: WideList; onClose: () => void; onDone: (r: UserDone) => void }) {
  const [type, setType] = useState('branch')
  const [id, setId] = useState('')
  const { guard, dialog } = useReconfirm('Changing access is protected.')
  if (dialog) return dialog
  return (
    <Ask title={`Give ${row.person ?? 'them'} a branch`} go="Change the access" ready={!!id} onClose={onClose} run={guard(async () => onDone(await api.narrowAccess(row.id, type, id)))}>
      <Text block style={{ marginBottom: 10 }}>{row.role} then applies to one branch or industry instead of the whole company.</Text>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <Choose label="Applies to" required options={[{ id: 'branch', label: 'One branch' }, { id: 'industry', label: 'One industry' }]} value={type} onChange={(v) => { setType(v); setId('') }} />
        <Choose label="Which one" required options={type === 'industry' ? data.industries : data.branches} value={id} onChange={setId} />
      </div>
    </Ask>
  )
}
