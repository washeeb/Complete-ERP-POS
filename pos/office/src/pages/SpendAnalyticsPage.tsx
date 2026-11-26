import { Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow,
  Text, tokens } from '@fluentui/react-components'
import { useEffect, useState, type ReactNode } from 'react'
import { api, type SpendData } from '../api'
import { money } from '../home/format'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { END, Panel, useProcStyles } from './ProcurementParts'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '10px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  two: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 420px), 1fr))', columnGap: '14px' },
  barCol: { width: '45%' },
  track: { height: '10px', borderRadius: '5px', backgroundColor: tokens.colorBrandBackground, minWidth: '1px' },
  sub: { display: 'block', fontSize: tokens.fontSizeBase200, color: tokens.colorNeutralForeground3 },
})

/** A small table of figures: the header, the rows, and a line when there are none. */
function Figures({ label, head, rows, empty }: { label: string; head: string[]; rows: ReactNode[][]; empty: string }) {
  const p = useProcStyles()
  return (
    <Table size="small" aria-label={label}>
      <TableHeader><TableRow>{head.map((h, i) => <TableHeaderCell key={h} button={i ? END : undefined}>{h}</TableHeaderCell>)}</TableRow></TableHeader>
      <TableBody>
        {rows.length === 0
          ? <TableRow><TableCell colSpan={head.length}><Text className={p.muted}>{empty}</Text></TableCell></TableRow>
          : rows.map((r, i) => <TableRow key={i}>{r.map((c, j) => <TableCell key={j} className={j ? p.end : undefined}>{c}</TableCell>)}</TableRow>)}
      </TableBody>
    </Table>
  )
}

/**
 * Spend analytics (part 19): where the money goes on buying, and how well it is controlled — spend before VAT, how much
 * was bought on an approved order or under a contract, how much the biggest suppliers take, what was billed above order
 * prices and what asking for quotes saved; then month by month, by supplier, by category, how it was bought and by
 * department. Dates and branch live in the address; Export gives every table.
 */
export function SpendAnalyticsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<SpendData | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const query = serialize(params)
  useEffect(() => {
    const c = new AbortController()
    setLoading(true)
    api.spendAnalytics(query, c.signal).then((d) => { setData(d); setError('') })
      .catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
      .finally(() => { if (!c.signal.aborted) setLoading(false) })
    return () => c.abort()
  }, [query])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Adding up the spend…" /></div>
  const m = (x: number) => money(x, data.currency, data.exponent)
  const { totals: t, compliance: c, concentration: k, variance: v, rfq } = data
  const peak = Math.max(1, ...data.months.map((x) => x.spend))
  const sub = (x: string) => <span className={s.sub}>{x}</span>
  return (
    <div className={page.page}>
      <PageHead title="Spend analytics" hint="Where the money goes on buying, and how well it is controlled." />
      <div className={s.bar}>
        <Field label="From"><Input type="date" value={data.from} onChange={(_, d) => d.value && set({ from: d.value })} /></Field>
        <Field label="To"><Input type="date" value={data.to} onChange={(_, d) => d.value && set({ to: d.value })} /></Field>
        <Field label="Branch">
          <Dropdown aria-label="Branch" value={data.branches.find((b) => b.id === data.branch)?.label ?? 'All branches'} selectedOptions={[data.branch]} style={{ minWidth: 200 }}
            onOptionSelect={(_, d) => set({ branch: d.optionValue ?? '' })}>
            <Option value="">All branches</Option>{data.branches.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
          </Dropdown>
        </Field>
        <span className={s.grow} />
        {loading && <Spinner size="tiny" />}
        <ExportMenu url="/office-api/spend-analytics/export" query={query} total={data.suppliers.length + data.months.length || null} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Cards items={[
        { label: 'Spend (before VAT)', value: <>{m(t.spend)}{sub(`${t.bills} bill(s) from ${t.suppliers} supplier(s)`)}</> },
        { label: 'Bought on an approved order', value: <>{c.on_order_pct} %{sub(`${m(t.spend - c.on_order)} without one`)}</> },
        { label: 'Under contracts', value: <>{c.on_contract_pct} %{sub(`${m(c.on_contract)} at agreed prices`)}</> },
        { label: 'Biggest supplier / biggest five', value: <>{k.top1} % / {k.top5} %{sub('of all spend')}</> },
        { label: 'Billed above order prices', value: <>{m(v.above)}{sub(`on ${v.bills_above} bill(s); ${v.held} needed approval`)}</> },
        { label: 'Saved by asking for quotes', value: <>{m(rfq.saved)}{sub(`${rfq.rfqs} request(s) awarded${rfq.quotes_per_rfq !== null ? `, ${rfq.quotes_per_rfq} quotes each, ${rfq.response_pct} % of suppliers answered` : ''}`)}</> },
      ]} />
      <div style={{ height: 14 }} />
      <Panel title="Spend by month" about="The twelve months up to the end of the period.">
        <Table size="small" aria-label="Spend by month">
          <TableHeader><TableRow><TableHeaderCell>Month</TableHeaderCell><TableHeaderCell button={END}>Spend</TableHeaderCell><TableHeaderCell className={s.barCol}><span style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0 0 0 0)' }}>Bar</span></TableHeaderCell></TableRow></TableHeader>
          <TableBody>{data.months.map((x) => (
            <TableRow key={x.month}><TableCell>{x.month}</TableCell><TableCell style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{m(x.spend)}</TableCell>
              <TableCell className={s.barCol} aria-hidden="true"><div className={s.track} style={{ width: `${Math.round((100 * x.spend) / peak)}%` }} /></TableCell></TableRow>
          ))}</TableBody>
        </Table>
      </Panel>
      <div className={s.two}>
        <Panel title="By supplier" about="The fifteen biggest.">
          <Figures label="By supplier" head={['Supplier', 'Bills', 'Spend', 'Share']} empty="No supplier bills in this period."
            rows={data.suppliers.map((r) => [r.name, r.bills, m(r.spend), `${r.share} %`])} />
        </Panel>
        <Panel title="By category" about="The product's category for goods; the account for services and charges.">
          <Figures label="By category" head={['Category', 'Spend', 'Share']} empty="No supplier bills in this period."
            rows={data.categories.map((r) => [r.name, m(r.spend), `${r.share} %`])} />
        </Panel>
        <Panel title="How it was bought" about="Spend without an approved order skipped the approval step; rent and utilities usually have none.">
          <Figures label="How it was bought" head={['Bought', 'Spend']} empty="" rows={[['On an approved purchase order', m(c.on_order)],
            ['… of which under a contract or blanket order', m(c.on_contract)], ['Received without an order', m(c.received_no_order)],
            ['Billed with no order (charges, services)', m(c.no_order)]]} />
        </Panel>
        <Panel title="Services and charges by department" about="Goods for resale have no department.">
          <Figures label="By department" head={['Department', 'Spend']} empty="No services or charges in this period." rows={data.departments.map((r) => [r.name, m(r.spend)])} />
        </Panel>
      </div>
    </div>
  )
}
