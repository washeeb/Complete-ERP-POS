import { Badge, Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState, type ReactNode } from 'react'
import type { Option as Choice } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { str, useQueryParams, serialize } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

/** Shared looks of the Purchasing pages of part 18 (purchase requests, requests for quotation, contracts). */
export const useProcStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  about: { display: 'block', marginBottom: '10px', color: tokens.colorNeutralForeground3 },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '10px 18px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  one: { display: 'grid', gap: '10px' },
  line: { padding: '12px 0', borderBottom: `1px solid ${tokens.colorNeutralStroke2}` },
  lineHead: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px', flexWrap: 'wrap' },
  grid: { display: 'grid', gridTemplateColumns: 'minmax(0, 3fr) repeat(2, minmax(0, 1fr))', gap: '8px 10px', alignItems: 'start', '@media (max-width: 760px)': { gridTemplateColumns: '1fr' } },
  foot: { position: 'sticky', bottom: 0, zIndex: 2, display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0',
    backgroundColor: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

export const END = { style: { justifyContent: 'flex-end' } }
export const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
export const ToneBadge = ({ tone, label }: { tone: string; label: string }) => <Badge appearance="tint" color={tone as 'warning'}>{label}</Badge>

export function Panel({ title, about, actions, children }: { title: string; about?: string; actions?: ReactNode; children: ReactNode }) {
  const s = useProcStyles()
  return (
    <section className={s.panel} aria-label={title}>
      <div className={s.head}><h2 className={s.h2}>{title}</h2>{actions}</div>
      {about && <Text className={s.about}>{about}</Text>}
      {children}
    </section>
  )
}

export function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useProcStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text>{children}</Text></div>
}

type ListData<R> = { total: number; page: number; per: number; rows: R[]; can_create: boolean; statuses: Choice[] } & Record<string, unknown>

/**
 * A Purchasing list (part 18): search, status (and more filters given), the table, the pager, and New. Each list keeps
 * the current page's search and filters in the address.
 */
export function ProcList<R extends { id: string }>({ title, hint, load, label, placeholder, columns, empty, open, create, createLabel, filters, cards }: {
  title: string; hint: string; load: (q: string, signal: AbortSignal) => Promise<ListData<R>>; label: string; placeholder: string
  columns: Column<R>[]; empty: string; open: (r: R) => string; create: string; createLabel: string
  filters?: (data: ListData<R> | null, value: (k: string) => string, set: (k: string, v: string) => void) => ReactNode
  cards?: (data: ListData<R>) => ReactNode
}) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(load, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = str(params, 'status')
  return (
    <div className={page.page}>
      <PageHead title={title} hint={hint} />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder={placeholder} value={q} onChange={(_, d) => setQ(d.value)} aria-label={`Search ${label.toLowerCase()}`} />
        <Dropdown aria-label="Status" value={status ? data?.statuses.find((x) => x.id === status)?.label ?? '' : 'Any status'} selectedOptions={[status]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 190 }}>
          <Option value="">Any status</Option>{(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        {filters?.(data, (k) => str(params, k), (k, v) => set({ [k]: v }))}
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate(create)}>{createLabel}</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {data && cards?.(data)}
      <DataTable label={label} columns={columns} rows={data?.rows ?? []} sort="created" dir="desc" loading={loading} empty={empty} onSort={() => undefined} onOpen={(r) => navigate(open(r))} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
