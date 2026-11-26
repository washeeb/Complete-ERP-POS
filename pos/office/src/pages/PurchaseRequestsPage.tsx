import { Text } from '@fluentui/react-components'
import { api, type PrRow } from '../api'
import { Cards } from '../list/ListParts'
import { day, ProcList, ToneBadge } from './ProcurementParts'

/**
 * Purchase requests (part 18): what a store needs, asked by the stock controller; a buyer orders it (a draft purchase
 * order), asks several suppliers for prices, or rejects it. Search by number; filter by status. A row opens the request.
 */
export function PurchaseRequestsPage() {
  return (
    <ProcList<PrRow> title="Purchase requests" hint="What a store needs. A buyer orders it, asks suppliers for prices, or rejects it."
      load={api.purchaseRequests as never} label="Purchase requests" placeholder="Request number" create="/office/purchase-requests/new" createLabel="New purchase request"
      empty="No purchase requests match." open={(r) => `/office/purchase-requests/${r.id}`}
      cards={(d) => <Cards items={[{ label: 'Shown', value: d.total.toLocaleString('en-GB') },
        { label: 'Waiting for a buyer', value: (d.waiting as number).toLocaleString('en-GB'), tone: d.waiting ? 'var(--zx-warn)' : undefined }]} />}
      columns={[
        { key: 'number', label: 'Request', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
        { key: 'asked', label: 'Asked on', render: (r) => day(r.asked_on) },
        { key: 'store', label: 'Store', render: (r) => r.store ?? '—' },
        { key: 'lines', label: 'Lines', end: true, render: (r) => r.lines },
        { key: 'needed', label: 'Needed by', render: (r) => day(r.needed_by) },
        { key: 'by', label: 'Asked by', render: (r) => r.by ?? '—' },
        { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
      ]} />
  )
}
