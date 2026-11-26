import { Button, Checkbox, Combobox, Dropdown, Field, makeStyles, MessageBar, MessageBarBody, MessageBarActions, Option, SearchBox, Switch, Tag, TagGroup, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type Option as Choice, type PriceListOptions, type PriceListRow } from '../api'
import { money } from '../home/format'
import { ChooseMany } from '../form/Fields'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  filters: { display: 'grid', gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', gap: '8px 12px', alignItems: 'start', marginBottom: '10px',
    '@media (max-width: 1000px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' }, '@media (max-width: 560px)': { gridTemplateColumns: '1fr' } },
  picks: { display: 'flex', flexDirection: 'column', gap: '6px' },
})

const STATUS = [['', 'Active products'], ['0', 'Inactive products'], ['all', 'All products']]
const many = (v: string | string[] | undefined) => (Array.isArray(v) ? v : v ? [v] : [])

/**
 * Price list (catalog and pricing setup): every product in every unit with its price on a chosen list, narrowed with
 * the filters, then Export (Excel, CSV, or a PDF with the company's logo). Cost and margin only for people who may set
 * prices, so a list printed by anyone else can go to a customer.
 */
export function PriceListPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<PriceListOptions | null>(null)
  const [wide, setWide] = useState(false)
  const query = serialize(params) // no status = active products, as now; "all" = every product
  const { data, loading, error } = useList(api.priceList, query, 0)
  const [q, setQ] = useState(str(params, 'q'))
  const [found, setFound] = useState<Choice[]>([])
  const [named, setNamed] = useState<Choice[]>([])
  const [typed, setTyped] = useState('')
  useEffect(() => { void api.priceListOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (typed.trim().length < 2) return
    const t = setTimeout(() => { void api.priceListProducts(typed).then((r) => setFound(r.rows)) }, 250)
    return () => clearTimeout(t)
  }, [typed])
  const m = (v: number | null) => (v === null || !data ? '—' : money(v, data.currency, data.exponent))
  const list = str(params, 'list') || options?.default_list || ''
  const status = str(params, 'active')
  const products = many(params.product)
  const cost = !!data?.may_cost && wide
  const columns: Column<PriceListRow>[] = [
    { key: 'sku', label: 'SKU', render: (r) => <Text className={s.muted}>{r.sku}</Text> },
    { key: 'product', label: 'Product', render: (r) => <Text weight="semibold">{r.product}</Text> },
    { key: 'category', label: 'Category', render: (r) => r.category ?? '—' },
    { key: 'brand', label: 'Brand', render: (r) => r.brand ?? '—' },
    { key: 'unit', label: 'Unit', render: (r) => r.unit },
    { key: 'net', label: 'Before tax', end: true, render: (r) => m(r.net) },
    { key: 'tax', label: 'VAT and levies', end: true, render: (r) => m(r.tax) },
    { key: 'gross', label: 'Customer pays', end: true, render: (r) => <Text weight="bold">{m(r.gross)}</Text> },
    ...(wide ? [{ key: 'on_hand', label: 'In stock', end: true, render: (r: PriceListRow) => r.on_hand ?? '—' }] : []),
    ...(cost ? [{ key: 'cost', label: 'Cost', end: true, render: (r: PriceListRow) => m(r.cost) }, { key: 'margin', label: 'Margin', end: true, render: (r: PriceListRow) => r.margin ?? '—' }] : []),
  ]
  return (
    <div className={page.page}>
      <PageHead title="Price list" hint="Every product in every unit with its price on the list you choose. Narrow it with the filters, then Export to Excel, CSV or PDF." />
      {data?.ticked !== null && data?.ticked !== undefined && <MessageBar intent="info" style={{ marginBottom: 10 }}>
        <MessageBarBody>{data.ticked} product(s) ticked on the Products list.</MessageBarBody>
        <MessageBarActions><Button size="small" onClick={() => set({ only: '' })}>Show all products</Button></MessageBarActions>
      </MessageBar>}
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Product name or code" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search the price list" />
        <Dropdown aria-label="Price list" style={{ minWidth: 160 }} value={options?.lists.find((x) => x.id === list)?.label ?? ''} selectedOptions={[list]}
          onOptionSelect={(_, d) => set({ list: d.optionValue === options?.default_list ? '' : d.optionValue ?? '' })}>
          {(options?.lists ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Status" style={{ minWidth: 150 }} value={STATUS.find((x) => x[0] === status)?.[1] ?? ''} selectedOptions={[status]} onOptionSelect={(_, d) => set({ active: d.optionValue ?? '' })}>
          {STATUS.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Checkbox label="In stock only" checked={str(params, 'in_stock') === '1'} onChange={(_, d) => set({ in_stock: d.checked ? '1' : '' })} />
        <Checkbox label="With a price only" checked={str(params, 'priced') === '1'} onChange={(_, d) => set({ priced: d.checked ? '1' : '' })} />
        <span className={s.grow} />
        <Switch label={data?.may_cost ? 'Stock, cost and margin' : 'Stock'} checked={wide} onChange={(_, d) => setWide(d.checked)} />
        <ExportMenu list="price-list" query={query} total={data?.total ?? null} />
      </div>
      {options && <div className={s.filters}>
        <ChooseMany label="Category" options={options.categories} value={many(params.category)} onChange={(v) => set({ category: v })} placeholder="All" />
        <ChooseMany label="Brand" options={options.brands} value={many(params.brand)} onChange={(v) => set({ brand: v })} placeholder="All" />
        <ChooseMany label="Supplier" options={options.suppliers} value={many(params.supplier)} onChange={(v) => set({ supplier: v })} placeholder="All" />
        <Field label="Product">
          <div className={s.picks}>
            <Combobox freeform value={typed} selectedOptions={[]} placeholder="All" onChange={(e) => setTyped(e.target.value)}
              onOptionSelect={(_, d) => { const p = found.find((x) => x.id === d.optionValue); if (p) { setNamed([...named, p]); set({ product: [...products, p.id] }) } setTyped('') }}>
              {found.filter((p) => !products.includes(p.id)).map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
            </Combobox>
            {products.length > 0 && <TagGroup aria-label="Products" onDismiss={(_, d) => set({ product: products.filter((x) => x !== d.value) })}>
              {products.map((x) => <Tag key={x} value={x} size="small" dismissible dismissIcon={{ 'aria-label': 'Remove' }}>{named.find((p) => p.id === x)?.label ?? 'Chosen product'}</Tag>)}
            </TagGroup>}
          </div>
        </Field>
      </div>}
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable label="Price list" columns={columns} rows={data?.rows ?? []} sort="product" dir="asc" loading={loading} empty="No products match."
        onSort={() => undefined} onOpen={() => undefined} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[25, 50, 100, 200]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
