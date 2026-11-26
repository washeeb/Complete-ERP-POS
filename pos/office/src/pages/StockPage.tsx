import {
  Button, Combobox, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Field, Input, makeStyles, Menu, MenuItem, MenuList,
  MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Option, Popover, PopoverSurface, PopoverTrigger, Radio, RadioGroup, SearchBox, Tag, TagGroup, Text, tokens,
} from '@fluentui/react-components'
import { AddRegular, ArrowUploadRegular, ClipboardTextLtrRegular, FilterRegular, HistoryRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type Option as Choice, type StockFind, type StockOptions, type StockRow } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams, type Params } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 260px', maxWidth: '420px' },
  grow: { flex: '1 1 auto' },
  filters: { display: 'grid', gap: '10px', width: '300px' },
  form: { display: 'grid', gap: '10px' },
  pair: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px', '& > *': { minWidth: 0 } },
  muted: { color: tokens.colorNeutralForeground3 },
})

const LEVELS: Choice[] = [{ id: 'in', label: 'In stock' }, { id: 'out', label: 'Out of stock (0)' }, { id: 'negative', label: 'Negative (investigate)' }]
const since = (iso: string | null) => {
  if (!iso) return '—'
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
  return days <= 0 ? 'Today' : days === 1 ? 'Yesterday' : days < 60 ? `${days} days ago` : new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

/**
 * Stock on hand (release 3): every product in every warehouse the person may see, with search (SKU, name or a scanned
 * barcode), warehouse, category and in / out / negative stock. The stock sheet before a count (PDF or CSV, blind or
 * not), opening stock and adjustments into the stock ledger, and the CSV update are here as on the current screen.
 */
export function StockPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<StockOptions | null>(null)
  const [reload, setReload] = useState(0)
  const { data, loading, error } = useList(api.stock, `${serialize(params)}${reload ? `&r=${reload}` : ''}`)
  const [q, setQ] = useState(str(params, 'q'))
  const [dialog, setDialog] = useState<'sheet' | 'adjust' | null>(null)
  const [notice, setNotice] = useState('')
  useEffect(() => { api.stockOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const label = (opts: Choice[] | undefined, id: string) => opts?.find((o) => o.id === id)?.label ?? id
  const columns: Column<StockRow>[] = [
    { key: 'product', label: 'Product', sort: 'product', render: (r) => <><Text weight="semibold">{r.name}</Text><Text block size={200} font="monospace" className={s.muted}>{r.sku}</Text></> },
    { key: 'warehouse', label: 'Warehouse', render: (r) => r.warehouse ?? '—' },
    { key: 'on_hand', label: 'On hand', sort: 'on_hand', end: true, render: (r) => (
      <Text weight="semibold" style={{ color: Number(r.on_hand) < 0 ? 'var(--zx-bad)' : Number(r.on_hand) === 0 ? 'var(--zx-warn)' : undefined }}>
        {r.on_hand} <Text size={200} className={s.muted}>{r.unit}</Text>
      </Text>) },
    { key: 'moved', label: 'Last movement', sort: 'updated_at', render: (r) => since(r.updated_at) },
  ]
  const chips: { key: string; text: string; clear: Params }[] = [
    ...(str(params, 'warehouse') ? [{ key: 'warehouse', text: `Warehouse: ${label(options?.warehouses, str(params, 'warehouse'))}`, clear: { warehouse: '' } }] : []),
    ...(str(params, 'category') ? [{ key: 'category', text: `Category: ${label(options?.categories, str(params, 'category'))}`, clear: { category: '' } }] : []),
    ...(str(params, 'level') ? [{ key: 'level', text: label(LEVELS, str(params, 'level')), clear: { level: '' } }] : []),
  ]

  return (
    <div className={page.page}>
      <PageHead title="Stock on hand" hint="What is in each warehouse now. Every change is a row in the stock ledger, with who made it and why." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="SKU, name or barcode" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search stock" />
        <Filters options={options} params={params} onApply={set} />
        <ExportMenu list="stock" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        <Button icon={<HistoryRegular />} onClick={() => navigate('/office/stock/movements')}>Stock ledger</Button>
        <Button icon={<ClipboardTextLtrRegular />} disabled={!options} onClick={() => setDialog('sheet')}>Stock sheet</Button>
        {options?.can_adjust && <Button appearance="primary" icon={<AddRegular />} onClick={() => setDialog('adjust')}>Opening stock / adjustment</Button>}
        {options?.links.import && (
          <Menu>
            <MenuTrigger disableButtonEnhancement><Button icon={<MoreHorizontalRegular />} aria-label="More">More</Button></MenuTrigger>
            <MenuPopover><MenuList><MenuItem icon={<ArrowUploadRegular />} onClick={() => navigate(options.links.import!)}>Update stock from CSV</MenuItem></MenuList></MenuPopover>
          </Menu>
        )}
      </div>
      {chips.length > 0 && (
        <TagGroup style={{ marginBottom: 10 }} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}
      {notice && <MessageBar intent="success" style={{ marginBottom: 10 }}><MessageBarBody>{notice}</MessageBarBody></MessageBar>}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Lines shown', value: data.total.toLocaleString('en-GB') },
        { label: 'In stock', value: data.stocked.toLocaleString('en-GB') },
        { label: 'Out of stock', value: data.empty.toLocaleString('en-GB'), tone: data.empty ? 'var(--zx-warn)' : undefined },
        { label: 'Negative (investigate)', value: data.negative.toLocaleString('en-GB'), tone: data.negative ? 'var(--zx-bad)' : undefined },
      ]} />}
      <DataTable label="Stock on hand" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'product'} dir={data?.dir ?? 'asc'} loading={loading}
        empty="Nothing matches. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/products/${r.product_id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {dialog === 'sheet' && options && <StockSheet options={options} warehouse={str(params, 'warehouse')} onClose={() => setDialog(null)} />}
      {dialog === 'adjust' && options && <Adjust options={options} onClose={() => setDialog(null)}
        onDone={(text) => { setDialog(null); setNotice(text); setReload((r) => r + 1) }} />}
    </div>
  )
}

function Pick({ label, options, value, onChange, all, required }: { label: string; options: Choice[]; value: string; onChange: (v: string) => void; all?: string; required?: boolean }) {
  return (
    <Field label={label} required={required} style={{ minWidth: 0 }}>
      <Dropdown value={options.find((o) => o.id === value)?.label ?? all ?? ''} placeholder={all ?? 'Choose'} selectedOptions={value ? [value] : all ? [''] : []}
        onOptionSelect={(_, d) => onChange(d.optionValue ?? '')}>
        {all !== undefined && <Option value="">{all}</Option>}
        {options.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      </Dropdown>
    </Field>
  )
}

function Filters({ options, params, onApply }: { options: StockOptions | null; params: Params; onApply: (p: Params) => void }) {
  const s = useStyles()
  const keys = ['warehouse', 'category', 'level']
  const read = () => Object.fromEntries(keys.map((k) => [k, str(params, k)]))
  const [open, setOpen] = useState(false)
  const [f, setF] = useState<Record<string, string>>(read)
  const count = keys.filter((k) => str(params, k)).length
  return (
    <Popover open={open} onOpenChange={(_, d) => { setOpen(d.open); if (d.open) setF(read()) }} positioning="below-start" trapFocus>
      <PopoverTrigger disableButtonEnhancement><Button icon={<FilterRegular />}>Filters{count ? ` (${count})` : ''}</Button></PopoverTrigger>
      <PopoverSurface>
        <div className={s.filters}>
          <Pick label="Warehouse" options={options?.warehouses ?? []} value={f.warehouse} onChange={(v) => setF({ ...f, warehouse: v })} all="All warehouses" />
          <Pick label="Category" options={options?.categories ?? []} value={f.category} onChange={(v) => setF({ ...f, category: v })} all="All categories" />
          <Pick label="Stock" options={LEVELS} value={f.level} onChange={(v) => setF({ ...f, level: v })} all="Any" />
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <Button onClick={() => { onApply({ warehouse: '', category: '', level: '' }); setOpen(false) }}>Clear all</Button>
            <Button appearance="primary" onClick={() => { onApply(f); setOpen(false) }}>Apply</Button>
          </div>
        </div>
      </PopoverSurface>
    </Popover>
  )
}

/** The stock standing of a warehouse to count from: PDF to print or CSV to fill in Excel; leave out the system figures for a blind count. */
function StockSheet({ options, warehouse, onClose }: { options: StockOptions; warehouse: string; onClose: () => void }) {
  const s = useStyles()
  const [f, setF] = useState({ warehouse: warehouse || options.warehouses[0]?.id || '', category: '', show_qty: '1', format: 'pdf', zero: '1' })
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 520 }}>
        <DialogBody>
          <DialogTitle>Stock sheet (before a count)</DialogTitle>
          <DialogContent>
            <div className={s.form}>
              <Text>The current stock standing of a warehouse, to print and count on paper or to fill in Excel. Leave out the system quantities for a blind count.</Text>
              <Pick label="Warehouse" required options={options.warehouses} value={f.warehouse} onChange={(v) => setF({ ...f, warehouse: v })} />
              <Pick label="Category" options={options.categories} value={f.category} onChange={(v) => setF({ ...f, category: v })} all="All products" />
              <Field label="System quantities"><RadioGroup layout="horizontal" value={f.show_qty} onChange={(_, d) => setF({ ...f, show_qty: d.value })}>
                <Radio value="1" label="Show them" /><Radio value="0" label="Leave them out (blind count)" /></RadioGroup></Field>
              <Field label="Format"><RadioGroup layout="horizontal" value={f.format} onChange={(_, d) => setF({ ...f, format: d.value })}>
                <Radio value="pdf" label="PDF (to print)" /><Radio value="csv" label="CSV (to fill in Excel)" /></RadioGroup></Field>
              <Field label="Products with no stock"><RadioGroup layout="horizontal" value={f.zero} onChange={(_, d) => setF({ ...f, zero: d.value })}>
                <Radio value="1" label="Include them" /><Radio value="0" label="Leave them out" /></RadioGroup></Field>
            </div>
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Cancel</Button>
            <Button appearance="primary" disabled={!f.warehouse} onClick={() => {
              window.open(api.stockSheetUrl({ warehouse: f.warehouse, category: f.category, show_qty: f.show_qty === '1', format: f.format as 'pdf' | 'csv', zero: f.zero === '1' }), '_blank', 'noopener')
              onClose()
            }}>Make the sheet</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}

/** Opening stock (first count of a product here) or an adjustment with its reason: one row in the stock ledger. */
function Adjust({ options, onClose, onDone }: { options: StockOptions; onClose: () => void; onDone: (text: string) => void }) {
  const s = useStyles()
  const [f, setF] = useState({ type: 'opening', warehouse_id: options.adjust_warehouses.length === 1 ? options.adjust_warehouses[0].id : '', product_uom_id: '', qty: '',
    reason: '', note: '', batch_no: '', expires_on: '', unit_cost: '' })
  const [product, setProduct] = useState<StockFind | null>(null)
  const [text, setText] = useState('')
  const [found, setFound] = useState<StockFind[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  useEffect(() => {
    if (text.trim().length < 2 || text === product?.label) return
    const ctl = new AbortController()
    const t = setTimeout(() => api.stockFind(text, ctl.signal).then((r) => setFound(r.results)).catch(() => undefined), 250)
    return () => { clearTimeout(t); ctl.abort() }
  }, [text, product?.label])
  const adjusting = f.type === 'adjustment'
  const ready = f.warehouse_id && product && f.product_uom_id && f.qty.trim() !== '' && (!adjusting || f.reason)
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 560 }}>
        <DialogBody>
          <DialogTitle>Opening stock / adjustment</DialogTitle>
          <DialogContent>
            <div className={s.form}>
              <Text>Every change is recorded in the stock ledger with your name and reason; nothing is overwritten.</Text>
              <RadioGroup value={f.type} onChange={(_, d) => setF({ ...f, type: d.value })}>
                <Radio value="opening" label="Opening balance (first time this product is counted here)" />
                <Radio value="adjustment" label="Adjustment" />
              </RadioGroup>
              <Pick label="Warehouse" required options={options.adjust_warehouses} value={f.warehouse_id} onChange={(v) => setF({ ...f, warehouse_id: v })} />
              <Field label="Product" required>
                <Combobox value={text} selectedOptions={product ? [product.id] : []} placeholder="Type the SKU, name or scan the barcode" onChange={(e) => setText(e.target.value)}
                  onOptionSelect={(_, d) => {
                    const p = found.find((x) => x.id === d.optionValue) ?? null
                    setProduct(p)
                    setText(p?.label ?? '')
                    setF((x) => ({ ...x, product_uom_id: p?.units[0]?.id ?? '' }))
                  }}>
                  {found.map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
                  {found.length === 0 && <Option disabled value="">Type at least two letters</Option>}
                </Combobox>
              </Field>
              <div className={s.pair}>
                <Pick label="Unit" required options={product?.units ?? []} value={f.product_uom_id} onChange={(v) => setF({ ...f, product_uom_id: v })} />
                <Field label={adjusting ? 'Change (minus to remove, e.g. -3)' : 'Quantity counted'} required style={{ minWidth: 0 }}>
                  <Input value={f.qty} inputMode="decimal" onChange={(_, d) => setF({ ...f, qty: d.value })} />
                </Field>
              </div>
              {adjusting && <>
                <Pick label="Reason" required options={options.reasons} value={f.reason} onChange={(v) => setF({ ...f, reason: v })} />
                <Field label="Note"><Input value={f.note} maxLength={200} onChange={(_, d) => setF({ ...f, note: d.value })} /></Field>
              </>}
              {product?.batches && (
                <div className={s.pair}>
                  <Field label="Batch no." hint="Removing stock without a batch takes the earliest expiry first." style={{ minWidth: 0 }}>
                    <Input value={f.batch_no} maxLength={60} onChange={(_, d) => setF({ ...f, batch_no: d.value })} /></Field>
                  <Field label="Expiry" style={{ minWidth: 0 }}><Input type="date" value={f.expires_on} onChange={(_, d) => setF({ ...f, expires_on: d.value })} /></Field>
                </div>
              )}
              <Field label="Unit cost (optional)" hint="What one unit cost you. It values your stock and works out profit on sales. Leave blank if unknown.">
                <Input type="number" min={0} step="any" value={f.unit_cost} onChange={(_, d) => setF({ ...f, unit_cost: d.value })} />
              </Field>
              {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
            </div>
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Cancel</Button>
            <Button appearance="primary" disabled={busy || !ready} onClick={() => {
              setBusy(true)
              setError('')
              api.adjustStock({ type: f.type, warehouse_id: f.warehouse_id, product_id: product!.id, product_uom_id: f.product_uom_id, qty: f.qty.trim(),
                reason: adjusting ? f.reason : null, note: adjusting ? f.note || null : null, batch_no: f.batch_no || null, expires_on: f.expires_on || null, unit_cost: f.unit_cost || null })
                .then(() => onDone(`Stock updated: ${product!.label}.`)).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Saving…' : 'Save'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
