import {
  Badge, Button, Dropdown, Field, Input, makeStyles, Option, Popover, PopoverSurface, PopoverTrigger, SearchBox, Tag, TagGroup, Text, ToggleButton, tokens,
} from '@fluentui/react-components'
import { FilterRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type InvoiceOptions, type InvoiceRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, PeriodButtons, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams, type Params } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  muted: { color: tokens.colorNeutralForeground3 },
  filters: { display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: '10px', width: 'min(420px, 80vw)' },
  foot: { display: 'flex', justifyContent: 'flex-end', gap: '8px', marginTop: '12px' },
})

const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Customer invoices (release 4): search by number, customer or their reference; filter by status, still owed, past due
 * date, when issued and the total. Cards say how much is still owed and how much of it is past due. A row opens the
 * invoice (Print / PDF, Credit note). Invoices are raised by deliveries; they never change.
 */
export function InvoicesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<InvoiceOptions | null>(null)
  const { data, loading, error } = useList(api.invoices, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.invoicesOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const toggle = (key: string) => set({ [key]: str(params, key) === '1' ? '' : '1' })
  const statusLabel = (id: string) => options?.statuses.find((o) => o.id === id)?.label ?? id
  const columns: Column<InvoiceRow>[] = [
    { key: 'number', label: 'Invoice', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'customer', label: 'Customer', render: (r) => <>{r.customer}{r.ref && <Text block size={200} className={s.muted}>Ref. {r.ref}</Text>}</> },
    { key: 'issued', label: 'Issued', sort: 'issued_on', render: (r) => day(r.issued_on) },
    { key: 'due', label: 'Due', sort: 'due_on', render: (r) => <Text style={r.overdue ? { color: 'var(--zx-bad)' } : undefined}>{day(r.due_on)}</Text> },
    { key: 'total', label: 'Total', sort: 'total_minor', end: true, render: (r) => m(r.total) },
    { key: 'open', label: 'Still owed', sort: 'open_minor', end: true, render: (r) => (r.open
      ? <Text weight="semibold" style={{ color: r.overdue ? 'var(--zx-bad)' : 'var(--zx-warn)' }}>{m(r.open)}</Text>
      : <Text style={{ color: 'var(--zx-good)' }}>paid</Text>) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={r.status === 'credited' ? 'subtle' : 'brand'}>{statusLabel(r.status)}</Badge> },
  ]
  const chips: { key: string; text: string; clear: Params }[] = [
    ...(str(params, 'min') || str(params, 'max') ? [{ key: 'amount', text: `Total ${str(params, 'min') || '0'} – ${str(params, 'max') || 'any'}`, clear: { min: '', max: '' } }] : []),
    ...(str(params, 'from') || str(params, 'to') ? [{ key: 'dates', text: `Issued ${str(params, 'from') || '…'} to ${str(params, 'to') || 'today'}`, clear: { from: '', to: '' } }] : []),
  ]

  return (
    <div className={page.page}>
      <PageHead title="Invoices" hint="Invoices to customers, raised by deliveries. Open one to print it or to cancel it with a credit note." classic="/admin/invoices" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Invoice, customer or their ref." value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search invoices" />
        <Dropdown aria-label="Status" value={str(params, 'status') ? statusLabel(str(params, 'status')) : 'Any status'} selectedOptions={[str(params, 'status')]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 130 }}>
          <Option value="">Any status</Option>{(options?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ToggleButton checked={str(params, 'owed') === '1'} onClick={() => toggle('owed')}>Still owed</ToggleButton>
        <ToggleButton checked={str(params, 'overdue') === '1'} onClick={() => toggle('overdue')}>Past due date</ToggleButton>
        <Filters params={params} onApply={set} />
        <ExportMenu list="invoices" query={serialize(params)} total={data?.total ?? null} />
      </div>
      <div className={s.bar}>
        <Text size={200} className={s.muted}>Issued</Text>
        <PeriodButtons value={str(params, 'period')} onChange={(period) => set({ period, from: '', to: '' })} />
      </div>
      {chips.length > 0 && (
        <TagGroup style={{ marginBottom: 10 }} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Invoices shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Invoiced', value: m(data.sum) },
        { label: 'Still owed', value: m(data.open), tone: data.open ? 'var(--zx-warn)' : undefined },
        { label: 'Past due', value: m(data.overdue), tone: data.overdue ? 'var(--zx-bad)' : undefined },
      ]} />}
      <DataTable label="Invoices" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'issued_on'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No invoices match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/invoices/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}

/** Issued between two days, and the total between two amounts. Applied together. */
function Filters({ params, onApply }: { params: Params; onApply: (p: Params) => void }) {
  const s = useStyles()
  const read = () => ({ from: str(params, 'from'), to: str(params, 'to'), min: str(params, 'min'), max: str(params, 'max') })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState(read)
  const count = ['from', 'to', 'min', 'max'].filter((k) => str(params, k)).length
  return (
    <Popover open={open} onOpenChange={(_, d) => { setOpen(d.open); if (d.open) setF(read()) }} positioning="below-start" trapFocus>
      <PopoverTrigger disableButtonEnhancement><Button icon={<FilterRegular />}>Filters{count ? ` (${count})` : ''}</Button></PopoverTrigger>
      <PopoverSurface>
        <div className={s.filters}>
          <Field label="Issued from"><Input type="date" value={f.from} onChange={(_, d) => setF({ ...f, from: d.value })} /></Field>
          <Field label="until"><Input type="date" value={f.to} onChange={(_, d) => setF({ ...f, to: d.value })} /></Field>
          <Field label="Total from"><Input type="number" min={0} value={f.min} onChange={(_, d) => setF({ ...f, min: d.value })} /></Field>
          <Field label="up to"><Input type="number" min={0} value={f.max} onChange={(_, d) => setF({ ...f, max: d.value })} /></Field>
        </div>
        <div className={s.foot}>
          <Button onClick={() => { onApply({ from: '', to: '', min: '', max: '' }); setOpen(false) }}>Clear</Button>
          <Button appearance="primary" onClick={() => { onApply({ ...f, period: f.from || f.to ? '' : str(params, 'period') }); setOpen(false) }}>Apply</Button>
        </div>
      </PopoverSurface>
    </Popover>
  )
}
