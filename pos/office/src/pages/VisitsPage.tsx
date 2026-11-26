import { Link, MessageBar, MessageBarBody, SearchBox, Switch, Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { VisitList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = VisitList['rows'][number]

/**
 * Distribution → Visits (part 34): every visit a rep recorded on the phone — who, which customer and route, what came of
 * it, a note and where they were (opens the map). Read-only.
 */
export function VisitsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.visits, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className={page.page}>
      <PageHead title="Visits" hint="Every visit a rep recorded on the phone, with what came of it and where they were." classic="/admin/visits" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Rep" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search visits" />
        <Pick label="Outcome" any="Any outcome" options={data?.outcomes ?? []} value={str(params, 'outcome')} onChange={(v) => set({ outcome: v })} />
        <Switch label="Today" checked={str(params, 'today') === '1'} onChange={(_, d) => set({ today: d.checked ? '1' : '' })} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Visits" rows={data?.rows ?? []} sort="when" dir="desc" loading={loading} empty="No visits match." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'when', label: 'When', render: (r) => r.when ?? '—' },
          { key: 'rep', label: 'Rep', render: (r) => r.rep ?? '—' },
          { key: 'customer', label: 'Customer', render: (r) => r.customer ?? '—' },
          { key: 'route', label: 'Route', render: (r) => r.route ?? '—' },
          { key: 'outcome', label: 'Outcome', render: (r) => <ToneBadge tone={r.tone} label={r.outcome} /> },
          { key: 'note', label: 'Note', render: (r) => r.note ?? '—' },
          { key: 'location', label: 'Location', render: (r) => (r.map ? <Link href={r.map} target="_blank" rel="noopener noreferrer">{r.location}</Link> : <Text className={s.muted}>No GPS</Text>) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
