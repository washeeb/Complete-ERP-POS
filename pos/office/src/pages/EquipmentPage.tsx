import { Button, MessageBar, MessageBarBody, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { EquipmentList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ShowFilter, Status } from './CompanyParts'
import { Pick } from './CrmLeadsPage'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = EquipmentList['rows'][number]
const WHOSE = [{ id: 'own', label: 'Ours' }, { id: 'customer', label: 'Customers’' }]

/**
 * Maintenance → Equipment (part 31): what you look after — your own and customers' equipment — with its status, warranty,
 * meter and next service. New equipment; a row opens it with its servicing plans and work orders.
 */
export function EquipmentPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.equipment, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className={page.page}>
      <PageHead title="Equipment" hint="What you look after, with its servicing plans and work orders: what it costs to keep running and how long it was down." classic="/admin/equipment" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, name, serial, make or model" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search equipment" />
        <Pick label="Whose" any="Ours and customers’" options={WHOSE} value={str(params, 'owner')} onChange={(v) => set({ owner: v })} />
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <Pick label="Category" any="Any category" options={data?.categories ?? []} value={str(params, 'category')} onChange={(v) => set({ category: v })} />
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/maintenance/equipment/new')}>New equipment</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Equipment" rows={data?.rows ?? []} sort="number" dir="asc" loading={loading}
        empty="No equipment yet. Add what you look after — generators, fridges, vehicles, tills — and customers’ equipment under warranty or contract."
        onSort={() => undefined} onOpen={(r) => navigate(`/office/maintenance/equipment/${r.id}`)}
        columns={[
          { key: 'number', label: 'No.', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'name', label: 'Equipment', render: (r) => <><Text weight="semibold">{r.name}</Text>{r.where && <Text size={200} block className={s.muted}>{r.where}</Text>}</> },
          { key: 'category', label: 'Category', render: (r) => r.category },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.tone} label={r.status_label} />{r.archived && <Status r={r} />}</> },
          { key: 'warranty', label: 'Warranty', render: (r) => <span style={r.warranty_over ? { color: tokens.colorNeutralForeground3 } : undefined}>{day(r.warranty)}</span> },
          { key: 'meter', label: 'Meter', render: (r) => r.meter ?? '—' },
          { key: 'next', label: 'Next service', render: (r) => day(r.next) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
