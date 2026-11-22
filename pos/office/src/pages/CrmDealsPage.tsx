import { Button, Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular, BoardRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api, type CrmDealList, type CrmDealRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { day } from './ProcurementParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * CRM → Deals (part 25): sales being worked on — what they want and for whom, stage, value (and weighted by the stage's
 * chance), expected close, quotation and who works it. In progress by default. A row opens the deal.
 */
export function CrmDealsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const cur = useRef<CrmDealList | null>(null)
  const { data, loading, error } = useList((q, sig) => api.crmDeals(q, sig).then((d) => { cur.current = d; return d }), serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const m = (x: number) => (cur.current ? money(x, cur.current.currency, cur.current.exponent) : '')
  const status = data?.status ?? (str(params, 'status') || 'open')
  return (
    <div className={page.page}>
      <PageHead title="Deals" hint="Sales you are working on. A deal is won by itself when its quotation is confirmed as an order." classic="/admin/crm-deals" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or what they want" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search deals" />
        <Dropdown aria-label="Status" value={status === 'all' ? 'Any status' : data?.statuses.find((x) => x.id === status)?.label ?? 'In progress'} selectedOptions={[status]} style={{ minWidth: 150 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? 'open' })}>
          {(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}<Option value="all">Any status</Option>
        </Dropdown>
        <Pick label="Stage" any="Any stage" options={data?.stages ?? []} value={str(params, 'stage')} onChange={(v) => set({ stage: v })} />
        <Pick label="Works it" any="Anyone" options={data?.people ?? []} value={str(params, 'owner')} onChange={(v) => set({ owner: v })} />
        <span className={s.grow} />
        <Button icon={<BoardRegular />} onClick={() => navigate('/office/crm/pipeline')}>Pipeline</Button>
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/crm/deals/new')}>New deal</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<CrmDealRow> label="Deals" rows={data?.rows ?? []} sort="added" dir="desc" loading={loading} empty="No deals match." onSort={() => undefined}
        onOpen={(r) => navigate(`/office/crm/deals/${r.id}`)}
        columns={[
          { key: 'number', label: 'No.', render: (r) => <Text font="monospace" size={200} className={s.muted}>{r.number}</Text> },
          { key: 'title', label: 'What they want', render: (r) => <><Text weight="semibold">{r.title}</Text><Text size={200} block className={s.muted}>{r.contact}</Text></> },
          { key: 'stage', label: 'Stage', render: (r) => <ToneBadge tone={r.tone} label={r.stage ?? '—'} /> },
          { key: 'value', label: 'Value', end: true, render: (r) => <>{m(r.value)}{r.weighted !== null && <Text size={200} block className={s.muted}>Weighted {m(r.weighted)}</Text>}</> },
          { key: 'closes', label: 'Closes', render: (r) => <span style={r.late ? { color: 'var(--zx-bad)' } : undefined}>{day(r.closes)}</span> },
          { key: 'quote', label: 'Quotation', render: (r) => r.quote ?? '—' },
          { key: 'owner', label: 'Works it', render: (r) => r.owner ?? '—' },
          ...(status === 'lost' ? [{ key: 'why', label: 'Why lost', render: (r: CrmDealRow) => r.lost_reason ?? '' }] : []),
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
