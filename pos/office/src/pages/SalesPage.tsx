import {
  Badge, Button, Checkbox, Dropdown, Field, Input, makeStyles, Option, Popover, PopoverSurface, PopoverTrigger, SearchBox, Tag, TagGroup,
  Text, ToggleButton, tokens,
} from '@fluentui/react-components'
import { FilterRegular } from '@fluentui/react-icons'
import { useEffect, useMemo, useState } from 'react'
import { api, type SaleRow, type SalesList, type SalesOptions } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { list, serialize, str, useQueryParams } from '../list/query'
import { navigate } from '../nav'

const useStyles = makeStyles({
  page: { flex: '1 1 auto', overflowY: 'auto', padding: '16px 20px 32px', '@media (max-width: 760px)': { padding: '12px 10px 24px' } },
  head: { display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 260px', maxWidth: '420px' },
  seg: { display: 'flex', gap: '2px', flexWrap: 'wrap' },
  chips: { marginBottom: '10px' },
  cards: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '10px', marginBottom: '12px' },
  card: { padding: '12px 14px', borderRadius: tokens.borderRadiusLarge, backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}` },
  big: { display: 'block', fontSize: '20px', fontWeight: tokens.fontWeightBold, fontVariantNumeric: 'tabular-nums' },
  filters: { display: 'grid', gap: '10px', width: '320px' },
  pair: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' },
  methods: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 8px' },
})

const PERIODS: [string, string][] = [['today', 'Today'], ['7', '7 days'], ['30', '30 days'], ['90', '90 days'], ['', 'All']]

const when = (iso: string) => new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })

/**
 * Till sales (release 2): every sale the person may see, newest first. Search by receipt, teller or walk-in; Today, 7,
 * 30 or 90 days; filters for branch, teller, how it was paid, amount, dates and returns; the count, the total and how it
 * was paid for what is shown. A row opens the sale. The old screen is one click away.
 */
export function SalesPage() {
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<SalesList | null>(null)
  const [options, setOptions] = useState<SalesOptions | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [q, setQ] = useState(str(params, 'q'))
  const query = serialize(params)

  useEffect(() => { api.salesOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => { // type, then a pause, then search
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    const ctl = new AbortController()
    setLoading(true)
    api.sales(query, ctl.signal).then((d) => { setData(d); setError('') })
      .catch((e: unknown) => { if (!ctl.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
      .finally(() => { if (!ctl.signal.aborted) setLoading(false) }) // a request replaced by a newer one leaves "loading" to it
    return () => ctl.abort()
  }, [query])

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const columns: Column<SaleRow>[] = useMemo(() => [
    { key: 'receipt', label: 'Receipt', sort: 'receipt_no', render: (r) => <><Text weight="semibold">{r.receipt_no}</Text>{r.kind === 'return' && <> <Badge appearance="tint" color="warning" size="small">Return</Badge></>}</> },
    { key: 'at', label: 'Sold at', sort: 'occurred_at', render: (r) => (r.occurred_at ? when(r.occurred_at) : '—') },
    { key: 'where', label: 'Branch · till', render: (r) => [r.branch, r.till].filter(Boolean).join(' · ') || '—' },
    { key: 'teller', label: 'Teller', sort: 'cashier_name', render: (r) => r.cashier ?? '—' },
    { key: 'paid', label: 'Paid by', render: (r) => r.paid_by.join(' + ') || '—' },
    { key: 'total', label: 'Total', sort: 'total_minor', end: true, render: (r) => <Text weight="semibold">{m(r.total)}</Text> },
  ], [data]) // eslint-disable-line react-hooks/exhaustive-deps

  // What is being looked at, as chips that each take a filter off.
  const label = (opts: { id: string; label: string }[] | undefined, id: string) => opts?.find((o) => o.id === id)?.label ?? id
  const chips: { key: string; text: string; clear: Record<string, string | string[]> }[] = [
    ...(str(params, 'branch') ? [{ key: 'branch', text: `Branch: ${label(options?.branches, str(params, 'branch'))}`, clear: { branch: '' } }] : []),
    ...(str(params, 'teller') ? [{ key: 'teller', text: `Teller: ${label(options?.tellers, str(params, 'teller'))}`, clear: { teller: '' } }] : []),
    ...(list(params, 'methods').length ? [{ key: 'methods', text: `Paid by: ${list(params, 'methods').map((x) => label(options?.methods, x)).join(', ')}`, clear: { methods: [] } }] : []),
    ...(str(params, 'min') || str(params, 'max') ? [{ key: 'amount', text: `Total ${str(params, 'min') || '0'} – ${str(params, 'max') || 'any'}`, clear: { min: '', max: '' } }] : []),
    ...(str(params, 'from') || str(params, 'to') ? [{ key: 'dates', text: `${str(params, 'from') || '…'} to ${str(params, 'to') || 'today'}`, clear: { from: '', to: '' } }] : []),
    ...(str(params, 'kind') ? [{ key: 'kind', text: str(params, 'kind') === 'return' ? 'Returns only' : 'Sales only', clear: { kind: '' } }] : []),
  ]

  return (
    <div className={s.page}>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>Till sales</h1>
          <Text className={s.muted}>Every sale and return from the tills, newest first. Sales never change; returns come from the tills.</Text>
        </div>
      </div>

      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Receipt, teller or customer" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search sales" />
        <div className={s.seg} role="group" aria-label="When">
          {PERIODS.map(([k, l]) => <ToggleButton key={k || 'all'} size="small" checked={str(params, 'period') === k} onClick={() => set({ period: k })}>{l}</ToggleButton>)}
        </div>
        <Filters options={options} params={params} onApply={set} />
        <ExportMenu list="sales" query={serialize(params)} total={data?.total ?? null} />
      </div>

      {chips.length > 0 && (
        <TagGroup className={s.chips} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}

      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}

      {data && (
        <div className={s.cards}>
          <div className={s.card}><Text size={200} className={s.muted}>Sales shown</Text><span className={s.big}>{data.total.toLocaleString('en-GB')}</span></div>
          <div className={s.card}><Text size={200} className={s.muted}>Total</Text><span className={s.big}>{m(data.sum)}</span></div>
          {data.mix.slice(0, 2).map((x) => (
            <div key={x.method} className={s.card}><Text size={200} className={s.muted}>{x.method}</Text><span className={s.big}>{m(x.amount)}</span></div>
          ))}
        </div>
      )}

      <DataTable label="Till sales" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'occurred_at'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No sales match. Clear a filter, or choose All." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/sales/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}

/** Filters: branch, teller, how it was paid, amount, dates, returns. Applied together. */
function Filters({ options, params, onApply }: { options: SalesOptions | null; params: Record<string, string | string[]>; onApply: (p: Record<string, string | string[]>) => void }) {
  const s = useStyles()
  const [open, setOpen] = useState(false)
  const [f, setF] = useState(() => ({ branch: str(params, 'branch'), teller: str(params, 'teller'), methods: list(params, 'methods'), min: str(params, 'min'),
    max: str(params, 'max'), from: str(params, 'from'), to: str(params, 'to'), kind: str(params, 'kind') }))
  const count = ['branch', 'teller', 'min', 'max', 'from', 'to', 'kind'].filter((k) => str(params, k)).length + (list(params, 'methods').length ? 1 : 0)
  return (
    <Popover open={open} onOpenChange={(_, d) => {
      setOpen(d.open)
      if (d.open) setF({ branch: str(params, 'branch'), teller: str(params, 'teller'), methods: list(params, 'methods'), min: str(params, 'min'), max: str(params, 'max'), from: str(params, 'from'), to: str(params, 'to'), kind: str(params, 'kind') })
    }} positioning="below-end" trapFocus>
      <PopoverTrigger disableButtonEnhancement><Button icon={<FilterRegular />}>Filters{count ? ` (${count})` : ''}</Button></PopoverTrigger>
      <PopoverSurface>
        <div className={s.filters}>
          <Field label="Branch">
            <Dropdown placeholder="All branches" value={options?.branches.find((b) => b.id === f.branch)?.label ?? ''} selectedOptions={f.branch ? [f.branch] : []}
              onOptionSelect={(_, d) => setF({ ...f, branch: d.optionValue ?? '' })}>
              <Option value="">All branches</Option>
              {(options?.branches ?? []).map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
            </Dropdown>
          </Field>
          <Field label="Teller">
            <Dropdown placeholder="Everyone" value={options?.tellers.find((t) => t.id === f.teller)?.label ?? ''} selectedOptions={f.teller ? [f.teller] : []}
              onOptionSelect={(_, d) => setF({ ...f, teller: d.optionValue ?? '' })}>
              <Option value="">Everyone</Option>
              {(options?.tellers ?? []).map((t) => <Option key={t.id} value={t.id}>{t.label}</Option>)}
            </Dropdown>
          </Field>
          <Field label="Paid by">
            <div className={s.methods}>
              {(options?.methods ?? []).map((mth) => (
                <Checkbox key={mth.id} label={mth.label} checked={f.methods.includes(mth.id)}
                  onChange={(_, d) => setF({ ...f, methods: d.checked ? [...f.methods, mth.id] : f.methods.filter((x) => x !== mth.id) })} />
              ))}
            </div>
          </Field>
          <div className={s.pair}>
            <Field label="Total from"><Input type="number" min={0} value={f.min} onChange={(_, d) => setF({ ...f, min: d.value })} /></Field>
            <Field label="to"><Input type="number" min={0} value={f.max} onChange={(_, d) => setF({ ...f, max: d.value })} /></Field>
          </div>
          <div className={s.pair}>
            <Field label="Sold from"><Input type="date" value={f.from} onChange={(_, d) => setF({ ...f, from: d.value })} /></Field>
            <Field label="until"><Input type="date" value={f.to} onChange={(_, d) => setF({ ...f, to: d.value })} /></Field>
          </div>
          <Field label="Show">
            <Dropdown value={f.kind === 'return' ? 'Returns only' : f.kind === 'sale' ? 'Sales only' : 'Sales and returns'} selectedOptions={[f.kind]}
              onOptionSelect={(_, d) => setF({ ...f, kind: d.optionValue ?? '' })}>
              <Option value="">Sales and returns</Option><Option value="sale">Sales only</Option><Option value="return">Returns only</Option>
            </Dropdown>
          </Field>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <Button onClick={() => { onApply({ branch: '', teller: '', methods: [], min: '', max: '', from: '', to: '', kind: '' }); setOpen(false) }}>Clear all</Button>
            <Button appearance="primary" onClick={() => { onApply({ ...f, period: f.from || f.to ? '' : str(params, 'period') }); setOpen(false) }}>Apply</Button>
          </div>
        </div>
      </PopoverSurface>
    </Popover>
  )
}
