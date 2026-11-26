import { Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { WhatsappList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = WhatsappList['rows'][number]

/**
 * Online → WhatsApp orders (part 33): customers' WhatsApp messages to the business number, new by default and refreshed
 * every 30 seconds. A row opens the message, to make the order, reply, decline or make a ticket.
 */
export function WhatsappOrdersPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [tick, setTick] = useState(0)
  const { data, loading, error } = useList(api.whatsappOrders, serialize(params), tick)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { const t = setInterval(() => setTick((n) => n + 1), 30000); return () => clearInterval(t) }, [])
  const status = data?.status ?? (str(params, 'status') || 'new')
  return (
    <div className={page.page}>
      <PageHead title="WhatsApp orders" hint="Customers' WhatsApp messages to your business number. Make the order, reply, decline, or make a ticket for a problem." classic="/admin/whatsapp-orders" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Reference, name or number" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search WhatsApp orders" />
        <Dropdown aria-label="Status" value={status === 'all' ? 'Any status' : data?.statuses.find((x) => x.id === status)?.label ?? 'New'} selectedOptions={[status]} style={{ minWidth: 130 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? 'new' })}>
          {(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}<Option value="all">Any status</Option>
        </Dropdown>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="WhatsApp orders" rows={data?.rows ?? []} sort="received" dir="desc" loading={loading && !data}
        empty="No WhatsApp orders waiting. Customers' WhatsApp messages to your business number appear here." onSort={() => undefined}
        onOpen={(r) => navigate(`/office/online/whatsapp/${r.id}`)}
        columns={[
          { key: 'number', label: 'Reference', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'received', label: 'Received', render: (r) => r.received ?? '—' },
          { key: 'from', label: 'From', render: (r) => r.from },
          { key: 'body', label: 'Message', render: (r) => r.body ?? <Text className={s.muted}>(photo only)</Text> },
          { key: 'photos', label: 'Photos', end: true, render: (r) => r.photos ?? '—' },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
