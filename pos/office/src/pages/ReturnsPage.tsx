import { Button, Dropdown, Link, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { PrintRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ReturnRow, type ReturnsOptions } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { download, ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, PeriodButtons, usePageStyles } from '../list/ListParts'
import { list, serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  items: { maxWidth: '280px' },
})

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')

/**
 * Returns (release 7): till returns, newest first — who took each, who approved it, the reason, the items and where
 * they went (shelf or quarantine), the refund and how it was paid back — with the 30-day summary that names one person
 * doing most of them. Filter by period, who took it, reason, product and approval. A return opens its receipt; the
 * return slip prints for signing and filing.
 */
export function ReturnsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<ReturnsOptions | null>(null)
  const [error, setError] = useState('')
  const { data, loading, error: listError } = useList(api.returns, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.returnsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const label = (o: { id: string; label: string }[] | undefined, id: string) => o?.find((x) => x.id === id)?.label ?? id
  const many = (key: 'reasons' | 'products', o: { id: string; label: string }[] | undefined, none: string, width: number) => {
    const chosen = list(params, key)
    return (
      <Dropdown multiselect aria-label={none} style={{ minWidth: width }} selectedOptions={chosen}
        value={chosen.length === 0 ? none : chosen.length === 1 ? label(o, chosen[0]) : `${chosen.length} chosen`}
        onOptionSelect={(_, d) => set({ [key]: d.selectedOptions })}>
        {(o ?? []).map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
      </Dropdown>
    )
  }
  const slip = async (r: ReturnRow) => { const problem = await download(r.slip, `return-${r.receipt_no}.pdf`); if (problem) setError(problem) }
  const columns: Column<ReturnRow>[] = [
    { key: 'receipt', label: 'Return', sort: 'receipt_no', render: (r) => <Link onClick={(e) => { e.stopPropagation(); navigate(r.open) }}><Text font="monospace">{r.receipt_no}</Text></Link> },
    { key: 'when', label: 'When', sort: 'occurred_at', render: (r) => <>{when(r.occurred_at)}{r.branch && <Text block size={200} className={s.muted}>{r.branch}</Text>}</> },
    { key: 'by', label: 'By', sort: 'cashier_name', render: (r) => <>{r.by ?? '—'}<Text block size={200} className={s.muted}>{r.approved_by ? `Approved by ${r.approved_by}` : 'Not approved'}</Text></> },
    { key: 'reason', label: 'Reason', render: (r) => <>{r.reason ?? '—'}{r.details && <Text block size={200} className={s.muted}>{r.details}</Text>}</> },
    { key: 'items', label: 'Items', render: (r) => <div className={s.items}>{r.items}<Text block size={200} className={s.muted}>Went to {r.to.toLowerCase()}</Text></div> },
    { key: 'refund', label: 'Refund', sort: 'total_minor', end: true, render: (r) => <><Text weight="semibold">{m(r.refund)}</Text><Text block size={200} className={s.muted}>{r.paid_by}</Text></> },
    { key: 'slip', label: '', render: (r) => <Button size="small" appearance="subtle" icon={<PrintRegular />} aria-label={`Return slip ${r.receipt_no}`} onClick={(e) => { e.stopPropagation(); void slip(r) }}>Slip</Button> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Returns" hint="Goods brought back at the till or in the back office: who took them, who approved them, why, and how the money went back." />
      {options?.summary && <MessageBar intent={options.summary.includes('Worth a look') ? 'warning' : 'info'} style={{ marginBottom: 10 }}><MessageBarBody>{options.summary}</MessageBarBody></MessageBar>}
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Return number or person" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search returns" />
        <PeriodButtons value={str(params, 'period')} onChange={(period) => set({ period })} />
        <Dropdown aria-label="Taken by" style={{ minWidth: 150 }} value={str(params, 'teller') ? label(options?.tellers, str(params, 'teller')) : 'Anyone'} selectedOptions={[str(params, 'teller')]}
          onOptionSelect={(_, d) => set({ teller: d.optionValue ?? '' })}>
          <Option value="">Anyone</Option>{(options?.tellers ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        {many('reasons', options?.reasons, 'Any reason', 150)}
        {many('products', options?.products, 'Any product', 170)}
        <Dropdown aria-label="Approval" style={{ minWidth: 140 }} value={{ approved: 'Approved', none: 'Not approved' }[str(params, 'approval')] ?? 'Approved or not'}
          selectedOptions={[str(params, 'approval')]} onOptionSelect={(_, d) => set({ approval: d.optionValue ?? '' })}>
          <Option value="">Approved or not</Option><Option value="approved">Approved</Option><Option value="none">Not approved</Option>
        </Dropdown>
        <span className={s.grow} />
        <ExportMenu list="returns" query={serialize(params)} total={data?.total ?? null} />
      </div>
      {(listError || error) && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginBottom: 8 }}>{listError || error}</Text>}
      {data && <Cards items={[{ label: 'Returns shown', value: data.total.toLocaleString('en-GB') }, { label: 'Refunded', value: m(data.refunded) }]} />}
      <DataTable label="Returns" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'occurred_at'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No returns match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(r.open)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
