import { Badge, Button, Dropdown, makeStyles, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { DocumentAddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type DpOptions, type DpRow } from '../api'
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
})

const TONE: Record<string, 'success' | 'warning' | 'subtle' | 'informative'> = { posted: 'success', submitted: 'warning', cancelled: 'subtle', draft: 'informative' }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Direct purchases (release 5): goods that arrive with a supplier's invoice and no purchase order, paid on delivery, on
 * credit or on consignment. Search by number, invoice or supplier; filter by status and how it is paid. A row opens it;
 * Enter a supplier invoice opens the form (type it in, or scan it with the AI).
 */
export function DirectPurchasesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<DpOptions | null>(null)
  const { data, loading, error } = useList(api.directPurchases, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.directPurchasesOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const label = (o: { id: string; label: string }[] | undefined, id: string) => o?.find((x) => x.id === id)?.label ?? id
  const columns: Column<DpRow>[] = [
    { key: 'number', label: 'No.', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'date', label: 'Date', sort: 'invoice_date', render: (r) => day(r.invoice_date) },
    { key: 'supplier', label: 'Supplier', render: (r) => r.supplier ?? '—' },
    { key: 'invoice', label: 'Invoice', render: (r) => r.invoice_no },
    { key: 'settlement', label: 'Payment', render: (r) => label(options?.settlements, r.settlement) },
    { key: 'total', label: 'Total', sort: 'total_minor', end: true, render: (r) => m(r.total) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={TONE[r.status] ?? 'informative'}>{label(options?.statuses, r.status)}</Badge> },
    { key: 'by', label: 'By', render: (r) => r.by ?? '—' },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Direct purchases" hint="Goods that arrive with a supplier's invoice and no purchase order, paid on delivery, on credit or on consignment." classic="/admin/direct-purchases" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, invoice or supplier" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search direct purchases" />
        <Dropdown aria-label="Status" value={str(params, 'status') ? label(options?.statuses, str(params, 'status')) : 'Any status'} selectedOptions={[str(params, 'status')]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
          <Option value="">Any status</Option>{(options?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Payment" value={str(params, 'settlement') ? label(options?.settlements, str(params, 'settlement')) : 'Any payment'} selectedOptions={[str(params, 'settlement')]}
          onOptionSelect={(_, d) => set({ settlement: d.optionValue ?? '' })} style={{ minWidth: 190 }}>
          <Option value="">Any payment</Option>{(options?.settlements ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ExportMenu list="direct-purchases" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_create && <Button appearance="primary" icon={<DocumentAddRegular />} onClick={() => navigate('/office/direct-purchases/new')}>Enter a supplier invoice</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Posted', value: m(data.posted) },
        ...(data.waiting ? [{ label: 'Waiting for approval', value: data.waiting.toLocaleString('en-GB'), tone: 'var(--zx-warn)' }] : []),
        ...(data.drafts ? [{ label: 'Drafts', value: data.drafts.toLocaleString('en-GB') }] : []),
      ]} />}
      <DataTable label="Direct purchases" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'created_at'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No direct purchases match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/direct-purchases/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
