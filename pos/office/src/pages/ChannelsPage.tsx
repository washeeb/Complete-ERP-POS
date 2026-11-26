import { Button, MessageBar, MessageBarBody, Text, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { api } from '../api'
import type { ChannelList } from '../api'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ShowFilter, Status } from './CompanyParts'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = ChannelList['rows'][number]

/**
 * Online → Channels (part 33): the built-in shop page and connected stores (WooCommerce, Shopify…), with the branch that
 * fills their orders, whether they are live, whether updates are waiting or failed, and the shop or webhook address. New
 * channel; a row opens it.
 */
export function ChannelsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.channels, serialize(params), 0)
  return (
    <div className={page.page}>
      <PageHead title="Channels" hint="Your shop page and connected stores: what they sell, from which branch, and whether they are up to date." classic="/admin/sales-channels" />
      <div className={s.bar}>
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/online/channels/new')}>New channel</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Channels" rows={data?.rows ?? []} sort="name" dir="asc" loading={loading}
        empty="No online channels yet. Add the built-in shop, or connect a WooCommerce or Shopify store." onSort={() => undefined}
        onOpen={(r) => navigate(`/office/online/channels/${r.id}`)}
        columns={[
          { key: 'name', label: 'Channel', render: (r) => <><Text weight="semibold">{r.name}</Text><Text size={200} block className={s.muted}>{r.type}</Text></> },
          { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.tone} label={r.status} />{r.archived && <Status r={r} />}</> },
          { key: 'updates', label: 'Updates', render: (r) => <span style={r.failed ? { color: tokens.colorPaletteRedForeground1 } : undefined}>{r.updates}</span> },
          { key: 'address', label: 'Address / webhook', render: (r) => (r.address ? <Text size={200} style={{ overflowWrap: 'anywhere' }}>{r.address}</Text> : '—') },
        ]} />
    </div>
  )
}
