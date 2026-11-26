import { Badge, Button, Dropdown, Input, makeStyles, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CoOptions, type CoRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles, when } from '../list/ListParts'
import { list, serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  amount: { width: '130px' },
  grow: { flex: '1 1 auto' },
})

export const CO_TONE: Record<string, 'warning' | 'danger' | 'informative' | 'success' | 'subtle'> = {
  quotation: 'warning', on_hold: 'danger', confirmed: 'informative', partially_delivered: 'informative', delivered: 'success', lost: 'subtle', cancelled: 'subtle',
}

/**
 * Quotes & orders (part 15): quotations and customer (wholesale) orders. Search by quote or order number or customer;
 * filter by status (several), total and who entered it. Cards say how many quotes are open, how many orders wait on a
 * credit hold, and what is ordered and not yet delivered. A row opens the quote or order; New quotation opens the form.
 */
export function CustomerOrdersPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<CoOptions | null>(null)
  const { data, loading, error } = useList(api.customerOrders, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  const [min, setMin] = useState(str(params, 'min'))
  const [max, setMax] = useState(str(params, 'max'))
  useEffect(() => { api.customerOrdersOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => {
      if (q !== str(params, 'q') || min !== str(params, 'min') || max !== str(params, 'max')) set({ q, min, max })
    }, 400)
    return () => clearTimeout(t)
  }, [q, min, max]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const label = (o: { id: string; label: string }[] | undefined, id: string) => o?.find((x) => x.id === id)?.label ?? id
  const statuses = list(params, 'status')
  const columns: Column<CoRow>[] = [
    { key: 'quote', label: 'Quote', sort: 'quote_number', render: (r) => <Text font="monospace">{r.quote}</Text> },
    { key: 'order', label: 'Order', render: (r) => (r.order ? <Text font="monospace" weight="semibold">{r.order}</Text> : '—') },
    { key: 'customer', label: 'Customer', render: (r) => r.customer ?? '—' },
    { key: 'total', label: 'Total', sort: 'total_minor', end: true, render: (r) => m(r.total) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={CO_TONE[r.status] ?? 'informative'}>{label(options?.statuses, r.status)}</Badge> },
    { key: 'created', label: 'Created', sort: 'created_at', render: (r) => <>{when(r.created_at)}{r.by && <Text block size={200} style={{ color: tokens.colorNeutralForeground3 }}>{r.by}</Text>}</> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Quotes & orders" hint="Quotations and customer orders: turn a quote into an order, release a credit hold, deliver (each delivery raises its invoice)." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Quote, order or customer" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search quotes and orders" />
        <Dropdown aria-label="Status" multiselect placeholder="Any status" value={statuses.map((x) => label(options?.statuses, x)).join(', ')} selectedOptions={statuses}
          onOptionSelect={(_, d) => set({ status: d.selectedOptions })} style={{ minWidth: 170 }}>
          {(options?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Input className={s.amount} type="number" min={0} placeholder="Total from" aria-label="Total from" value={min} onChange={(_, d) => setMin(d.value)} />
        <Input className={s.amount} type="number" min={0} placeholder="Total to" aria-label="Total to" value={max} onChange={(_, d) => setMax(d.value)} />
        <Dropdown aria-label="Entered by" value={str(params, 'by') || 'Anyone'} selectedOptions={[str(params, 'by')]}
          onOptionSelect={(_, d) => set({ by: d.optionValue ?? '' })} style={{ minWidth: 150 }}>
          <Option value="">Anyone</Option>{(options?.people ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ExportMenu list="customer-orders" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/customer-orders/new')}>New quotation</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Open quotations', value: data.quotes.toLocaleString('en-GB') },
        { label: 'On credit hold', value: data.held.toLocaleString('en-GB'), tone: data.held ? 'var(--zx-bad)' : undefined },
        { label: 'Ordered, not yet delivered', value: m(data.open) },
      ]} />}
      <DataTable label="Quotes and orders" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'created_at'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No quotes or orders match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/customer-orders/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
