import { Badge, Button, Dropdown, makeStyles, Option, SearchBox, Tag, TagGroup, Text, ToggleButton, tokens } from '@fluentui/react-components'
import { HandRightRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type Option as Choice, type RequestOptions, type RequestRow } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, PeriodButtons, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams, type Params } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 180px', maxWidth: '260px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

export const REQUEST_STATUS: Record<string, 'informative' | 'warning' | 'success' | 'danger' | 'subtle'> = {
  requested: 'warning', approved: 'warning', supplied: 'informative', received: 'success', rejected: 'danger', cancelled: 'subtle',
}

/**
 * Stock requests (release 3): one branch asking another for stock. Between two industries both sides approve before
 * anything is sent; sending makes a transfer, and receiving it closes the request. Filter by status, by who asked and
 * who was asked, between industries only, or only those waiting on you. A row opens the request; Ask for stock opens the form.
 */
export function RequestsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<RequestOptions | null>(null)
  const { data, loading, error } = useList(api.requests, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.requestsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const label = (opts: Choice[] | undefined, id: string) => opts?.find((o) => o.id === id)?.label ?? id
  const toggle = (key: string) => set({ [key]: str(params, key) === '1' ? '' : '1' })
  const columns: Column<RequestRow>[] = [
    { key: 'number', label: 'Request', render: (r) => <Text weight="semibold">{r.number}</Text> },
    { key: 'for', label: 'For', render: (r) => r.for ?? '—' },
    { key: 'asked_of', label: 'Asked of', render: (r) => <>{r.asked_of ?? '—'}{r.cross && <> <Badge appearance="outline" size="small">Between industries</Badge></>}</> },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={REQUEST_STATUS[r.status] ?? 'informative'}>{r.status_label}</Badge> },
    { key: 'lines', label: 'Lines', end: true, render: (r) => r.lines },
    { key: 'created', label: 'Asked', sort: 'created_at', render: (r) => <>{when(r.created_at)}<Text block size={200} className={s.muted}>{r.by}</Text></> },
  ]
  const pick = (key: string, all: string, opts: Choice[] | undefined, width = 170) => (
    <Dropdown aria-label={all} value={str(params, key) ? label(opts, str(params, key)) : all} selectedOptions={[str(params, key)]}
      onOptionSelect={(_, d) => set({ [key]: d.optionValue ?? '' })} style={{ minWidth: width }}>
      <Option value="">{all}</Option>{(opts ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
    </Dropdown>
  )
  const chips: { key: string; text: string; clear: Params }[] = [
    ...(str(params, 'to_wh') ? [{ key: 'to_wh', text: `For: ${label(options?.warehouses, str(params, 'to_wh'))}`, clear: { to_wh: '' } }] : []),
    ...(str(params, 'from_wh') ? [{ key: 'from_wh', text: `Asked of: ${label(options?.warehouses, str(params, 'from_wh'))}`, clear: { from_wh: '' } }] : []),
  ]

  return (
    <div className={page.page}>
      <PageHead title="Stock requests" hint="Ask another branch for stock. Between industries both sides approve first; sending makes a transfer." classic="/admin/stock-requests" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Request number" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search stock requests" />
        <PeriodButtons value={str(params, 'period')} onChange={(v) => set({ period: v })} />
        {pick('status', 'Any status', options?.statuses, 140)}
        {pick('to_wh', 'For any warehouse', options?.warehouses)}
        {pick('from_wh', 'Asked of anyone', options?.warehouses)}
        <ToggleButton checked={str(params, 'waiting') === '1'} onClick={() => toggle('waiting')}>Waiting on you</ToggleButton>
        <ToggleButton checked={str(params, 'cross') === '1'} onClick={() => toggle('cross')}>Between industries</ToggleButton>
        <ExportMenu list="requests" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_create && <Button appearance="primary" icon={<HandRightRegular />} onClick={() => navigate('/office/requests/new')}>Ask for stock</Button>}
      </div>
      {chips.length > 0 && (
        <TagGroup style={{ marginBottom: 10 }} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Requests shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Open', value: data.open.toLocaleString('en-GB') },
        { label: 'Waiting on you', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined },
        { label: 'Sent, not yet received', value: data.sent.toLocaleString('en-GB') },
      ]} />}
      <DataTable label="Stock requests" columns={columns} rows={data?.rows ?? []} sort="created_at" dir={data?.dir ?? 'desc'} loading={loading}
        empty="No stock requests match. Choose All, or clear a filter." onSort={(_, dir) => set({ dir })} onOpen={(r) => navigate(`/office/requests/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
