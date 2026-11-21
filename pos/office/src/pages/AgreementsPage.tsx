import { Dropdown, Option, Text } from '@fluentui/react-components'
import { api, type AgreementRow, type Option as Choice } from '../api'
import { day, ProcList, ToneBadge } from './ProcurementParts'

/**
 * Contracts and blanket orders (part 18): prices agreed with a supplier for a period (and, for a blanket order, the most
 * that may be ordered). Orders then take those prices and count against any limits. Search by number, title or
 * supplier; filter by status and supplier.
 */
export function AgreementsPage() {
  return (
    <ProcList<AgreementRow> title="Contracts and blanket orders" hint="Prices agreed with a supplier for a period. Orders take those prices and count against any limits."
      classic="/admin/purchase-agreements" load={api.agreements as never} label="Contracts and blanket orders" placeholder="Number, title or supplier"
      create="/office/agreements/new" createLabel="New agreement" empty="No contracts or blanket orders match." open={(r) => `/office/agreements/${r.id}`}
      filters={(data, value, set) => {
        const v = value('supplier')
        const suppliers = (data?.suppliers ?? []) as Choice[]
        return (
          <Dropdown aria-label="Supplier" value={v ? suppliers.find((x) => x.id === v)?.label ?? '' : 'Any supplier'} selectedOptions={[v]}
            onOptionSelect={(_, d) => set('supplier', d.optionValue ?? '')} style={{ minWidth: 180 }}>
            <Option value="">Any supplier</Option>{suppliers.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
          </Dropdown>
        )
      }}
      columns={[
        { key: 'number', label: 'Number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
        { key: 'supplier', label: 'Supplier', render: (r) => r.supplier ?? '—' },
        { key: 'title', label: 'Title', render: (r) => r.title },
        { key: 'kind', label: 'Kind', render: (r) => r.kind },
        { key: 'ends', label: 'Until', render: (r) => day(r.ends_on) },
        { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
      ]} />
  )
}
