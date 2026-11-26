import { Dropdown, MessageBar, MessageBarBody, Option, Text } from '@fluentui/react-components'
import { api, type EstimateRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { useProcStyles } from './ProcurementParts'

const DELIVERED: [string, string][] = [['', 'Any'], ['yes', 'Delivered since'], ['no', 'Still estimated']]

/**
 * Estimated against real costs (part 19): every product given an estimated cost by a cost run, and what its first real
 * delivery after the run cost. The real factor (price ÷ real cost) shows whether the factor used was right; a product
 * stays "still estimated" until a delivery arrives.
 */
export function EstimatedCostsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.estimatedCosts, serialize(params))
  const delivered = str(params, 'delivered')
  const m = (x: number | null) => (x === null || !data ? '—' : money(x, data.currency, data.exponent))
  return (
    <div className={page.page}>
      <PageHead title="Estimated against real costs" hint="Products costed by a cost run, against what their first delivery since really cost." />
      {data && <Text block style={{ marginBottom: 10 }}>{data.summary}</Text>}
      <div className={s.bar}>
        <Dropdown aria-label="Real cost known" value={DELIVERED.find(([k]) => k === delivered)?.[1] ?? 'Any'} selectedOptions={[delivered]}
          onOptionSelect={(_, d) => set({ delivered: d.optionValue ?? '' })} style={{ minWidth: 180 }}>
          {DELIVERED.map(([k, l]) => <Option key={k || 'any'} value={k}>{l}</Option>)}
        </Dropdown>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<EstimateRow> label="Estimated costs" rows={data?.rows ?? []} sort="created" dir="desc" loading={loading} onSort={() => undefined} onOpen={() => undefined}
        empty="No estimated costs: products given a cost by a cost run appear here."
        columns={[
          { key: 'product', label: 'Product', render: (r) => r.product },
          { key: 'store', label: 'Store', render: (r) => r.store ?? '—' },
          { key: 'price', label: 'Price used', end: true, render: (r) => m(r.price) },
          { key: 'estimate', label: 'Estimated cost', end: true, render: (r) => m(r.estimate) },
          { key: 'real', label: 'Real cost', end: true, render: (r) => (r.real === null ? <Text className={s.muted}>Still estimated</Text> : m(r.real)) },
          { key: 'diff', label: 'Real against estimate', end: true, render: (r) => r.difference ?? '—' },
          { key: 'factor', label: 'Real factor', end: true, render: (r) => r.real_factor ?? '—' },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
