import { Button, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { StyleList } from '../api'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ShowFilter, Status } from './CompanyParts'
import { Pick } from './CrmLeadsPage'
import { useProcStyles } from './ProcurementParts'

type Row = StyleList['rows'][number]

/**
 * Fashion → Styles (part 36): one design in sizes and colours, each size × colour its own product. Search, season, in use
 * or archived; New style; a row opens it (its size and colour grid, Create sizes and colours, One price for all).
 */
export function StylesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.styles, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const m = (x: number | null) => (x === null || !data ? '—' : money(x, data.currency, data.exponent))
  return (
    <div className={page.page}>
      <PageHead title="Styles" hint="One design in sizes and colours. Each size and colour is its own product, with its own SKU and barcode." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Code or name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search styles" />
        <Pick label="Season" any="Any season" options={data?.seasons ?? []} value={str(params, 'season')} onChange={(v) => set({ season: v })} />
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/fashion/styles/new')}>New style</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Styles" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No styles yet."
        onSort={() => undefined} onOpen={(r) => navigate(`/office/fashion/styles/${r.id}`)}
        columns={[
          { key: 'code', label: 'Code', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
          { key: 'name', label: 'Style', render: (r) => <><Text weight="semibold">{r.name}</Text>{!r.for_sale && <Text size={200} block className={s.muted}>Not for sale</Text>}</> },
          { key: 'season', label: 'Season', render: (r) => r.season ?? '—' },
          { key: 'collection', label: 'Collection', render: (r) => r.collection ?? '—' },
          { key: 'products', label: 'Products', end: true, render: (r) => r.products },
          { key: 'price', label: 'Price', end: true, render: (r) => m(r.price) },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
