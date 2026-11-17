import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Field, Input, makeStyles, Option, SearchBox, Switch, Tag, TagGroup,
  Text, tokens,
} from '@fluentui/react-components'
import { PlayRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CountOptions, type CountRow, type Option as Choice } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, PeriodButtons, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams, type Params } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'grid', gap: '10px' },
})

export const COUNT_STATUS: Record<string, 'informative' | 'warning' | 'success' | 'subtle'> = { counting: 'informative', submitted: 'warning', posted: 'success', cancelled: 'subtle' }

/**
 * Stock counts (release 3): a warehouse (or one bin) counted, blind or with the expected figures shown, submitted, then
 * approved and posted by someone else — the differences go into stock as count movements. Products nobody counted are
 * left as they are. Start a count here; a row opens it.
 */
export function CountsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<CountOptions | null>(null)
  const { data, loading, error } = useList(api.counts, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  const [starting, setStarting] = useState(false)
  useEffect(() => { api.countsOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const label = (opts: Choice[] | undefined, id: string) => opts?.find((o) => o.id === id)?.label ?? id
  const columns: Column<CountRow>[] = [
    { key: 'number', label: 'Count', render: (r) => <><Text weight="semibold">{r.number}</Text>{r.blind && <Text block size={200} className={s.muted}>blind</Text>}</> },
    { key: 'warehouse', label: 'Warehouse', render: (r) => <>{r.warehouse ?? '—'}{r.bin && <Text block size={200} className={s.muted}>bin {r.bin}</Text>}</> },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={COUNT_STATUS[r.status] ?? 'informative'}>{r.status_label}</Badge> },
    { key: 'started', label: 'Started', sort: 'started_at', render: (r) => <>{when(r.started_at)}<Text block size={200} className={s.muted}>{r.started_by}</Text></> },
    { key: 'lines', label: 'Lines', end: true, render: (r) => r.lines },
    { key: 'difference', label: 'Difference value', end: true, render: (r) => (r.difference === null || !data ? '—'
      : <Text weight="semibold" style={{ color: r.difference < 0 ? 'var(--zx-bad)' : undefined }}>{money(r.difference, data.currency, data.exponent)}</Text>) },
  ]
  const chips: { key: string; text: string; clear: Params }[] = [
    ...(str(params, 'status') ? [{ key: 'status', text: `Status: ${label(options?.statuses, str(params, 'status'))}`, clear: { status: '' } }] : []),
    ...(str(params, 'warehouse') ? [{ key: 'warehouse', text: `Warehouse: ${label(options?.warehouses, str(params, 'warehouse'))}`, clear: { warehouse: '' } }] : []),
  ]

  return (
    <div className={page.page}>
      <PageHead title="Stock counts" hint="Count a warehouse or a bin; once someone else approves the count, the differences go into stock." classic="/admin/stock-counts" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Count number" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search counts" />
        <PeriodButtons value={str(params, 'period')} onChange={(v) => set({ period: v })} />
        <Dropdown aria-label="Status" placeholder="Any status" value={str(params, 'status') ? label(options?.statuses, str(params, 'status')) : 'Any status'}
          selectedOptions={[str(params, 'status')]} onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 160 }}>
          <Option value="">Any status</Option>{(options?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Warehouse" placeholder="All warehouses" value={str(params, 'warehouse') ? label(options?.warehouses, str(params, 'warehouse')) : 'All warehouses'}
          selectedOptions={[str(params, 'warehouse')]} onOptionSelect={(_, d) => set({ warehouse: d.optionValue ?? '' })} style={{ minWidth: 180 }}>
          <Option value="">All warehouses</Option>{(options?.warehouses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ExportMenu list="counts" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_start && <Button appearance="primary" icon={<PlayRegular />} onClick={() => setStarting(true)}>Start a count</Button>}
      </div>
      {chips.length > 0 && (
        <TagGroup style={{ marginBottom: 10 }} aria-label="Filters in use" onDismiss={(_, d) => { const c = chips.find((x) => x.key === d.value); if (c) set(c.clear) }}>
          {chips.map((c) => <Tag key={c.key} value={c.key} dismissible dismissIcon={{ 'aria-label': `Remove ${c.text}` }} size="small">{c.text}</Tag>)}
        </TagGroup>
      )}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Counts shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Being counted', value: data.counting.toLocaleString('en-GB') },
        { label: 'Waiting for approval', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined },
      ]} />}
      <DataTable label="Stock counts" columns={columns} rows={data?.rows ?? []} sort="started_at" dir={data?.dir ?? 'desc'} loading={loading}
        empty="No counts match. Choose All, or clear a filter." onSort={(_, dir) => set({ dir })} onOpen={(r) => navigate(`/office/counts/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {starting && options && <StartCount options={options} onClose={() => setStarting(false)} onStarted={(id) => navigate(`/office/counts/${id}`)} />}
    </div>
  )
}

/** The warehouse (and, where it keeps bins, one bin), blind or not, and a note. */
function StartCount({ options, onClose, onStarted }: { options: CountOptions; onClose: () => void; onStarted: (id: string) => void }) {
  const s = useStyles()
  const [f, setF] = useState({ warehouse_id: options.start_warehouses.length === 1 ? options.start_warehouses[0].id : '', bin_id: '', blind: true, note: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const bins = options.bins[f.warehouse_id] ?? []
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 480 }}>
        <DialogBody>
          <DialogTitle>Start a count</DialogTitle>
          <DialogContent>
            <div className={s.form}>
              <Field label="Warehouse" required>
                <Dropdown placeholder="Choose" value={options.start_warehouses.find((w) => w.id === f.warehouse_id)?.label ?? ''} selectedOptions={f.warehouse_id ? [f.warehouse_id] : []}
                  onOptionSelect={(_, d) => setF({ ...f, warehouse_id: d.optionValue ?? '', bin_id: '' })}>
                  {options.start_warehouses.map((w) => <Option key={w.id} value={w.id}>{w.label}</Option>)}
                </Dropdown>
              </Field>
              {bins.length > 0 && (
                <Field label="Only this bin (optional)">
                  <Dropdown placeholder="The whole warehouse" value={bins.find((b) => b.id === f.bin_id)?.label ?? ''} selectedOptions={f.bin_id ? [f.bin_id] : []}
                    onOptionSelect={(_, d) => setF({ ...f, bin_id: d.optionValue ?? '' })}>
                    <Option value="">The whole warehouse</Option>{bins.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
                  </Dropdown>
                </Field>
              )}
              <Field label="Blind count" hint="Counters do not see the expected quantities, so they count what is there.">
                <Switch checked={f.blind} onChange={(_, d) => setF({ ...f, blind: d.checked })} />
              </Field>
              <Field label="Note"><Input value={f.note} maxLength={255} onChange={(_, d) => setF({ ...f, note: d.value })} /></Field>
              {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
            </div>
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Cancel</Button>
            <Button appearance="primary" disabled={busy || !f.warehouse_id} onClick={() => {
              setBusy(true)
              api.startCount({ warehouse_id: f.warehouse_id, bin_id: f.bin_id || null, blind: f.blind, note: f.note.trim() || null })
                .then((r) => onStarted(r.id)).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Starting…' : 'Start the count'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
