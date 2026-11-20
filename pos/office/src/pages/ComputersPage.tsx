import { Button, Link, makeStyles, MessageBar, MessageBarBody, SearchBox, Text, tokens } from '@fluentui/react-components'
import { EditRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ComputerRow } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  muted: { color: tokens.colorNeutralForeground3 },
  acts: { display: 'flex', gap: '4px', flexWrap: 'wrap' },
})

const at = (d: string | null) => (d ? new Date(d).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')

/** Computers (settings and admin): every computer (browser) that used the back office, who used it last and from where. Name each one so the audit log says which computer did what. */
export function ComputersPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [naming, setNaming] = useState<ComputerRow | null>(null)
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.computers, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const columns: Column<ComputerRow>[] = [
    { key: 'label', label: 'Computer', render: (r) => <><Text weight="semibold">{r.label}</Text>{r.machine && <Text block size={200} className={s.muted}>{r.machine}</Text>}</> },
    { key: 'user', label: 'Last used by', render: (r) => r.last_user ?? '—' },
    { key: 'seen', label: 'Last used', render: (r) => at(r.last_seen) },
    { key: 'ip', label: 'From address', render: (r) => (r.ip ? (r.lookup ? <><Link href={r.lookup} target="_blank" rel="noopener">{r.ip}</Link><Text block size={200} className={s.muted}>Where is this? (opens ipinfo.io)</Text></> : r.ip) : '—') },
    { key: 'browser', label: 'Browser', render: (r) => r.browser ?? '—' },
    { key: 'acts', label: '', render: (r) => <div className={s.acts} onClick={(e) => e.stopPropagation()}>
      <Button size="small" icon={<EditRegular />} onClick={() => { setDone(''); setNaming(r) }}>Name it</Button>
      <Button size="small" appearance="subtle" onClick={() => navigate(r.activity)}>What it did</Button>
    </div> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Computers" hint="Every computer that used the back office, who used it last and from which address. Name each one (Front desk PC) so the audit log says which computer did what." classic="/admin/computers" />
      <div className={s.bar}><SearchBox className={s.search} placeholder="Computer name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search computers" /></div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Computers" columns={columns} rows={data?.rows ?? []} sort="seen" dir="desc" loading={loading}
        empty="No computers yet. Each is listed the first time someone signs in to the back office from it." onSort={() => undefined} onOpen={(r) => setNaming(r)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {naming && <NameIt row={naming} onClose={() => setNaming(null)} onDone={(m) => { setNaming(null); setDone(m); setNonce((n) => n + 1) }} />}
    </div>
  )
}

function NameIt({ row, onClose, onDone }: { row: ComputerRow; onClose: () => void; onDone: (m: string) => void }) {
  const [name, setName] = useState(row.name ?? '')
  return (
    <Ask title="Name this computer" go="Save" ready={name.trim() !== ''} onClose={onClose} run={async () => onDone((await api.nameComputer(row.id, name)).message)}>
      <TextField label="Name (e.g. Front desk PC)" required value={name} maxLength={80} onChange={setName} />
    </Ask>
  )
}
