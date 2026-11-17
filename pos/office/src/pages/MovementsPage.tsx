import {
  Button, Dropdown, Field, Input, makeStyles, Option, Popover, PopoverSurface, PopoverTrigger, SearchBox, Tag, TagGroup, Text, tokens,
} from '@fluentui/react-components'
import { FilterRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type MovementRow, type MovementsOptions, type Option as Choice } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, PeriodButtons, usePageStyles, when } from '../list/ListParts'
import { list, serialize, str, useQueryParams, type Params } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 260px', maxWidth: '420px' },
  filters: { display: 'grid', gap: '10px', width: '320px' },
  pair: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', '& > *': { minWidth: 0 } },
  muted: { color: tokens.colorNeutralForeground3 },
})

/**
 * The stock ledger (release 3): every movement of stock, newest first — sales, returns, deliveries, transfers, counts,
 * adjustments — with who and why. Nothing here is ever changed; a mistake is put right by a new movement. Search a
 * product, choose the period, filter by kind, warehouse, category, brand or person. A row opens its product.
 */
export function MovementsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<MovementsOptions | null>(null)
  const { data, loading, error } = useList(api.movements, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.movementsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const names = (opts: Choice[] | undefined, ids: string[]) => ids.map((id) => opts?.find((o) => o.id === id)?.label ?? id).join(', ')
  const columns: Column<MovementRow>[] = [
    { key: 'at', label: 'When', sort: 'occurred_at', render: (r) => when(r.at) },
    { key: 'product', label: 'Product', render: (r) => <><Text weight="semibold">{r.product}</Text><Text block size={200} font="monospace" className={s.muted}>{r.sku}</Text></> },
    { key: 'type', label: 'What', render: (r) => <>{r.type}{r.reason && <Text block size={200} className={s.muted}>{r.reason}</Text>}</> },
    { key: 'warehouse', label: 'Warehouse', render: (r) => r.warehouse ?? '—' },
    { key: 'entered', label: 'Entered', end: true, render: (r) => r.entered },
    { key: 'qty', label: 'Base qty', end: true, render: (r) => (
      <Text weight="semibold" style={{ color: r.in ? 'var(--zx-good)' : 'var(--zx-bad)' }}>{r.in && !r.qty.startsWith('-') ? '+' : ''}{r.qty} <Text size={200} className={s.muted}>{r.unit}</Text></Text>) },
    { key: 'by', label: 'By', render: (r) => r.by ?? '—' },
  ]
  const chips: { key: string; text: string; clear: Params }[] = [
    ...(str(params, 'product') ? [{ key: 'product', text: `Product: ${data?.product ?? '…'}`, clear: { product: '' } }] : []),
    ...(list(params, 'types').length ? [{ key: 'types', text: `What: ${names(options?.types, list(params, 'types'))}`, clear: { types: [] } }] : []),
    ...(list(params, 'warehouses').length ? [{ key: 'warehouses', text: `Warehouse: ${names(options?.warehouses, list(params, 'warehouses'))}`, clear: { warehouses: [] } }] : []),
    ...(list(params, 'categories').length ? [{ key: 'categories', text: `Category: ${names(options?.categories, list(params, 'categories'))}`, clear: { categories: [] } }] : []),
    ...(list(params, 'brands').length ? [{ key: 'brands', text: `Brand: ${names(options?.brands, list(params, 'brands'))}`, clear: { brands: [] } }] : []),
    ...(str(params, 'by') ? [{ key: 'by', text: `By: ${names(options?.people, [str(params, 'by')])}`, clear: { by: '' } }] : []),
    ...(str(params, 'from') || str(params, 'to') ? [{ key: 'dates', text: `${str(params, 'from') || '…'} to ${str(params, 'to') || 'today'}`, clear: { from: '', to: '' } }] : []),
  ]

  return (
    <div className={page.page}>
      <PageHead title="Stock ledger" hint="Every movement of stock, newest first, with who and why. Nothing here changes: a mistake is put right by a new movement." classic="/admin/stock-movements" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Product: SKU, name or barcode" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search the stock ledger" />
        <PeriodButtons value={str(params, 'period')} onChange={(v) => set({ period: v, from: '', to: '' })} />
        <Filters options={options} params={params} onApply={set} />
      </div>
      {chips.length > 0 && (
        <TagGroup style={{ marginBottom: 10 }} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      <DataTable label="Stock ledger" columns={columns} rows={data?.rows ?? []} sort="occurred_at" dir={data?.dir ?? 'desc'} loading={loading}
        empty="No movements match. Choose All, or clear a filter." onSort={(_, dir) => set({ dir })} onOpen={(r) => navigate(`/office/products/${r.product_id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}

/** Several of a kind (what, warehouse, category, brand), one person, dates. */
function Filters({ options, params, onApply }: { options: MovementsOptions | null; params: Params; onApply: (p: Params) => void }) {
  const s = useStyles()
  const read = () => ({ types: list(params, 'types'), warehouses: list(params, 'warehouses'), categories: list(params, 'categories'), brands: list(params, 'brands'),
    by: str(params, 'by'), from: str(params, 'from'), to: str(params, 'to') })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState(read)
  const count = ['types', 'warehouses', 'categories', 'brands'].filter((k) => list(params, k).length).length + ['by', 'from', 'to'].filter((k) => str(params, k)).length
  const many = (key: 'types' | 'warehouses' | 'categories' | 'brands', lbl: string, opts: Choice[]) => (
    <Field label={lbl}>
      <Dropdown multiselect placeholder="Any" value={f[key].map((id) => opts.find((o) => o.id === id)?.label ?? id).join(', ')} selectedOptions={f[key]}
        onOptionSelect={(_, d) => setF({ ...f, [key]: d.selectedOptions })}>
        {opts.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      </Dropdown>
    </Field>
  )
  return (
    <Popover open={open} onOpenChange={(_, d) => { setOpen(d.open); if (d.open) setF(read()) }} positioning="below-start" trapFocus>
      <PopoverTrigger disableButtonEnhancement><Button icon={<FilterRegular />}>Filters{count ? ` (${count})` : ''}</Button></PopoverTrigger>
      <PopoverSurface>
        <div className={s.filters}>
          {many('types', 'What', options?.types ?? [])}
          {many('warehouses', 'Warehouse', options?.warehouses ?? [])}
          {many('categories', 'Category', options?.categories ?? [])}
          {many('brands', 'Brand', options?.brands ?? [])}
          <Field label="By">
            <Dropdown placeholder="Anyone" value={options?.people.find((p) => p.id === f.by)?.label ?? ''} selectedOptions={f.by ? [f.by] : []} onOptionSelect={(_, d) => setF({ ...f, by: d.optionValue ?? '' })}>
              <Option value="">Anyone</Option>
              {(options?.people ?? []).map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
            </Dropdown>
          </Field>
          <div className={s.pair}>
            <Field label="From"><Input type="date" value={f.from} onChange={(_, d) => setF({ ...f, from: d.value })} /></Field>
            <Field label="until"><Input type="date" value={f.to} onChange={(_, d) => setF({ ...f, to: d.value })} /></Field>
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <Button onClick={() => { onApply({ types: [], warehouses: [], categories: [], brands: [], by: '', from: '', to: '' }); setOpen(false) }}>Clear all</Button>
            <Button appearance="primary" onClick={() => { onApply({ ...f, period: f.from || f.to ? '' : str(params, 'period') }); setOpen(false) }}>Apply</Button>
          </div>
        </div>
      </PopoverSurface>
    </Popover>
  )
}
