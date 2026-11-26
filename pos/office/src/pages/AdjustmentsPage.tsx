import {
  Badge, Button, Dropdown, Field, makeStyles, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, Option, Popover, PopoverSurface, PopoverTrigger, SearchBox, Tag, TagGroup,
  Text, tokens,
} from '@fluentui/react-components'
import { AddRegular, ArrowUploadRegular, DocumentCheckmarkRegular, FilterRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type AdjOptions, type AdjRow, type Option as Choice } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, PeriodButtons, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams, type Params } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 240px', maxWidth: '360px' },
  grow: { flex: '1 1 auto' },
  filters: { display: 'grid', gap: '10px', width: '300px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

export const ADJ_STATUS: Record<string, 'success' | 'warning' | 'informative' | 'subtle'> = { posted: 'success', submitted: 'warning', draft: 'informative', cancelled: 'subtle' }

/**
 * Stock adjustments (release 3): increases, decreases and "set to counted quantity", prepared, approved and posted to the
 * stock ledger. Search a number or reference, choose the period, filter by status, type, reason or warehouse. A row
 * opens the adjustment; New stock adjustment opens the form.
 */
export function AdjustmentsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<AdjOptions | null>(null)
  const { data, loading, error } = useList(api.adjustments, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.adjustmentsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number | null) => (v === null || !data ? '—' : money(v, data.currency, data.exponent))
  const label = (opts: Choice[] | undefined, id: string) => opts?.find((o) => o.id === id)?.label ?? id
  const columns: Column<AdjRow>[] = [
    { key: 'number', label: 'Adjustment', render: (r) => <Text weight="semibold">{r.number}</Text> },
    { key: 'date', label: 'Date', sort: 'created_at', render: (r) => when(r.created_at) },
    { key: 'warehouse', label: 'Warehouse', render: (r) => r.warehouse ?? '—' },
    { key: 'reason', label: 'Reason', render: (r) => <>{r.reason}{r.disposal && <Text block size={200} className={s.muted}>disposal</Text>}</> },
    { key: 'reference', label: 'Reference', render: (r) => r.reference ?? '—' },
    { key: 'lines', label: 'Lines', end: true, render: (r) => r.lines },
    { key: 'value', label: 'Value', end: true, render: (r) => <Text weight="semibold" style={{ color: r.value !== null && r.value < 0 ? 'var(--zx-bad)' : undefined }}>{m(r.value)}</Text> },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={ADJ_STATUS[r.status] ?? 'informative'}>{r.status_label}</Badge> },
    { key: 'by', label: 'Prepared by', render: (r) => r.by ?? '—' },
  ]
  const chips: { key: string; text: string; clear: Params }[] = ['status', 'kind', 'reason', 'warehouse'].filter((k) => str(params, k)).map((k) => ({
    key: k, text: `${{ status: 'Status', kind: 'Type', reason: 'Reason', warehouse: 'Warehouse' }[k]}: ${label(
      k === 'status' ? options?.statuses : k === 'kind' ? options?.kinds : k === 'reason' ? options?.reasons : options?.warehouses, str(params, k))}`,
    clear: { [k]: '' },
  }))

  return (
    <div className={page.page}>
      <PageHead title="Stock adjustments" hint="Stock added, taken away or set to what was counted: prepared, approved and posted to the stock ledger." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or reference" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search adjustments" />
        <PeriodButtons value={str(params, 'period')} onChange={(v) => set({ period: v })} />
        <Filters options={options} params={params} onApply={set} />
        <ExportMenu list="adjustments" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/adjustments/new')}>New stock adjustment</Button>}
        {options && (options.links.import || options.links.invoice) && (
          <Menu>
            <MenuTrigger disableButtonEnhancement><Button icon={<MoreHorizontalRegular />} aria-label="More">More</Button></MenuTrigger>
            <MenuPopover><MenuList>
              {options.links.import && <MenuItem icon={<ArrowUploadRegular />} onClick={() => navigate(options.links.import!)}>Update stock from a CSV file</MenuItem>}
              {options.links.invoice && <MenuItem icon={<DocumentCheckmarkRegular />} onClick={() => navigate(options.links.invoice!)}>Enter a supplier invoice</MenuItem>}
            </MenuList></MenuPopover>
          </Menu>
        )}
      </div>
      {chips.length > 0 && (
        <TagGroup style={{ marginBottom: 10 }} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Adjustments shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Waiting for approval', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined },
        { label: 'Drafts', value: data.drafts.toLocaleString('en-GB') },
      ]} />}
      <DataTable label="Stock adjustments" columns={columns} rows={data?.rows ?? []} sort="created_at" dir={data?.dir ?? 'desc'} loading={loading}
        empty="No adjustments match. Choose All, or clear a filter." onSort={(_, dir) => set({ dir })} onOpen={(r) => navigate(`/office/adjustments/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}

function Filters({ options, params, onApply }: { options: AdjOptions | null; params: Params; onApply: (p: Params) => void }) {
  const s = useStyles()
  const keys = ['status', 'kind', 'reason', 'warehouse'] as const
  const read = () => Object.fromEntries(keys.map((k) => [k, str(params, k)])) as Record<(typeof keys)[number], string>
  const [open, setOpen] = useState(false)
  const [f, setF] = useState(read)
  const count = keys.filter((k) => str(params, k)).length
  const pick = (k: (typeof keys)[number], lbl: string, opts: Choice[], all: string) => (
    <Field label={lbl}>
      <Dropdown value={opts.find((o) => o.id === f[k])?.label ?? all} selectedOptions={[f[k]]} onOptionSelect={(_, d) => setF({ ...f, [k]: d.optionValue ?? '' })}>
        <Option value="">{all}</Option>
        {opts.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      </Dropdown>
    </Field>
  )
  return (
    <Popover open={open} onOpenChange={(_, d) => { setOpen(d.open); if (d.open) setF(read()) }} positioning="below-start" trapFocus>
      <PopoverTrigger disableButtonEnhancement><Button icon={<FilterRegular />}>Filters{count ? ` (${count})` : ''}</Button></PopoverTrigger>
      <PopoverSurface>
        <div className={s.filters}>
          {pick('status', 'Status', options?.statuses ?? [], 'Any')}
          {pick('kind', 'Type', options?.kinds ?? [], 'Any')}
          {pick('reason', 'Reason', options?.reasons ?? [], 'Any')}
          {pick('warehouse', 'Warehouse', options?.warehouses ?? [], 'All warehouses')}
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <Button onClick={() => { onApply({ status: '', kind: '', reason: '', warehouse: '' }); setOpen(false) }}>Clear all</Button>
            <Button appearance="primary" onClick={() => { onApply(f); setOpen(false) }}>Apply</Button>
          </div>
        </div>
      </PopoverSurface>
    </Popover>
  )
}
