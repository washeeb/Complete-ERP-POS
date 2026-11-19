import { Button, Dropdown, Link, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { ClockRegular, DocumentTextRegular, MoneyRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SpOptions, type SpRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { PaySupplier } from './PaySupplier'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '320px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Supplier payments (release 6): money paid to suppliers, newest first — what each settles, the withholding tax kept
 * back (with its certificate) and what was paid out. Search by number, reference or supplier; filter by method and
 * supplier. Pay a supplier records a payment, settling the oldest bills first.
 */
export function SupplierPaymentsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<SpOptions | null>(null)
  const [paying, setPaying] = useState(false)
  const [done, setDone] = useState('')
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.supplierPayments, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.supplierPaymentsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const label = (o: { id: string; label: string }[] | undefined, id: string) => o?.find((x) => x.id === id)?.label ?? id
  const columns: Column<SpRow>[] = [
    { key: 'number', label: 'Number', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'date', label: 'Date', sort: 'paid_on', render: (r) => day(r.paid_on) },
    { key: 'supplier', label: 'Supplier', render: (r) => (r.supplier_open
      ? <Link onClick={(e) => { e.stopPropagation(); navigate(r.supplier_open!) }}>{r.supplier}</Link> : (r.supplier ?? '—')) },
    { key: 'method', label: 'Method', render: (r) => <>{label(options?.methods, r.method)}{r.from && <Text block size={200} className={s.muted}>{r.from}</Text>}</> },
    { key: 'reference', label: 'Reference', render: (r) => r.reference ?? '—' },
    { key: 'settles', label: 'Settles', sort: 'amount_minor', end: true, render: (r) => m(r.amount) },
    { key: 'wht', label: 'Withholding tax', end: true, render: (r) => (r.wht ? <>
      {m(r.wht)}
      {r.certificate && <Link style={{ display: "block" }} onClick={(e) => { e.stopPropagation(); window.open(r.certificate!, '_blank', 'noopener') }}><DocumentTextRegular /> {r.certificate_no ?? 'Certificate'}</Link>}
    </> : <Text className={s.muted}>—</Text>) },
    { key: 'paid', label: 'Paid out', end: true, render: (r) => <Text weight="semibold">{m(r.paid)}</Text> },
    { key: 'by', label: 'Recorded by', render: (r) => r.by ?? '—' },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Supplier payments" hint="Money paid to suppliers. A payment settles the oldest bills first; withholding tax kept back has its certificate here." classic="/admin/supplier-payments" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, reference or supplier" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search supplier payments" />
        <Dropdown aria-label="Method" value={str(params, 'method') ? label(options?.methods, str(params, 'method')) : 'Any method'} selectedOptions={[str(params, 'method')]}
          onOptionSelect={(_, d) => set({ method: d.optionValue ?? '' })} style={{ minWidth: 150 }}>
          <Option value="">Any method</Option>{(options?.methods ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Supplier" value={str(params, 'supplier') ? label(options?.suppliers, str(params, 'supplier')) : 'Any supplier'} selectedOptions={[str(params, 'supplier')]}
          onOptionSelect={(_, d) => set({ supplier: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
          <Option value="">Any supplier</Option>{(options?.suppliers ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ExportMenu list="supplier-payments" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.aging && <Button icon={<ClockRegular />} onClick={() => navigate('/office/aged-payables')}>Aged payables</Button>}
        {options?.pay && <Button appearance="primary" icon={<MoneyRegular />} onClick={() => { setDone(''); setPaying(true) }}>Pay a supplier</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {data && <Cards items={[
        { label: 'Payments shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Settled', value: m(data.settled) },
        { label: 'Withholding tax kept back', value: m(data.wht) },
      ]} />}
      <DataTable label="Supplier payments" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'paid_on'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No supplier payments match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })}
        onOpen={(r) => { if (r.supplier_open) navigate(r.supplier_open) }} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {paying && options?.pay && data && <PaySupplier form={options.pay} currency={data.currency} onClose={() => setPaying(false)}
        onDone={(p) => { setPaying(false); setDone(`Payment ${p.number} recorded.`); setNonce((n) => n + 1) }} />}
    </div>
  )
}
