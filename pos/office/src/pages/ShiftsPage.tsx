import { Badge, Button, Checkbox, Dropdown, Field, Input, makeStyles, Option, Popover, PopoverSurface, PopoverTrigger, Tag, TagGroup, Text, tokens } from '@fluentui/react-components'
import { FilterRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ShiftRow, type ShiftsOptions } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, overShortColour, PageHead, PeriodButtons, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams, type Params } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  filters: { display: 'grid', gap: '10px', width: '320px' },
  pair: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

/**
 * Till shifts (release 2): every shift the person may see, newest first, with what it sold, the cash expected, counted
 * and over or short (a corrected count shows the corrected figure), the drawer and the MoMo/card checks. Open or closed,
 * teller, dates, over or short, vaults waiting for the manager's count. A row opens the shift.
 */
export function ShiftsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<ShiftsOptions | null>(null)
  const { data, loading, error } = useList(api.shifts, serialize(params))
  useEffect(() => { api.shiftsOptions().then(setOptions).catch(() => undefined) }, [])
  const m = (v: number | null) => (v === null || !data ? '—' : money(v, data.currency, data.exponent))

  const columns: Column<ShiftRow>[] = [
    { key: 'till', label: 'Till', render: (r) => <><Text weight="semibold">{r.where}</Text>{r.shift_name && <Text block size={200} className={s.muted}>{r.shift_name}</Text>}</> },
    { key: 'by', label: 'Opened by', render: (r) => <>{r.opened_by ?? '—'}{r.sellers && <Text block size={200} className={s.muted}>{r.sellers}</Text>}</> },
    { key: 'opened', label: 'Opened', sort: 'opened_at', render: (r) => when(r.opened_at) },
    { key: 'closed', label: 'Closed', render: (r) => (r.status === 'open'
      ? (r.left_open
        ? <Text weight="semibold" style={{ color: 'var(--zx-bad)' }}>Left open since {r.left_open}<Text block size={200} style={{ color: 'inherit', fontWeight: 400 }}>close it on the till</Text></Text>
        : <Badge appearance="tint" color="success">Open</Badge>)
      : <>{when(r.closed_at)}{r.vault_waiting && <Text block size={200} style={{ color: 'var(--zx-warn)' }}>Vault waiting for the manager's count</Text>}</>) },
    { key: 'sales', label: 'Sales', end: true, render: (r) => <>{r.sales}<Text block size={200} className={s.muted}>{m(r.takings)}</Text></> },
    { key: 'float', label: 'Float', end: true, render: (r) => m(r.float) },
    { key: 'expected', label: 'Expected', end: true, render: (r) => m(r.expected) },
    { key: 'counted', label: 'Counted', end: true, render: (r) => <>{m(r.counted)}{r.corrected && <Text block size={200} className={s.muted}>corrected</Text>}</> },
    { key: 'os', label: 'Over / short', sort: 'over_short', end: true, render: (r) => <Text weight="semibold" style={{ color: overShortColour(r.over_short) }}>{m(r.over_short)}</Text> },
    { key: 'checks', label: 'MoMo, card & vouchers', render: (r) => <Text style={{ color: /short/.test(r.checks) ? 'var(--zx-bad)' : /over/.test(r.checks) ? 'var(--zx-warn)' : undefined }}>{r.checks}</Text> },
  ]

  const chips: { key: string; text: string; clear: Params }[] = [
    ...(str(params, 'status') ? [{ key: 'status', text: str(params, 'status') === 'open' ? 'Open only' : 'Closed only', clear: { status: '' } }] : []),
    ...(str(params, 'teller') ? [{ key: 'teller', text: `Teller: ${options?.tellers.find((t) => t.id === str(params, 'teller'))?.label ?? ''}`, clear: { teller: '' } }] : []),
    ...(str(params, 'branch') ? [{ key: 'branch', text: `Branch: ${options?.branches.find((b) => b.id === str(params, 'branch'))?.label ?? ''}`, clear: { branch: '' } }] : []),
    ...(str(params, 'os_min') || str(params, 'os_max') ? [{ key: 'os', text: `Over/short ${str(params, 'os_min') || 'any'} to ${str(params, 'os_max') || 'any'}`, clear: { os_min: '', os_max: '' } }] : []),
    ...(str(params, 'from') || str(params, 'to') ? [{ key: 'dates', text: `${str(params, 'from') || '…'} to ${str(params, 'to') || 'today'}`, clear: { from: '', to: '' } }] : []),
    ...(str(params, 'vault_waiting') ? [{ key: 'vault', text: 'Vaults waiting for the count', clear: { vault_waiting: '' } }] : []),
  ]

  return (
    <div className={page.page}>
      <PageHead title="Till shifts" hint="Each shift from opening to the cash count. A corrected count shows the corrected figure; the original stays on the shift." />
      <div className={s.bar}>
        <PeriodButtons value={str(params, 'period')} onChange={(v) => set({ period: v })} />
        <Button size="small" appearance={str(params, 'status') === 'open' ? 'primary' : 'secondary'} onClick={() => set({ status: str(params, 'status') === 'open' ? '' : 'open' })}>Open now</Button>
        <Filters options={options} params={params} onApply={set} />
        <ExportMenu list="shifts" query={serialize(params)} total={data?.total ?? null} />
      </div>
      {chips.length > 0 && (
        <TagGroup style={{ marginBottom: 10 }} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Shifts shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Still open', value: String(data.open), tone: data.open ? 'var(--zx-warn)' : undefined },
        { label: 'Over / short, together', value: m(data.over_short), tone: overShortColour(data.over_short) },
      ]} />}
      <DataTable label="Till shifts" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'opened_at'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No shifts match. Clear a filter, or choose All." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/shifts/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}

function Filters({ options, params, onApply }: { options: ShiftsOptions | null; params: Params; onApply: (p: Params) => void }) {
  const s = useStyles()
  const read = () => ({ status: str(params, 'status'), branch: str(params, 'branch'), teller: str(params, 'teller'), os_min: str(params, 'os_min'),
    os_max: str(params, 'os_max'), from: str(params, 'from'), to: str(params, 'to'), vault_waiting: str(params, 'vault_waiting') })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState(read)
  const count = Object.values(read()).filter(Boolean).length
  return (
    <Popover open={open} onOpenChange={(_, d) => { setOpen(d.open); if (d.open) setF(read()) }} positioning="below-end" trapFocus>
      <PopoverTrigger disableButtonEnhancement><Button icon={<FilterRegular />}>Filters{count ? ` (${count})` : ''}</Button></PopoverTrigger>
      <PopoverSurface>
        <div className={s.filters}>
          <Field label="Shifts">
            <Dropdown value={f.status === 'open' ? 'Open only' : f.status === 'closed' ? 'Closed only' : 'Open and closed'} selectedOptions={[f.status]}
              onOptionSelect={(_, d) => setF({ ...f, status: d.optionValue ?? '' })}>
              <Option value="">Open and closed</Option><Option value="open">Open only</Option><Option value="closed">Closed only</Option>
            </Dropdown>
          </Field>
          <Field label="Branch">
            <Dropdown placeholder="All branches" value={options?.branches.find((b) => b.id === f.branch)?.label ?? ''} selectedOptions={f.branch ? [f.branch] : []}
              onOptionSelect={(_, d) => setF({ ...f, branch: d.optionValue ?? '' })}>
              <Option value="">All branches</Option>{(options?.branches ?? []).map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
            </Dropdown>
          </Field>
          <Field label="Teller">
            <Dropdown placeholder="Everyone" value={options?.tellers.find((t) => t.id === f.teller)?.label ?? ''} selectedOptions={f.teller ? [f.teller] : []}
              onOptionSelect={(_, d) => setF({ ...f, teller: d.optionValue ?? '' })}>
              <Option value="">Everyone</Option>{(options?.tellers ?? []).map((t) => <Option key={t.id} value={t.id}>{t.label}</Option>)}
            </Dropdown>
          </Field>
          <div className={s.pair}>
            <Field label="Over / short from" hint="minus for short"><Input type="number" value={f.os_min} onChange={(_, d) => setF({ ...f, os_min: d.value })} /></Field>
            <Field label="to"><Input type="number" value={f.os_max} onChange={(_, d) => setF({ ...f, os_max: d.value })} /></Field>
          </div>
          <div className={s.pair}>
            <Field label="Opened from"><Input type="date" value={f.from} onChange={(_, d) => setF({ ...f, from: d.value })} /></Field>
            <Field label="until"><Input type="date" value={f.to} onChange={(_, d) => setF({ ...f, to: d.value })} /></Field>
          </div>
          <Checkbox label="Vaults waiting for the manager's count" checked={!!f.vault_waiting} onChange={(_, d) => setF({ ...f, vault_waiting: d.checked ? '1' : '' })} />
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <Button onClick={() => { onApply({ status: '', branch: '', teller: '', os_min: '', os_max: '', from: '', to: '', vault_waiting: '' }); setOpen(false) }}>Clear all</Button>
            <Button appearance="primary" onClick={() => { onApply({ ...f, period: f.from || f.to ? '' : str(params, 'period') }); setOpen(false) }}>Apply</Button>
          </div>
        </div>
      </PopoverSurface>
    </Popover>
  )
}
