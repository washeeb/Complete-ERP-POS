import { Badge, Button, Dropdown, makeStyles, Option, SearchBox, Text, ToggleButton, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type EcRow, type Option as Choice } from '../api'
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

export const EC_TONE: Record<string, 'warning' | 'informative' | 'success' | 'danger'> = { submitted: 'warning', approved: 'informative', paid: 'success', rejected: 'danger' }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Expense claims and petty cash (release 6): your own claims, and every claim in the branches where you approve or pay.
 * Search by number, description, who claimed or who was paid; filter by status or only yours. A row opens the claim
 * (Approve, Reject, Pay); New claim records money spent, with the receipt photo.
 */
export function ExpenseClaimsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<{ statuses: Choice[]; per: number[]; can_create: boolean } | null>(null)
  const { data, loading, error } = useList(api.expenseClaims, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.expenseClaimsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const label = (id: string) => options?.statuses.find((x) => x.id === id)?.label ?? id
  const columns: Column<EcRow>[] = [
    { key: 'number', label: 'Number', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'date', label: 'Date', sort: 'spent_on', render: (r) => day(r.spent_on) },
    { key: 'by', label: 'By', render: (r) => <>{r.by ?? '—'}{r.mine && <Text block size={200} className={s.muted}>you</Text>}</> },
    { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
    { key: 'description', label: 'Description', render: (r) => <>{r.description}{r.for && <Text block size={200} className={s.muted}>{r.for}</Text>}</> },
    { key: 'amount', label: 'Amount', sort: 'amount_minor', end: true, render: (r) => m(r.amount) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={EC_TONE[r.status] ?? 'informative'}>{label(r.status)}</Badge> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Expense claims" hint="Money people spent for the business (petty cash and claims). It is approved by someone else, then paid back." classic="/admin/expense-claims" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, description, who claimed or who was paid" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search expense claims" />
        <Dropdown aria-label="Status" value={str(params, 'status') ? label(str(params, 'status')) : 'Any status'} selectedOptions={[str(params, 'status')]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 180 }}>
          <Option value="">Any status</Option>{(options?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ToggleButton checked={str(params, 'mine') === '1'} onClick={() => set({ mine: str(params, 'mine') === '1' ? '' : '1' })}>Only mine</ToggleButton>
        <ExportMenu list="expense-claims" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/expense-claims/new')}>New claim</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Claims shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Amount', value: m(data.amount) },
        ...(data.waiting !== null ? [{ label: 'Waiting for your approval', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined }] : []),
        { label: 'Approved, to pay', value: m(data.to_pay) },
      ]} />}
      <DataTable label="Expense claims" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'number'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No expense claims match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/expense-claims/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
