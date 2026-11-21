import { Text } from '@fluentui/react-components'
import { api, type RfqRow } from '../api'
import { when } from '../list/ListParts'
import { ProcList, ToneBadge } from './ProcurementParts'

/**
 * Requests for quotation (part 18): one list to several suppliers; their prices stay sealed until the closing time,
 * then are compared side by side and awarded into draft purchase orders. Search by number or title; filter by status.
 */
export function RfqsPage() {
  return (
    <ProcList<RfqRow> title="Requests for quotation" hint="Ask several suppliers for prices on the same list, compare them side by side and award the best." classic="/admin/rfqs"
      load={api.rfqs as never} label="Requests for quotation" placeholder="Number or title" create="/office/rfqs/new" createLabel="New request for quotation"
      empty="No requests for quotation match." open={(r) => `/office/rfqs/${r.id}`}
      columns={[
        { key: 'number', label: 'Request', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
        { key: 'title', label: 'Title', render: (r) => r.title },
        { key: 'deliver', label: 'Deliver to', render: (r) => r.deliver_to ?? '—' },
        { key: 'items', label: 'Items', end: true, render: (r) => r.items },
        { key: 'quotes', label: 'Quotes', render: (r) => r.quotes },
        { key: 'closes', label: 'Close', render: (r) => when(r.closes_at) },
        { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
      ]} />
  )
}
