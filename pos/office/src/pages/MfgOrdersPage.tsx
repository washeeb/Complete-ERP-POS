import { Button, Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { MfgOrderList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { NewMfgOrder } from './MfgParts'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = MfgOrderList['rows'][number]
const OPEN = [{ id: '1', label: 'Open only' }, { id: '0', label: 'All' }]

/**
 * Manufacturing → Work orders (part 35): what is being made, open only by default, and by status. New work order (a draft
 * until released); a row opens it, where materials are issued, output reported, quality checked and the order finished.
 */
export function MfgOrdersPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.mfgOrders, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  const [adding, setAdding] = useState(false)
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const open = str(params, 'open') === '0' ? '0' : '1'
  return (
    <div className={page.page}>
      <PageHead title="Work orders" hint="What is being made. Release a draft to start; issue materials, report output, then finish to settle the cost." classic="/admin/work-orders" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or product" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search work orders" />
        <Dropdown aria-label="Open or all" value={OPEN.find((x) => x.id === open)?.label ?? 'Open only'} selectedOptions={[open]} style={{ minWidth: 130 }}
          onOptionSelect={(_, d) => set({ open: d.optionValue === '0' ? '0' : '' })}>
          {OPEN.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => setAdding(true)}>New work order</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Work orders" rows={data?.rows ?? []} sort="number" dir="desc" loading={loading}
        empty="No work orders. Raise one here, from a bill of materials (Make…), or from production planning."
        onSort={() => undefined} onOpen={(r) => navigate(`/office/manufacturing/orders/${r.id}`)}
        columns={[
          { key: 'number', label: 'No.', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'product', label: 'Product', render: (r) => <Text weight="semibold">{r.product ?? '—'}</Text> },
          { key: 'warehouse', label: 'Warehouse', render: (r) => r.warehouse ?? '—' },
          { key: 'planned', label: 'Planned', end: true, render: (r) => r.planned },
          { key: 'done', label: 'Made', end: true, render: (r) => r.done },
          { key: 'due', label: 'Needed by', render: (r) => day(r.due) },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {adding && <NewMfgOrder onClose={() => setAdding(false)} onDone={(id) => navigate(`/office/manufacturing/orders/${id}`)} />}
    </div>
  )
}
