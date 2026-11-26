import { Badge, Dropdown, makeStyles, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type GrnOptions, type GrnRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '320px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const TONE: Record<string, 'success' | 'warning' | 'subtle'> = { posted: 'success', held: 'warning', reversed: 'subtle' }

/**
 * Goods received (release 5): every goods received note, newest first. Search by number, purchase order, supplier or
 * their delivery note; filter by status and warehouse. Cards say what came in and how many deliveries wait for a
 * manager (more than the order). A row opens the note. Notes are made by receiving a purchase order or on the handheld.
 */
export function GoodsReceiptsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<GrnOptions | null>(null)
  const { data, loading, error } = useList(api.goodsReceipts, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.goodsReceiptsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const label = (o: { id: string; label: string }[] | undefined, id: string) => o?.find((x) => x.id === id)?.label ?? id
  const columns: Column<GrnRow>[] = [
    { key: 'number', label: 'GRN', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'order', label: 'Order', render: (r) => r.order ?? <Text className={s.muted}>—</Text> },
    { key: 'supplier', label: 'Supplier', render: (r) => <>{r.supplier ?? '—'}{r.ref && <Text block size={200} className={s.muted}>Delivery note {r.ref}</Text>}</> },
    { key: 'warehouse', label: 'Warehouse', render: (r) => r.warehouse ?? '—' },
    { key: 'value', label: 'Value', sort: 'net_minor', end: true, render: (r) => m(r.value) },
    { key: 'received', label: 'Received', sort: 'received_at', render: (r) => (
      <>{r.received_at ? when(r.received_at) : '—'}{r.received_by && <Text block size={200} className={s.muted}>{r.received_by}</Text>}</>
    ) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={TONE[r.status] ?? 'informative'}>{label(options?.statuses, r.status)}</Badge> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Goods received" hint="Every delivery received into stock. Open one to print it, accept or refuse a delivery above the order, or reverse a mistake." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="GRN, order, supplier or delivery note" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search goods received" />
        <Dropdown aria-label="Status" value={str(params, 'status') ? label(options?.statuses, str(params, 'status')) : 'Any status'} selectedOptions={[str(params, 'status')]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
          <Option value="">Any status</Option>{(options?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Warehouse" value={str(params, 'warehouse') ? label(options?.warehouses, str(params, 'warehouse')) : 'Any warehouse'} selectedOptions={[str(params, 'warehouse')]}
          onOptionSelect={(_, d) => set({ warehouse: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
          <Option value="">Any warehouse</Option>{(options?.warehouses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ExportMenu list="goods-receipts" query={serialize(params)} total={data?.total ?? null} />
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Notes shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Received (posted)', value: m(data.value) },
        ...(data.held ? [{ label: 'Waiting for a manager', value: data.held.toLocaleString('en-GB'), tone: 'var(--zx-warn)' }] : []),
      ]} />}
      <DataTable label="Goods received" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'received_at'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No goods received notes match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/goods-received/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
