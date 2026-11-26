import { Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { OnlineOrderList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = OnlineOrderList['rows'][number]

/**
 * Online → Online orders (part 33): orders from the shop page and connected stores, open (pending, confirmed, ready) by
 * default and refreshed every 30 seconds, with payment and status; red when not all the stock could be reserved. A row
 * opens the order.
 */
export function OnlineOrdersPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [tick, setTick] = useState(0)
  const { data, loading, error } = useList(api.onlineOrders, serialize(params), tick)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { const t = setInterval(() => setTick((n) => n + 1), 30000); return () => clearInterval(t) }, [])
  const status = data?.status ?? (str(params, 'status') || 'open')
  const statusLabel = status === 'open' ? 'Open' : status === 'all' ? 'Any status' : data?.statuses.find((x) => x.id === status)?.label ?? 'Open'
  return (
    <div className={page.page}>
      <PageHead title="Online orders" hint="Orders from your shop page and connected stores. Mark ready, hand over (taking payment if not paid) or cancel." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Order, customer or phone" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search online orders" />
        <Dropdown aria-label="Status" value={statusLabel} selectedOptions={[status]} style={{ minWidth: 150 }} onOptionSelect={(_, d) => set({ status: d.optionValue ?? 'open' })}>
          <Option value="open">Open</Option>
          {(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
          <Option value="all">Any status</Option>
        </Dropdown>
        <Pick label="Channel" any="Every channel" options={data?.channels ?? []} value={str(params, 'channel')} onChange={(v) => set({ channel: v })} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Online orders" rows={data?.rows ?? []} sort="placed" dir="desc" loading={loading && !data} empty="No online orders." onSort={() => undefined}
        onOpen={(r) => navigate(`/office/online/orders/${r.id}`)}
        columns={[
          { key: 'number', label: 'Order', render: (r) => <><Text font="monospace" size={200}>{r.number}</Text><Text size={200} block className={s.muted}>{r.channel}</Text></> },
          { key: 'placed', label: 'Placed', render: (r) => r.placed ?? '—' },
          { key: 'customer', label: 'Customer', render: (r) => <>{r.customer ?? '—'}{r.phone && <Text size={200} block className={s.muted}>{r.phone}</Text>}</> },
          { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
          { key: 'total', label: 'Total', end: true, render: (r) => r.total },
          { key: 'payment', label: 'Payment', render: (r) => <ToneBadge tone={r.paid ? 'success' : 'warning'} label={r.payment} /> },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.tone} label={r.status_label} />
            {r.short && <Text size={200} block style={{ color: tokens.colorPaletteRedForeground1 }}>Not all reserved</Text>}</> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
