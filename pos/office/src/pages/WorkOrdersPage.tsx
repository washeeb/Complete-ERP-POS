import { Button, Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Switch, Text, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api } from '../api'
import type { WorkOrderList } from '../api'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = WorkOrderList['rows'][number]
const OPEN = [{ id: '1', label: 'Open only' }, { id: '0', label: 'Done and cancelled' }, { id: 'all', label: 'All' }]

/**
 * Maintenance → Work orders (part 31): servicing that is due (opened by the plans), breakdowns and customer visits, open
 * only by default; mine and kind. New work order; a row opens it, where time and parts are logged and “Done” puts the
 * equipment back to work.
 */
export function WorkOrdersPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const cur = useRef<WorkOrderList | null>(null)
  const { data, loading, error } = useList((qq, sig) => api.workOrders(qq, sig).then((d) => { cur.current = d; return d }), serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const open = data?.open ?? (str(params, 'open') || '1')
  const m = (x: number) => (cur.current ? money(x, cur.current.currency, cur.current.exponent) : '')
  return (
    <div className={page.page}>
      <PageHead title="Work orders" hint="Servicing that is due, breakdowns and customer visits. Log time and parts on each; “Done” puts the equipment back to work." classic="/admin/maintenance-orders" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or what is to be done" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search work orders" />
        <Dropdown aria-label="Open or closed" value={OPEN.find((x) => x.id === open)?.label ?? 'Open only'} selectedOptions={[open]} style={{ minWidth: 150 }}
          onOptionSelect={(_, d) => set({ open: d.optionValue ?? '1' })}>
          {OPEN.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        {data?.work && <Switch label="Mine" checked={str(params, 'mine') === '1'} onChange={(_, d) => set({ mine: d.checked ? '1' : '' })} />}
        <Pick label="Kind" any="Any kind" options={data?.kinds ?? []} value={str(params, 'kind')} onChange={(v) => set({ kind: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/maintenance/orders/new')}>New work order</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Work orders" rows={data?.rows ?? []} sort="added" dir="desc" loading={loading}
        empty="No work orders. Plans open work orders by themselves; report a breakdown on the equipment, or from a ticket."
        onSort={() => undefined} onOpen={(r) => navigate(`/office/maintenance/orders/${r.id}`)}
        columns={[
          { key: 'number', label: 'No.', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'title', label: 'Work order', render: (r) => <><Text weight="semibold">{r.title}</Text>{r.asset && <Text size={200} block className={s.muted}>{r.asset}</Text>}</> },
          { key: 'kind', label: 'Kind', render: (r) => r.kind },
          { key: 'priority', label: 'Priority', render: (r) => <ToneBadge tone={r.priority_tone} label={r.priority} /> },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'due', label: 'By', render: (r) => <span style={r.late ? { color: tokens.colorPaletteRedForeground1 } : undefined}>{day(r.due)}</span> },
          { key: 'who', label: 'Who', render: (r) => r.who ?? <Text className={s.muted}>Nobody yet</Text> },
          { key: 'cost', label: 'Cost', end: true, render: (r) => m(r.cost) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
