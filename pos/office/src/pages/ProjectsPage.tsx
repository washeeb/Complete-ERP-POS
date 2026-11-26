import { Button, Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api } from '../api'
import type { ProjectList } from '../api'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = ProjectList['rows'][number]

/**
 * Projects → Projects (part 27): jobs for customers (milestones or time and materials) and internal ones, in progress by
 * default, with what they cost so far against the budget and when they are due. New project; a row opens the project.
 */
export function ProjectsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const cur = useRef<ProjectList | null>(null)
  const { data, loading, error } = useList((qq, sig) => api.projects(qq, sig).then((d) => { cur.current = d; return d }), serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = data?.status ?? (str(params, 'status') || 'active')
  const m = (x: number) => (cur.current ? money(x, cur.current.currency, cur.current.exponent) : '')
  return (
    <div className={page.page}>
      <PageHead title="Projects" hint="A project collects its costs (stock, bills, expenses, time) by itself and shows its profit." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search projects" />
        <Dropdown aria-label="Status" value={status === 'all' ? 'Any status' : data?.statuses.find((x) => x.id === status)?.label ?? 'In progress'} selectedOptions={[status]} style={{ minWidth: 140 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? 'active' })}>
          {(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}<Option value="all">Any status</Option>
        </Dropdown>
        <Pick label="Billed" any="Any billing" options={data?.billings ?? []} value={str(params, 'billing')} onChange={(v) => set({ billing: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/projects/new')}>New project</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Projects" rows={data?.rows ?? []} sort="added" dir="desc" loading={loading} empty="No projects match." onSort={() => undefined}
        onOpen={(r) => navigate(`/office/projects/${r.id}`)}
        columns={[
          { key: 'number', label: 'No.', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'name', label: 'Project', render: (r) => <><Text weight="semibold">{r.name}</Text><Text size={200} block className={s.muted}>{r.customer ?? 'Internal'}</Text></> },
          { key: 'billing', label: 'Billed', render: (r) => r.billing },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'cost', label: 'Cost', end: true, render: (r) => <>{m(r.cost)}{r.budget !== null && <Text size={200} block className={s.muted}>of {m(r.budget)}</Text>}</> },
          { key: 'due', label: 'Due', render: (r) => <span style={r.late ? { color: tokens.colorPaletteRedForeground1 } : undefined}>{day(r.due)}</span> },
          { key: 'manager', label: 'Manager', render: (r) => r.manager ?? '—' },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
