import { Badge, Button, Dropdown, makeStyles, Option, SearchBox, Tag, TagGroup, Text, ToggleButton, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type Option as Choice, type TransferOptions, type TransferRow } from '../api'
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

export const TRANSFER_STATUS: Record<string, 'informative' | 'warning' | 'success' | 'subtle'> = { draft: 'informative', in_transit: 'warning', received: 'success', cancelled: 'subtle' }

/**
 * Transfers (release 3): stock moved between warehouses — a draft, dispatched (it leaves the sending warehouse and is
 * in transit), then received at the other end with what actually arrived; a difference is flagged for review. Filter by
 * status, from and to warehouse, or differences only. A row opens the transfer; New transfer opens the form.
 */
export function TransfersPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<TransferOptions | null>(null)
  const { data, loading, error } = useList(api.transfers, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.transfersOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const label = (opts: Choice[] | undefined, id: string) => opts?.find((o) => o.id === id)?.label ?? id
  const columns: Column<TransferRow>[] = [
    { key: 'number', label: 'Transfer', render: (r) => <Text weight="semibold">{r.number}</Text> },
    { key: 'route', label: 'From → To', render: (r) => `${r.from ?? '—'} → ${r.to ?? '—'}` },
    { key: 'status', label: 'Status', render: (r) => <>
      <Badge appearance="tint" color={TRANSFER_STATUS[r.status] ?? 'informative'}>{r.status_label}</Badge>
      {r.difference && <> <Badge appearance="tint" color="danger" size="small">Difference</Badge></>}
    </> },
    { key: 'lines', label: 'Lines', end: true, render: (r) => r.lines },
    { key: 'dispatched', label: 'Dispatched', render: (r) => when(r.dispatched_at) },
    { key: 'created', label: 'Created', sort: 'created_at', render: (r) => <>{when(r.created_at)}<Text block size={200} className={s.muted}>{r.by}</Text></> },
  ]
  const pick = (key: string, all: string, opts: Choice[] | undefined, width = 170) => (
    <Dropdown aria-label={all} value={str(params, key) ? label(opts, str(params, key)) : all} selectedOptions={[str(params, key)]}
      onOptionSelect={(_, d) => set({ [key]: d.optionValue ?? '' })} style={{ minWidth: width }}>
      <Option value="">{all}</Option>{(opts ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
    </Dropdown>
  )
  const chips: { key: string; text: string; clear: Params }[] = [
    ...(str(params, 'from_wh') ? [{ key: 'from_wh', text: `From: ${label(options?.warehouses, str(params, 'from_wh'))}`, clear: { from_wh: '' } }] : []),
    ...(str(params, 'to_wh') ? [{ key: 'to_wh', text: `To: ${label(options?.warehouses, str(params, 'to_wh'))}`, clear: { to_wh: '' } }] : []),
  ]

  return (
    <div className={page.page}>
      <PageHead title="Transfers" hint="Stock moved between warehouses: dispatched by the sender, received by the other side with what actually arrived." classic="/admin/stock-transfers" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Transfer number" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search transfers" />
        <PeriodButtons value={str(params, 'period')} onChange={(v) => set({ period: v })} />
        {pick('status', 'Any status', options?.statuses, 140)}
        {pick('from_wh', 'From any warehouse', options?.warehouses)}
        {pick('to_wh', 'To any warehouse', options?.warehouses)}
        <ToggleButton checked={str(params, 'difference') === '1'} onClick={() => set({ difference: str(params, 'difference') === '1' ? '' : '1' })}>Differences only</ToggleButton>
        <ExportMenu list="transfers" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/transfers/new')}>New transfer</Button>}
      </div>
      {chips.length > 0 && (
        <TagGroup style={{ marginBottom: 10 }} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Transfers shown', value: data.total.toLocaleString('en-GB') },
        { label: 'In transit', value: data.in_transit.toLocaleString('en-GB') },
        { label: 'Yours to receive', value: data.to_receive.toLocaleString('en-GB'), tone: data.to_receive ? 'var(--zx-warn)' : undefined },
        { label: 'Drafts', value: data.drafts.toLocaleString('en-GB') },
      ]} />}
      <DataTable label="Transfers" columns={columns} rows={data?.rows ?? []} sort="created_at" dir={data?.dir ?? 'desc'} loading={loading}
        empty="No transfers match. Choose All, or clear a filter." onSort={(_, dir) => set({ dir })} onOpen={(r) => navigate(`/office/transfers/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
