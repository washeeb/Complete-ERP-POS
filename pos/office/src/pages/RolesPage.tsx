import { Badge, Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular, CheckmarkRegular, PersonQuestionMarkRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type RoleRow } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const SHOW = [['', 'In use'], ['archived', 'Archived only'], ['both', 'Both']]

/** Roles (settings and admin): what each role may do, its level, and whether it is technical (IT) or seeded. Open one to see or change its permissions. */
export function RolesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.roles, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const show = str(params, 'show')
  const yes = (b: boolean) => (b ? <CheckmarkRegular aria-label="Yes" /> : <Text className={s.muted}>—</Text>)
  const columns: Column<RoleRow>[] = [
    { key: 'name', label: 'Name', sort: 'name', render: (r) => <><Text weight="semibold">{r.name}</Text>{r.description && <Text block size={200} className={s.muted}>{r.description}</Text>}</> },
    { key: 'level', label: 'Level', sort: 'security_level', render: (r) => `L${r.level}` },
    { key: 'permissions', label: 'Permissions', end: true, render: (r) => r.permissions },
    { key: 'technical', label: 'Technical', render: (r) => yes(r.technical) },
    { key: 'seeded', label: 'Seeded', render: (r) => yes(r.seeded) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={r.archived ? 'subtle' : 'success'}>{r.archived ? 'Archived' : 'In use'}</Badge> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Roles" hint="What each role may do and its level. People get a role for the whole company or one place, on their user page." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Role name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search roles" />
        <Dropdown aria-label="Show" style={{ minWidth: 140 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })}>
          {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        {data?.can_requests && <Button icon={<PersonQuestionMarkRegular />} onClick={() => navigate('/office/access-requests')}>Access requests{data.waiting ? ` (${data.waiting})` : ''}</Button>}
        {data?.can_manage && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/roles/new')}>Add a role</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable label="Roles" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'security_level'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No roles match. Clear the search, or show the archived ones." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/roles/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
