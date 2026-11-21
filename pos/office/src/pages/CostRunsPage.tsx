import { Text } from '@fluentui/react-components'
import { useRef } from 'react'
import { api, type CostRunList, type CostRunRow } from '../api'
import { money } from '../home/format'
import { Cards } from '../list/ListParts'
import { day, ProcList, ToneBadge } from './ProcurementParts'

/**
 * Cost runs (part 19): products loaded without a cost get one worked out from their selling price (÷ a factor, or less
 * a profit percentage), for all products or a selection. A run's page is the preview; nothing moves until it is posted,
 * and a posted run can be undone. Search by number; filter by status.
 */
export function CostRunsPage() {
  const cur = useRef<CostRunList | null>(null)
  const m = (x: number) => (cur.current ? money(x, cur.current.currency, cur.current.exponent) : String(x))
  return (
    <ProcList<CostRunRow> title="Cost runs" hint="A cost worked out from the selling price, for products loaded without one. Preview first; nothing moves until it is posted."
      classic="/admin/stock-cost-runs" label="Cost runs" placeholder="Number" create="/office/cost-runs/new" createLabel="New cost run"
      load={((q: string, signal: AbortSignal) => api.costRuns(q, signal).then((d) => { cur.current = d; return d })) as never}
      empty="No cost runs match." open={(r) => `/office/cost-runs/${r.id}`}
      cards={(d) => <Cards items={[{ label: 'Shown', value: d.total.toLocaleString('en-GB') },
        { label: 'Waiting for approval', value: (d.waiting as number).toLocaleString('en-GB'), tone: d.waiting ? 'var(--zx-warn)' : undefined }]} />}
      columns={[
        { key: 'number', label: 'No.', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
        { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
        { key: 'factor', label: 'Factor', render: (r) => r.factor },
        { key: 'date', label: 'Date in the books', render: (r) => day(r.effective_on) },
        { key: 'value', label: 'Stock value', end: true, render: (r) => m(r.stock_value) },
        { key: 'past', label: 'Past sales', end: true, render: (r) => m(r.catch_up) },
        { key: 'lines', label: 'Lines', end: true, render: (r) => r.lines.toLocaleString('en-GB') },
        { key: 'skipped', label: 'Skipped', end: true, render: (r) => r.skipped.toLocaleString('en-GB') },
        { key: 'by', label: 'By', render: (r) => r.by ?? '—' },
      ]} />
  )
}
