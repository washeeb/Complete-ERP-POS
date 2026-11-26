import { Button, Dropdown, Input, makeStyles, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { PrintRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CpOptions, type CpRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { list, serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  amount: { width: '130px' },
  date: { width: '150px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Payments received (part 15): every payment customers made on account. Search by receipt, customer or reference;
 * filter by how they paid (several), who received it, the amount and the dates. A row opens the customer; Receipt
 * prints it. Payments are taken on the customer's page (Receive payment).
 */
export function CustomerPaymentsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<CpOptions | null>(null)
  const { data, loading, error } = useList(api.customerPayments, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  const [min, setMin] = useState(str(params, 'min'))
  const [max, setMax] = useState(str(params, 'max'))
  useEffect(() => { api.customerPaymentsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => {
      if (q !== str(params, 'q') || min !== str(params, 'min') || max !== str(params, 'max')) set({ q, min, max })
    }, 400)
    return () => clearTimeout(t)
  }, [q, min, max]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const label = (o: { id: string; label: string }[] | undefined, id: string) => o?.find((x) => x.id === id)?.label ?? id
  const methods = list(params, 'method')
  const columns: Column<CpRow>[] = [
    { key: 'number', label: 'Receipt', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'date', label: 'Date', sort: 'received_on', render: (r) => day(r.received_on) },
    { key: 'customer', label: 'Customer', render: (r) => r.customer ?? '—' },
    { key: 'method', label: 'Method', render: (r) => r.method },
    { key: 'reference', label: 'Reference', render: (r) => r.reference ?? '—' },
    { key: 'amount', label: 'Amount', sort: 'amount_minor', end: true, render: (r) => m(r.amount) },
    { key: 'by', label: 'Received by', render: (r) => r.by ?? '—' },
    { key: 'print', label: '', render: (r) => <Button size="small" icon={<PrintRegular />} aria-label={`Print receipt ${r.number}`}
      onClick={(e) => { e.stopPropagation(); window.open(r.print, '_blank', 'noopener') }}>Receipt</Button> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Payments received" hint="What customers paid on account. Open a row for the customer; Receipt prints it. Take a payment on the customer's page." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Receipt, customer or reference" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search payments" />
        <Dropdown aria-label="Method" multiselect placeholder="Any method" value={methods.map((x) => label(options?.methods, x)).join(', ')} selectedOptions={methods}
          onOptionSelect={(_, d) => set({ method: d.selectedOptions })} style={{ minWidth: 150 }}>
          {(options?.methods ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Received by" value={str(params, 'by') || 'Anyone'} selectedOptions={[str(params, 'by')]}
          onOptionSelect={(_, d) => set({ by: d.optionValue ?? '' })} style={{ minWidth: 150 }}>
          <Option value="">Anyone</Option>{(options?.people ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Input className={s.amount} type="number" min={0} placeholder="Amount from" aria-label="Amount from" value={min} onChange={(_, d) => setMin(d.value)} />
        <Input className={s.amount} type="number" min={0} placeholder="Amount to" aria-label="Amount to" value={max} onChange={(_, d) => setMax(d.value)} />
        <Input className={s.date} type="date" aria-label="From" value={str(params, 'from')} onChange={(_, d) => set({ from: d.value })} />
        <Input className={s.date} type="date" aria-label="To" value={str(params, 'to')} onChange={(_, d) => set({ to: d.value })} />
        <ExportMenu list="customer-payments" query={serialize(params)} total={data?.total ?? null} />
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[{ label: 'Payments shown', value: data.total.toLocaleString('en-GB') }, { label: 'Received', value: m(data.sum) }]} />}
      <DataTable label="Payments received" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'received_on'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No payments match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })}
        onOpen={(r) => { if (data?.open_customer) navigate(`/office/customers/${r.customer_id}`) }} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
