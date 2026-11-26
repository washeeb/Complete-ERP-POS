import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Field, Link, makeStyles, Menu, MenuItem, MenuList,
  MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Option, Popover, PopoverSurface, PopoverTrigger, SearchBox, Tag, TagGroup, Text, tokens,
} from '@fluentui/react-components'
import {
  AddRegular, ArrowSwapRegular, ArrowUploadRegular, CalculatorRegular, CalendarLtrRegular, FilterRegular, MoreHorizontalRegular, QrCodeRegular,
  ScalesRegular, ShieldCheckmarkRegular, TextBulletListSquareRegular,
} from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type Pick, type ProductRow, type ProductsOptions } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams, type Params } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ProductAction, type ProductActionKind } from './ProductActions'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 260px', maxWidth: '420px' },
  grow: { flex: '1 1 auto' },
  filters: { display: 'grid', gap: '10px', width: '300px' },
  muted: { color: tokens.colorNeutralForeground3 },
  ticked: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', padding: '8px 12px', marginBottom: '10px', borderRadius: tokens.borderRadiusLarge,
    backgroundColor: tokens.colorBrandBackground2, border: `1px solid ${tokens.colorBrandStroke2}` },
})

const SCHEDULE: Record<string, [string, 'danger' | 'warning' | 'informative']> = { controlled: ['Controlled', 'danger'], pom: ['POM', 'warning'], otc: ['OTC', 'informative'] }

/** The list's own search and filters, as the server reads them (for "every product that matches"). */
const FILTERS = ['q', 'industry', 'category', 'active', 'schedule', 'stock']

/**
 * Products (release 3): the catalogue the person works with. Search by SKU, name or shelf, or scan a barcode into the
 * box; filters for industry, category, for sale, medicine schedule and out of stock; the default price and the stock on
 * hand where the person may see it. Tick products (or every one that matches) for the current screen's actions. A row
 * opens the product; adding and editing stay on the current form for now.
 */
export function ProductsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<ProductsOptions | null>(null)
  const [reload, setReload] = useState(0)
  const { data, loading, error } = useList(api.products, `${serialize(params)}${reload ? `&r=${reload}` : ''}`)
  const [q, setQ] = useState(str(params, 'q'))
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const [all, setAll] = useState(false) // every product that matches, not only the ticked ones
  const [action, setAction] = useState<ProductActionKind | null>(null)
  const [confirmAll, setConfirmAll] = useState(false)
  const [notice, setNotice] = useState<{ intent: 'success' | 'warning' | 'error'; text: string } | null>(null)
  useEffect(() => { api.productsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => { // type (or scan), then a pause, then search
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const filterKey = FILTERS.map((k) => str(params, k)).join('|')
  useEffect(() => { setTicked(new Set()); setAll(false) }, [filterKey]) // a new search is a new choice

  const m = (v: number | null) => (v === null || !data ? '—' : money(v, data.currency, data.exponent))
  const label = (opts: { id: string; label: string }[] | undefined, id: string) => opts?.find((o) => o.id === id)?.label ?? id
  const match: Params = Object.fromEntries(FILTERS.filter((k) => str(params, k)).map((k) => [k, str(params, k)]))
  const pick: Pick = all ? { match } : { ids: [...ticked] }
  const count = all ? data?.total ?? 0 : ticked.size
  const can = options?.can

  const columns: Column<ProductRow>[] = [
    { key: 'sku', label: 'SKU', sort: 'sku', render: (r) => <Text font="monospace" size={200}>{r.sku}</Text> },
    { key: 'name', label: 'Name', sort: 'name', render: (r) => <>
      <Text weight="semibold">{r.name}</Text>
      {r.schedule && SCHEDULE[r.schedule] && <> <Badge appearance="tint" size="small" color={SCHEDULE[r.schedule][1]}>{SCHEDULE[r.schedule][0]}</Badge></>}
      {r.location && <Text block size={200} className={s.muted}>{r.location}</Text>}
    </> },
    { key: 'category', label: 'Category', render: (r) => r.category ?? '—' },
    ...(options?.industries.length ? [{ key: 'industry', label: 'Industry', render: (r: ProductRow) => r.industry ?? 'Shared' }] : []),
    { key: 'price', label: data?.price_list ? `${data.price_list} price` : 'Price', end: true, render: (r) => m(r.price) },
    ...(data?.stock_shown ? [{ key: 'stock', label: 'On hand', end: true, render: (r: ProductRow) => (r.tracked
      ? <Text weight="semibold" style={{ color: r.on_hand !== null && Number(r.on_hand) <= 0 ? 'var(--zx-bad)' : undefined }}>{r.on_hand} <Text size={200} className={s.muted}>{r.unit}</Text></Text>
      : <Text size={200} className={s.muted}>not tracked</Text>) }] : []),
    { key: 'active', label: 'For sale', render: (r) => (r.active ? <Badge appearance="tint" color="success" size="small">For sale</Badge> : <Badge appearance="tint" color="subtle" size="small">Not for sale</Badge>) },
  ]

  const chips: { key: string; text: string; clear: Params }[] = [
    ...(str(params, 'industry') ? [{ key: 'industry', text: `Industry: ${label(options?.industries, str(params, 'industry'))}`, clear: { industry: '' } }] : []),
    ...(str(params, 'category') ? [{ key: 'category', text: `Category: ${label(options?.categories, str(params, 'category'))}`, clear: { category: '' } }] : []),
    ...(str(params, 'active') ? [{ key: 'active', text: str(params, 'active') === '1' ? 'For sale' : 'Not for sale', clear: { active: '' } }] : []),
    ...(str(params, 'schedule') ? [{ key: 'schedule', text: `Schedule: ${label(options?.schedules, str(params, 'schedule'))}`, clear: { schedule: '' } }] : []),
    ...(str(params, 'stock') ? [{ key: 'stock', text: 'Out of stock', clear: { stock: '' } }] : []),
  ]

  const handOff = (to: 'prices' | 'cost_run') => api.productsHandOff(pick, to).then((r) => navigate(r.url))
    .catch((e: unknown) => setNotice({ intent: 'error', text: e instanceof Error ? e.message : String(e) }))
  const link = (url: string | undefined) => url && navigate(url)

  return (
    <div className={page.page}>
      <PageHead title="Products" hint="What you sell and keep in stock. Scan a barcode into the search box to find its product." />

      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="SKU, name, shelf or barcode" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search products" />
        <Filters options={options} params={params} stock={data?.stock_shown ?? false} onApply={set} />
        <ExportMenu list="products" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.links.create && <Button appearance="primary" icon={<AddRegular />} onClick={() => link(options.links.create)}>New product</Button>}
        {options && (options.links.import || options.links.import_prices || options.batches_off > 0) && (
          <Menu>
            <MenuTrigger disableButtonEnhancement><Button icon={<MoreHorizontalRegular />} aria-label="More">More</Button></MenuTrigger>
            <MenuPopover><MenuList>
              {options.links.import && <MenuItem icon={<ArrowUploadRegular />} onClick={() => link(options.links.import)}>Add new products (file)</MenuItem>}
              {options.links.update && <MenuItem icon={<ArrowSwapRegular />} onClick={() => link(options.links.update)}>Update products (file)</MenuItem>}
              {options.links.import_prices && <MenuItem icon={<TextBulletListSquareRegular />} onClick={() => link(options.links.import_prices)}>Import prices (CSV)</MenuItem>}
              {options.batches_off > 0 && <MenuItem icon={<CalendarLtrRegular />} onClick={() => setConfirmAll(true)}>Switch on batches for all</MenuItem>}
            </MenuList></MenuPopover>
          </Menu>
        )}
      </div>

      {chips.length > 0 && (
        <TagGroup style={{ marginBottom: 10 }} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}
      {notice && <MessageBar intent={notice.intent} style={{ marginBottom: 10 }}><MessageBarBody>{notice.text}</MessageBarBody></MessageBar>}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}

      {data && <Cards items={[
        { label: 'Products shown', value: data.total.toLocaleString('en-GB') },
        { label: 'For sale', value: data.active.toLocaleString('en-GB') },
        ...(data.out !== null ? [{ label: 'Out of stock', value: data.out.toLocaleString('en-GB'), tone: data.out ? 'var(--zx-bad)' : undefined }] : []),
      ]} />}

      {count > 0 && options && (
        <div className={s.ticked} role="region" aria-label="Ticked products">
          <Text weight="semibold">{count.toLocaleString('en-GB')} ticked</Text>
          {!all && data && ticked.size < data.total && data.rows.length > 0 && data.rows.every((r) => ticked.has(r.id)) && (
            <Link onClick={() => setAll(true)}>Tick all {data.total.toLocaleString('en-GB')} that match</Link>
          )}
          <span className={s.grow} />
          {can?.labels && <Button size="small" icon={<QrCodeRegular />} onClick={() => setAction('labels')}>Print labels</Button>}
          <Button size="small" icon={<TextBulletListSquareRegular />} onClick={() => handOff('prices')}>Export prices</Button>
          {can?.manage && (
            <Menu>
              <MenuTrigger disableButtonEnhancement><Button size="small" icon={<MoreHorizontalRegular />}>Change</Button></MenuTrigger>
              <MenuPopover><MenuList>
                <MenuItem icon={<ArrowSwapRegular />} onClick={() => setAction('move')}>Change category, brand or tax</MenuItem>
                <MenuItem icon={<ScalesRegular />} onClick={() => setAction('unit')}>Change the unit</MenuItem>
                <MenuItem icon={<CalendarLtrRegular />} onClick={() => setAction('batches')}>Switch on batches and expiry</MenuItem>
                {can.schedule && <MenuItem icon={<ShieldCheckmarkRegular />} onClick={() => setAction('schedule')}>Set medicine schedule</MenuItem>}
                {can.move_industry && <MenuItem icon={<ArrowSwapRegular />} onClick={() => setAction('industry')}>Move to industry</MenuItem>}
                {can.cost_run && <MenuItem icon={<CalculatorRegular />} onClick={() => handOff('cost_run')}>Work out cost from selling price</MenuItem>}
              </MenuList></MenuPopover>
            </Menu>
          )}
          {!can?.manage && can?.cost_run && <Button size="small" icon={<CalculatorRegular />} onClick={() => handOff('cost_run')}>Work out cost from selling price</Button>}
          <Button size="small" appearance="subtle" onClick={() => { setTicked(new Set()); setAll(false) }}>Clear</Button>
        </div>
      )}

      <DataTable label="Products" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'name'} dir={data?.dir ?? 'asc'} loading={loading}
        empty="No products match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/products/${r.id}`)}
        selected={all ? new Set(data?.rows.map((r) => r.id)) : ticked}
        onSelect={(ids, on) => { setAll(false); setTicked((t) => { const n = new Set(all ? data?.rows.map((r) => r.id) : t); ids.forEach((id) => (on ? n.add(id) : n.delete(id))); return n }) }} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {action && options && <ProductAction kind={action} pick={pick} count={count} options={options} onClose={() => setAction(null)}
        onDone={() => { setReload((r) => r + 1); setTicked(new Set()); setAll(false) }} />}
      {confirmAll && options && <BatchesForAll count={options.batches_off} note={options.batches_note} onClose={() => setConfirmAll(false)}
        onDone={(text, warn) => { setConfirmAll(false); setNotice({ intent: warn ? 'warning' : 'success', text }); setReload((r) => r + 1); api.productsOptions().then(setOptions).catch(() => undefined) }} />}
    </div>
  )
}

/** "Switch on batches for all": every stocked product not yet switched on, after saying what it does. */
function BatchesForAll({ count, note, onClose, onDone }: { count: number; note: string; onClose: () => void; onDone: (text: string, warn: boolean) => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 520 }}>
        <DialogBody>
          <DialogTitle>Switch on batches for all</DialogTitle>
          <DialogContent>
            <Text block>{count.toLocaleString('en-GB')} products in stock are not switched on. {note}</Text>
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 8 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Cancel</Button>
            <Button appearance="primary" disabled={busy} onClick={() => {
              setBusy(true)
              api.batchesAll().then((r) => onDone(`${r.done} product(s) switched on, ${r.untouched} already on${r.skipped.length ? `; ${r.skipped.length} left out: ${r.skipped.slice(0, 5).join(' · ')}` : ''}.`, r.skipped.length > 0))
                .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Working…' : 'Switch on'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}

/** Filters: industry, category, for sale, medicine schedule, out of stock. Applied together. */
function Filters({ options, params, stock, onApply }: { options: ProductsOptions | null; params: Params; stock: boolean; onApply: (p: Params) => void }) {
  const s = useStyles()
  const keys = ['industry', 'category', 'active', 'schedule', 'stock']
  const read = () => Object.fromEntries(keys.map((k) => [k, str(params, k)]))
  const [open, setOpen] = useState(false)
  const [f, setF] = useState<Record<string, string>>(read)
  const count = keys.filter((k) => str(params, k)).length
  const pick = (k: string, lbl: string, opts: { id: string; label: string }[], all: string) => (
    <Field label={lbl}>
      <Dropdown value={opts.find((o) => o.id === f[k])?.label ?? all} selectedOptions={[f[k]]} onOptionSelect={(_, d) => setF({ ...f, [k]: d.optionValue ?? '' })}>
        <Option value="">{all}</Option>
        {opts.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      </Dropdown>
    </Field>
  )
  return (
    <Popover open={open} onOpenChange={(_, d) => { setOpen(d.open); if (d.open) setF(read()) }} positioning="below-start" trapFocus>
      <PopoverTrigger disableButtonEnhancement><Button icon={<FilterRegular />}>Filters{count ? ` (${count})` : ''}</Button></PopoverTrigger>
      <PopoverSurface>
        <div className={s.filters}>
          {(options?.industries.length ?? 0) > 0 && pick('industry', 'Industry', options!.industries, 'All industries')}
          {pick('category', 'Category', options?.categories ?? [], 'All categories')}
          {pick('active', 'For sale', [{ id: '1', label: 'For sale' }, { id: '0', label: 'Not for sale' }], 'Both')}
          {(options?.schedules.length ?? 0) > 0 && pick('schedule', 'Medicine schedule', options!.schedules, 'Any')}
          {stock && pick('stock', 'Stock', [{ id: 'out', label: 'Out of stock' }], 'Any')}
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <Button onClick={() => { onApply(Object.fromEntries(keys.map((k) => [k, '']))); setOpen(false) }}>Clear all</Button>
            <Button appearance="primary" onClick={() => { onApply(f); setOpen(false) }}>Apply</Button>
          </div>
        </div>
      </PopoverSurface>
    </Popover>
  )
}
