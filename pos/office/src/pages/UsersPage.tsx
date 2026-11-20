import { Avatar, Badge, Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular, CheckmarkRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type UserOptions, type UserRow } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { UserForm } from './UserForm'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  who: { display: 'flex', alignItems: 'center', gap: '8px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const SHOW = [['', 'In use'], ['archived', 'Archived only'], ['both', 'Both']]
const since = (d: string | null) => (d ? new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Users (settings and admin): everyone who signs in, with their level, two-step sign-in, how they sign in and whether
 * they have a till PIN. Add a user here; open one to change them, set their PIN or card, and grant their access.
 */
export function UsersPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<UserOptions | null>(null)
  const [adding, setAdding] = useState(str(params, 'add') === '1')
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.users, serialize({ ...params, add: '' }), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.userOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const show = str(params, 'show')
  const yes = (b: boolean) => (b ? <CheckmarkRegular aria-label="Yes" /> : <Text className={s.muted}>—</Text>)
  const columns: Column<UserRow>[] = [
    { key: 'name', label: 'Name', sort: 'name', render: (r) => <div className={s.who}><Avatar size={28} name={r.name} image={{ src: r.photo }} />
      <div><Text weight="semibold">{r.name}</Text>{r.support && <Text block size={200} className={s.muted}>SyncLytics support (Superadmin)</Text>}</div></div> },
    { key: 'username', label: 'Username', render: (r) => <Text font="monospace">{r.username}</Text> },
    { key: 'email', label: 'E-mail', render: (r) => r.email },
    { key: 'level', label: 'Level', sort: 'security_level', render: (r) => `L${r.level}` },
    { key: 'mfa', label: '2FA', render: (r) => yes(r.mfa) },
    { key: 'signs', label: 'Signs in with', render: (r) => r.signs_in },
    { key: 'pin', label: 'PIN', render: (r) => yes(r.pin) },
    { key: 'last', label: 'Last till sign-in', sort: 'last_login_at', render: (r) => since(r.last_till) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={r.archived ? 'subtle' : 'success'}>{r.archived ? 'Archived' : 'In use'}</Badge> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Users" hint="Everyone who signs in: their level, how they sign in, and their till PIN. Open someone to change them and grant their access." classic="/admin/users" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name, username or e-mail" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search users" />
        <Dropdown aria-label="Show" style={{ minWidth: 140 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })}>
          {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        {options?.can_manage && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setAdding(true) }}>Add a user</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Users" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'name'} dir={data?.dir ?? 'asc'} loading={loading}
        empty="No users match. Clear the search, or show the archived ones." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/users/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {adding && options?.can_manage && <UserForm options={options} user={null} save={(b) => api.saveUser(null, b)}
        onClose={() => { setAdding(false); if (str(params, 'add')) set({ add: '' }) }}
        onDone={(r) => { setAdding(false); if (r.user) navigate(r.user.open); else setDone(r.message) }} />}
    </div>
  )
}
