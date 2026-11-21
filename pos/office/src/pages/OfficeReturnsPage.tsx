import { Badge, Dropdown, makeStyles, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type OfficeReturnRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { Cards, PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
})

export const RETURN_TONE: Record<string, 'warning' | 'success' | 'subtle'> = { submitted: 'warning', posted: 'success', cancelled: 'subtle' }

/**
 * Back-office returns (part 16): goods coming back from a receipt, entered in the back office (Return goods on the sale's
 * page) and approved by someone else before anything changes. Search by number or receipt; filter by status. A row
 * opens the return, to approve and refund it, cancel it, or print its slip.
 */
export function OfficeReturnsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.officeReturns, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const status = str(params, 'status')
  const columns: Column<OfficeReturnRow>[] = [
    { key: 'number', label: 'No.', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'entered', label: 'Entered', render: (r) => when(r.entered) },
    { key: 'receipt', label: 'Receipt', render: (r) => r.receipt ?? '—' },
    { key: 'refund', label: 'Refund', end: true, render: (r) => m(r.refund) },
    { key: 'as', label: 'Refund as', render: (r) => r.refund_as },
    { key: 'reason', label: 'Reason', render: (r) => r.reason },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={RETURN_TONE[r.status] ?? 'informative'}>{r.status_label}</Badge> },
    { key: 'by', label: 'By', render: (r) => r.by ?? '—' },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Back-office returns" hint="Goods coming back from a receipt, entered from the sale's page and approved by someone else. Open one to approve, cancel or print its slip." classic="/admin/office-returns" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or receipt" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search returns" />
        <Dropdown aria-label="Status" value={status ? data?.statuses.find((x) => x.id === status)?.label ?? '' : 'Any status'} selectedOptions={[status]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 190 }}>
          <Option value="">Any status</Option>{(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[{ label: 'Shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Waiting for approval', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined }]} />}
      <DataTable label="Back-office returns" columns={columns} rows={data?.rows ?? []} sort="entered" dir="desc" loading={loading}
        empty="No returns match. A return is started with Return goods on a sale's page." onSort={() => undefined} onOpen={(r) => navigate(`/office/office-returns/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
