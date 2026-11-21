import { Badge, Dropdown, makeStyles, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type SupplierReturnRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

export const SR_TONE: Record<string, 'warning' | 'success' | 'subtle'> = { submitted: 'warning', posted: 'success', cancelled: 'subtle' }
export const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Returns to suppliers (part 17): prepared from Expiry & returns, approved and sent by someone else, then settled with
 * the supplier's credit note, replacement or refund. Search by number or supplier; filter by status, settlement and
 * supplier. A row opens the return.
 */
export function SupplierReturnsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.supplierReturns, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const pick = (key: string, label: string, options: { id: string; label: string }[] | undefined, width = 170) => {
    const v = str(params, key)
    return (
      <Dropdown aria-label={label} value={v ? options?.find((x) => x.id === v)?.label ?? '' : `Any ${label.toLowerCase()}`} selectedOptions={[v]}
        onOptionSelect={(_, d) => set({ [key]: d.optionValue ?? '' })} style={{ minWidth: width }}>
        <Option value="">{`Any ${label.toLowerCase()}`}</Option>{(options ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      </Dropdown>
    )
  }
  const columns: Column<SupplierReturnRow>[] = [
    { key: 'number', label: 'Return', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'date', label: 'Date', render: (r) => day(r.date) },
    { key: 'supplier', label: 'Supplier', render: (r) => r.supplier ?? '—' },
    { key: 'store', label: 'Store', render: (r) => r.store ?? '—' },
    { key: 'reason', label: 'Reason', render: (r) => r.reason },
    { key: 'settlement', label: 'Settlement', render: (r) => <>{r.settlement}{r.settled && <Text block size={200} className={s.muted}>{r.settled}</Text>}</> },
    { key: 'lines', label: 'Lines', end: true, render: (r) => r.lines },
    { key: 'value', label: 'Value', end: true, render: (r) => m(r.value) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={SR_TONE[r.status] ?? 'informative'}>{r.status_label}</Badge> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Returns to suppliers" hint="Stock going back to a supplier: approved and sent by someone else, then settled with a credit note, replacement or refund." classic="/admin/supplier-returns" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or supplier" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search returns to suppliers" />
        {pick('status', 'Status', data?.statuses)}
        {pick('settlement', 'Settlement', data?.settlements)}
        {pick('supplier', 'Supplier', data?.suppliers, 180)}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[{ label: 'Shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Waiting for approval', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined }]} />}
      <DataTable label="Returns to suppliers" columns={columns} rows={data?.rows ?? []} sort="date" dir="desc" loading={loading}
        empty="No returns match. A return is prepared from Stock → Expiry & returns." onSort={() => undefined} onOpen={(r) => navigate(`/office/supplier-returns/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
