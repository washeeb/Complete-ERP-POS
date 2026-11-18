import { Badge, Button, Dropdown, Field, makeStyles, MessageBar, MessageBarBody, Option, Radio, RadioGroup, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowUploadRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type PoOptions, type PoRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose } from '../form/Fields'
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
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  one: { display: 'grid', gap: '10px' },
})

export const PO_TONE: Record<string, 'warning' | 'informative' | 'success' | 'subtle' | 'brand'> = {
  draft: 'subtle', pending_approval: 'warning', approved: 'informative', partially_received: 'informative', received: 'success', closed: 'subtle', cancelled: 'subtle',
}
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Purchase orders (release 5): search by number or supplier; filter by status (several), supplier and warehouse.
 * Cards say how many wait for approval and how many are on their way. A row opens the order; New purchase order opens
 * the draft form, and Order from a file makes a draft from an Excel/CSV list or a supplier's pro-forma (read by the AI).
 */
export function PurchaseOrdersPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<PoOptions | null>(null)
  const [importing, setImporting] = useState(false)
  const { data, loading, error } = useList(api.purchaseOrders, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.purchaseOrdersOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const label = (o: { id: string; label: string }[] | undefined, id: string) => o?.find((x) => x.id === id)?.label ?? id
  const statuses = list(params, 'status')
  const columns: Column<PoRow>[] = [
    { key: 'number', label: 'Order', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'supplier', label: 'Supplier', render: (r) => r.supplier ?? '—' },
    { key: 'to', label: 'To', render: (r) => r.to ?? '—' },
    { key: 'total', label: 'Total', sort: 'total_minor', end: true, render: (r) => m(r.total) },
    { key: 'expected', label: 'Expected', sort: 'expected_on', render: (r) => day(r.expected_on) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={PO_TONE[r.status] ?? 'informative'}>{label(options?.statuses, r.status)}</Badge> },
    { key: 'created', label: 'Created', sort: 'created_at', render: (r) => (r.created_at ? when(r.created_at) : '—') },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Purchase orders" hint="What you order from suppliers: drafts, those waiting for approval, and those on their way. Open one to approve it, send it or receive the goods." classic="/admin/purchase-orders" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Order number or supplier" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search purchase orders" />
        <Dropdown aria-label="Status" multiselect placeholder="Any status" value={statuses.map((x) => label(options?.statuses, x)).join(', ')} selectedOptions={statuses}
          onOptionSelect={(_, d) => set({ status: d.selectedOptions })} style={{ minWidth: 170 }}>
          {(options?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Supplier" value={str(params, 'supplier') ? label(options?.suppliers, str(params, 'supplier')) : 'Any supplier'} selectedOptions={[str(params, 'supplier')]}
          onOptionSelect={(_, d) => set({ supplier: d.optionValue ?? '' })} style={{ minWidth: 160 }}>
          <Option value="">Any supplier</Option>{(options?.suppliers ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Warehouse" value={str(params, 'warehouse') ? label(options?.warehouses, str(params, 'warehouse')) : 'Any warehouse'} selectedOptions={[str(params, 'warehouse')]}
          onOptionSelect={(_, d) => set({ warehouse: d.optionValue ?? '' })} style={{ minWidth: 160 }}>
          <Option value="">Any warehouse</Option>{(options?.warehouses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ExportMenu list="purchase-orders" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.import && <Button icon={<ArrowUploadRegular />} onClick={() => setImporting(true)}>Order from a file</Button>}
        {options?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/purchase-orders/new')}>New purchase order</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Orders shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Ordered (not drafts)', value: m(data.sum) },
        { label: 'Waiting for approval', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined },
        { label: 'On their way', value: data.open.toLocaleString('en-GB') },
      ]} />}
      <DataTable label="Purchase orders" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'created_at'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No purchase orders match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/purchase-orders/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {importing && options?.import && <ImportOrder o={options.import} onClose={() => setImporting(false)} />}
    </div>
  )
}

/** Order from a file: a draft to check, change and submit as usual; lines that cannot be matched are listed, not guessed. */
function ImportOrder({ o, onClose }: { o: NonNullable<PoOptions['import']>; onClose: () => void }) {
  const s = useStyles()
  const [source, setSource] = useState<'sheet' | 'proforma'>('sheet')
  const [supplier, setSupplier] = useState('')
  const [warehouse, setWarehouse] = useState(o.warehouses[0]?.id ?? '')
  const [file, setFile] = useState<File | null>(null)
  const [result, setResult] = useState<{ id: string; number: string; lines: number; skipped: string[] } | null>(null)
  if (result) {
    return (
      <Ask title={`${result.number}: ${result.lines} line(s) in`} go="Check the draft" ready onClose={onClose}
        run={async () => navigate(`/office/purchase-orders/${result.id}/edit`)}>
        {result.skipped.length ? (
          <MessageBar intent="warning"><MessageBarBody>
            {result.skipped.length} left out: {result.skipped.slice(0, 15).join(' · ')}
          </MessageBarBody></MessageBar>
        ) : <Text>Check the lines and costs, then submit it for approval.</Text>}
      </Ask>
    )
  }
  return (
    <Ask title="Order from a file" go="Make the draft" ready={!!warehouse && !!file && (source === 'proforma' || !!supplier)} onClose={onClose} width={600}
      run={() => {
        const body = new FormData()
        body.append('source', source)
        if (supplier) body.append('supplier_id', supplier)
        body.append('warehouse_id', warehouse)
        body.append('file', file!)
        return api.importPurchaseOrder(body).then(setResult)
      }}>
      <Text block style={{ marginBottom: 10 }}>
        Makes a draft purchase order to check, change and submit as usual. Lines that cannot be matched to a product, or have no cost, are listed, not guessed.
      </Text>
      <div className={s.one}>
        <Field label="From" required>
          <RadioGroup value={source} onChange={(_, d) => setSource(d.value as 'sheet' | 'proforma')}>
            <Radio value="sheet" label="An Excel or CSV list (columns: SKU, barcode or product name; Qty; optional Unit and Cost)" />
            <Radio value="proforma" label="A supplier's pro-forma invoice — photo or PDF, read by the AI" />
          </RadioGroup>
        </Field>
        <Choose label="Supplier" required={source === 'sheet'} options={o.suppliers} value={supplier} onChange={setSupplier}
          hint={source === 'proforma' ? 'Leave empty to take it from the pro-forma.' : undefined} />
        <Choose label="Deliver to" required options={o.warehouses} value={warehouse} onChange={setWarehouse} />
        <Field label="File" required hint={source === 'proforma' ? (o.ai_unavailable ?? 'A photo (JPEG, PNG, WebP) or a PDF, up to 10 MB.') : 'Excel (.xlsx) or CSV, up to 10 MB.'}>
          <input type="file" accept={source === 'proforma' ? 'image/jpeg,image/png,image/webp,application/pdf' : '.csv,.xlsx,.xls,text/csv'}
            onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
      </div>
    </Ask>
  )
}
