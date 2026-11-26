import { Badge, Button, Dropdown, makeStyles, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type WaybillRow } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { day } from './SupplierReturnsPage'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

export const WB_TONE: Record<string, 'informative' | 'warning' | 'success' | 'subtle'> = { prepared: 'informative', dispatched: 'warning', delivered: 'success', cancelled: 'subtle' }

/**
 * Waybills (part 17): the papers that travel with goods — to customers, between branches, back to suppliers — and the
 * supplier's waybill on goods received. Search by number, document, reference, receiver or vehicle; filter by status
 * and kind. A row opens the waybill; New waybill prepares one from a document.
 */
export function WaybillsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.waybills, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const pick = (key: string, label: string, options: { id: string; label: string }[] | undefined) => {
    const v = str(params, key)
    return (
      <Dropdown aria-label={label} value={v ? options?.find((x) => x.id === v)?.label ?? '' : `Any ${label.toLowerCase()}`} selectedOptions={[v]}
        onOptionSelect={(_, d) => set({ [key]: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
        <Option value="">{`Any ${label.toLowerCase()}`}</Option>{(options ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      </Dropdown>
    )
  }
  const columns: Column<WaybillRow>[] = [
    { key: 'number', label: 'Waybill', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'date', label: 'Date', render: (r) => day(r.date) },
    { key: 'kind', label: 'For', render: (r) => <>{r.kind}<Text block size={200} className={s.muted}>{r.source}</Text></> },
    { key: 'to', label: 'To', render: (r) => r.to ?? '—' },
    { key: 'vehicle', label: 'Vehicle', render: (r) => <>{r.vehicle ?? '—'}{r.driver && <Text block size={200} className={s.muted}>{r.driver}</Text>}</> },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={WB_TONE[r.status] ?? 'informative'}>{r.status_label}</Badge> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Waybills" hint="The papers that travel with goods: to customers, between branches, back to suppliers, and the supplier's waybill on what was received." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, document, receiver or vehicle" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search waybills" />
        {pick('status', 'Status', data?.statuses)}
        {pick('kind', 'Kind', data?.kinds)}
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/waybills/new')}>New waybill</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      <DataTable label="Waybills" columns={columns} rows={data?.rows ?? []} sort="date" dir="desc" loading={loading}
        empty="No waybills match." onSort={() => undefined} onOpen={(r) => navigate(`/office/waybills/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
