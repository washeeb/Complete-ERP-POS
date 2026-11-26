import { MessageBar, MessageBarBody, Text, tokens } from '@fluentui/react-components'
import { useRef } from 'react'
import { api } from '../api'
import type { SettlementList } from '../api'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = SettlementList['rows'][number]

/**
 * Distribution → Van settlements (part 34): each van's end of day — sales, cash over or short, and the stock difference
 * once settled. Started from the van (Vans → Start end-of-day settlement); a row opens it.
 */
export function SettlementsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const cur = useRef<SettlementList | null>(null)
  const { data, loading, error } = useList((qq, sig) => api.settlements(qq, sig).then((d) => { cur.current = d; return d }), serialize(params), 0)
  const m = (x: number) => (cur.current ? money(x, cur.current.currency, cur.current.exponent) : '')
  const signed = (x: number | null) => (x === null ? '—' : <span style={x < 0 ? { color: tokens.colorPaletteRedForeground1 } : undefined}>{m(x)}</span>)
  return (
    <div className={page.page}>
      <PageHead title="Van settlements" hint="Each van's end of day: sales, cash handed in and the stock left on the van. Started from the van." classic="/admin/van-settlements" />
      <div className={s.bar}>
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Van settlements" rows={data?.rows ?? []} sort="day" dir="desc" loading={loading} empty="No settlements yet. Start one from Vans." onSort={() => undefined}
        onOpen={(r) => navigate(`/office/distribution/settlements/${r.id}`)}
        columns={[
          { key: 'number', label: 'Settlement', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'day', label: 'Day', render: (r) => day(r.day) },
          { key: 'van', label: 'Van', render: (r) => r.van ?? '—' },
          { key: 'sales', label: 'Sales', end: true, render: (r) => m(r.sales) },
          { key: 'cash', label: 'Cash over / short', end: true, render: (r) => signed(r.cash) },
          { key: 'stock', label: 'Stock difference', end: true, render: (r) => signed(r.stock) },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
