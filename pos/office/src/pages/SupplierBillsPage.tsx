import { Badge, Button, Dropdown, Field, makeStyles, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { CameraRegular, DocumentAddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SbOptions, type SbRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose } from '../form/Fields'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '320px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  one: { display: 'grid', gap: '10px' },
})

export const SB_TONE: Record<string, 'warning' | 'success' | 'danger'> = { pending_approval: 'warning', posted: 'success', rejected: 'danger' }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Supplier bills (release 6): supplier invoices, matched to goods received or charged to expense accounts. Search by
 * number, their invoice or supplier; filter by status and supplier. "vs order" shows where the bill's prices differ from
 * the order's (those wait for approval). Enter a bill opens the form; Scan an invoice fills it with the AI.
 */
export function SupplierBillsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<SbOptions | null>(null)
  const [scanning, setScanning] = useState(false)
  const { data, loading, error } = useList(api.supplierBills, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.supplierBillsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const label = (o: { id: string; label: string }[] | undefined, id: string) => o?.find((x) => x.id === id)?.label ?? id
  const columns: Column<SbRow>[] = [
    { key: 'number', label: 'Number', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'supplier', label: 'Supplier', render: (r) => r.supplier ?? '—' },
    { key: 'invoice', label: 'Their invoice', render: (r) => r.invoice_no },
    { key: 'date', label: 'Date', sort: 'bill_date', render: (r) => day(r.bill_date) },
    { key: 'due', label: 'Due', sort: 'due_on', render: (r) => day(r.due_on) },
    { key: 'total', label: 'Total', sort: 'total_minor', end: true, render: (r) => m(r.total) },
    { key: 'variance', label: 'vs order', end: true, render: (r) => (r.variance ? <Text style={{ color: 'var(--zx-warn)' }}>{m(r.variance)}</Text> : <Text className={s.muted}>—</Text>) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={SB_TONE[r.status] ?? 'informative'}>{label(options?.statuses, r.status)}</Badge> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Supplier bills" hint="Supplier invoices, matched to what was received (or confirmed on an order), or charged straight to an expense account." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, their invoice or supplier" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search supplier bills" />
        <Dropdown aria-label="Status" value={str(params, 'status') ? label(options?.statuses, str(params, 'status')) : 'Any status'} selectedOptions={[str(params, 'status')]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 180 }}>
          <Option value="">Any status</Option>{(options?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Supplier" value={str(params, 'supplier') ? label(options?.suppliers, str(params, 'supplier')) : 'Any supplier'} selectedOptions={[str(params, 'supplier')]}
          onOptionSelect={(_, d) => set({ supplier: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
          <Option value="">Any supplier</Option>{(options?.suppliers ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ExportMenu list="supplier-bills" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.scan && <Button icon={<CameraRegular />} onClick={() => setScanning(true)}>Scan an invoice</Button>}
        {options?.can_create && <Button appearance="primary" icon={<DocumentAddRegular />} onClick={() => navigate('/office/supplier-bills/new')}>Enter a bill</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Bills shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Posted', value: m(data.posted) },
        { label: 'Waiting for approval', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined },
      ]} />}
      <DataTable label="Supplier bills" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'number'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No supplier bills match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/supplier-bills/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {scanning && options?.scan && <Scan o={options.scan} onClose={() => setScanning(false)} />}
    </div>
  )
}

/** Scan an invoice: the AI reads it and fills in the bill form; nothing is entered until it is checked and created. */
function Scan({ o, onClose }: { o: NonNullable<SbOptions['scan']>; onClose: () => void }) {
  const s = useStyles()
  const [branch, setBranch] = useState(o.hq)
  const [file, setFile] = useState<File | null>(null)
  return (
    <Ask title="Scan an invoice" go="Read it" ready={!o.ai_unavailable && !!branch && !!file} onClose={onClose}
      run={() => api.scanSupplierBill(branch, file!).then((r) => navigate(`/office/supplier-bills/new?scan=${r.scan}`))}>
      <Text block style={{ marginBottom: 10 }}>{o.ai_unavailable ?? 'The AI reads the invoice and fills in the bill form. Nothing is entered until you check it and press Create.'}</Text>
      {!o.ai_unavailable && (
        <div className={s.one}>
          <Field label="Photo or PDF of the supplier invoice" required hint="Up to 10 MB.">
            <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </Field>
          <Choose label="Branch" required options={o.branches} value={branch} onChange={setBranch} />
        </div>
      )}
    </Ask>
  )
}
