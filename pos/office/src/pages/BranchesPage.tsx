import { Badge, Button, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type BranchRow } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'

/**
 * Company → Branches (part 20): each branch with its code (it starts receipt numbers, so it never changes), industry,
 * tills and the modules it runs. A row opens the branch: its details, own settings, stores and tills. Branches are
 * archived, never deleted.
 */
export function BranchesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.branches, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className={page.page}>
      <PageHead title="Branches" hint="Each shop or office, with its stores and tills. The code starts receipt numbers, so it never changes." classic="/admin/branches" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Code or name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search branches" />
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/branches/new')}>New branch</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<BranchRow> label="Branches" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No branches match." onSort={() => undefined}
        onOpen={(r) => navigate(`/office/branches/${r.id}`)}
        columns={[
          { key: 'code', label: 'Code', render: (r) => <Text font="monospace" weight="semibold">{r.code}</Text> },
          { key: 'name', label: 'Name', render: (r) => <>{r.name}{r.hq && <Badge appearance="outline" size="small" style={{ marginLeft: 6 }}>HQ</Badge>}</> },
          { key: 'industry', label: 'Industry', render: (r) => r.industry ?? <Text className={s.muted}>Company-level</Text> },
          { key: 'tills', label: 'Tills', end: true, render: (r) => r.tills },
          { key: 'modules', label: 'Modules', render: (r) => r.modules || '—' },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
