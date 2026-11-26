import { Button, Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api, type CrmLeadList, type CrmLeadRow, type Option as Choice } from '../api'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ToneBadge, useProcStyles } from './ProcurementParts'

const OPEN: [string, string][] = [['1', 'Open only'], ['0', 'Closed'], ['all', 'All']]

export function Pick({ label, any, options, value, onChange, width = 170 }: { label: string; any: string; options: Choice[]; value: string; onChange: (v: string) => void; width?: number }) {
  return (
    <Dropdown aria-label={label} value={value ? options.find((o) => o.id === value)?.label ?? '' : any} selectedOptions={[value]} style={{ minWidth: width }}
      onOptionSelect={(_, d) => onChange(d.optionValue ?? '')}>
      <Option value="">{any}</Option>{options.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
    </Dropdown>
  )
}

/**
 * CRM → Leads (part 25): everyone who may buy — who, how to reach them, what they want, where they came from, status,
 * what it may be worth and who follows up. Open ones by default. A row opens the lead (its form, follow-ups, Make
 * customer, Lost).
 */
export function CrmLeadsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const cur = useRef<CrmLeadList | null>(null)
  const { data, loading, error } = useList((q, sig) => api.crmLeads(q, sig).then((d) => { cur.current = d; return d }), serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const open = str(params, 'open') || '1'
  return (
    <div className={page.page}>
      <PageHead title="Leads" hint="Everyone who may buy: plan the next step with “Follow-up”, then “Make customer” or close them as lost." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name, business, phone or e-mail" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search leads" />
        <Dropdown aria-label="Open or closed" value={OPEN.find(([k]) => k === open)?.[1] ?? 'Open only'} selectedOptions={[open]} style={{ minWidth: 130 }} onOptionSelect={(_, d) => set({ open: d.optionValue ?? '1' })}>
          {OPEN.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <Pick label="From" any="From anywhere" options={data?.sources ?? []} value={str(params, 'source')} onChange={(v) => set({ source: v })} />
        <Pick label="Follows up" any="Anyone" options={data?.people ?? []} value={str(params, 'owner')} onChange={(v) => set({ owner: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/crm/leads/new')}>New lead</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<CrmLeadRow> label="Leads" rows={data?.rows ?? []} sort="added" dir="desc" loading={loading} empty="No leads match." onSort={() => undefined}
        onOpen={(r) => navigate(`/office/crm/leads/${r.id}`)}
        columns={[
          { key: 'number', label: 'No.', render: (r) => <Text font="monospace" size={200} className={s.muted}>{r.number}</Text> },
          { key: 'name', label: 'Name', render: (r) => <><Text weight="semibold">{r.name}</Text>{r.company && <Text size={200} block className={s.muted}>{r.company}</Text>}</> },
          { key: 'phone', label: 'Phone', render: (r) => <>{r.phone ?? '—'}{r.email && <Text size={200} block className={s.muted}>{r.email}</Text>}</> },
          { key: 'interest', label: 'Interested in', render: (r) => <>{r.interest ?? '—'}<Text size={200} block className={s.muted}>From: {r.source}</Text></> },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'worth', label: 'Worth', end: true, render: (r) => (r.worth === null || !cur.current ? '—' : money(r.worth, cur.current.currency, cur.current.exponent)) },
          { key: 'owner', label: 'Follows up', render: (r) => r.owner ?? '—' },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
