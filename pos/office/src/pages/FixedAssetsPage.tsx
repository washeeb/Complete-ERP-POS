import { Badge, Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular, TagRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type AssetOptions, type AssetRow } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  two: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' },
})

const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const STATUS = [['', 'Any status'], ['active', 'Active'], ['disposed', 'Disposed']]

/**
 * Fixed assets (release 8): computers, vehicles, furniture and equipment the business owns, with cost and book value
 * (cost less depreciation so far; depreciation is charged monthly by itself). Register an asset — its cost is posted
 * by the supplier bill or the opening balances, not here. An asset opens with its depreciation month by month.
 */
export function FixedAssetsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<AssetOptions | null>(null)
  const [registering, setRegistering] = useState(str(params, 'register') === '1')
  const { data, loading, error } = useList(api.fixedAssets, serialize({ ...params, register: '' }), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.fixedAssetOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const kind = str(params, 'kind')
  const status = str(params, 'status')
  const columns: Column<AssetRow>[] = [
    { key: 'number', label: 'Number', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'name', label: 'Asset', sort: 'name', render: (r) => <>{r.name}{r.serial_no && <Text block size={200} className={s.muted}>{r.serial_no}</Text>}</> },
    { key: 'kind', label: 'Kind', render: (r) => r.kind ?? '—' },
    { key: 'at', label: 'At', render: (r) => r.branch ?? '—' },
    { key: 'bought', label: 'Bought', sort: 'acquired_on', render: (r) => day(r.acquired_on) },
    { key: 'cost', label: 'Cost', sort: 'cost_minor', end: true, render: (r) => m(r.cost) },
    { key: 'book', label: 'Book value', end: true, render: (r) => <Text weight="semibold">{m(r.book)}</Text> },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={r.status === 'active' ? 'success' : 'subtle'}>{r.status === 'active' ? 'Active' : 'Disposed'}</Badge> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Fixed assets" hint="What the business owns and uses for years: cost, book value after depreciation, where it is. Depreciation is charged every month by itself." classic="/admin/fixed-assets" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, name or serial" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search fixed assets" />
        <Dropdown aria-label="Status" style={{ minWidth: 130 }} value={STATUS.find((x) => x[0] === status)?.[1] ?? ''} selectedOptions={[status]} onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
          {STATUS.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Kind" style={{ minWidth: 170 }} value={kind ? options?.kinds.find((k) => k.id === kind)?.label ?? '' : 'Any kind'} selectedOptions={[kind]}
          onOptionSelect={(_, d) => set({ kind: d.optionValue ?? '' })}>
          <Option value="">Any kind</Option>{(options?.kinds ?? []).map((k) => <Option key={k.id} value={k.id}>{k.label}</Option>)}
        </Dropdown>
        <ExportMenu list="fixed-assets" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_classes && <Button icon={<TagRegular />} onClick={() => navigate('/office/asset-kinds')}>Kinds of asset</Button>}
        {options?.can_manage && <Button appearance="primary" icon={<AddRegular />} onClick={() => setRegistering(true)}>Register an asset</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {data && <Cards items={[{ label: 'Assets shown', value: data.total.toLocaleString('en-GB') }, { label: 'Cost', value: m(data.cost) }]} />}
      <DataTable label="Fixed assets" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'number'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No fixed assets match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/fixed-assets/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {registering && options?.can_manage && <Register options={options} onClose={() => { setRegistering(false); if (str(params, 'register')) set({ register: '' }) }} onDone={(open) => navigate(open)} />}
    </div>
  )
}

function Register({ options, onClose, onDone }: { options: AssetOptions; onClose: () => void; onDone: (open: string) => void }) {
  const s = useStyles()
  const today = new Date().toISOString().slice(0, 10)
  const [v, setV] = useState({ name: '', asset_class_id: '', branch_id: options.hq, acquired_on: today, cost: '', residual: '0', life_months: '', opening_depreciation: '0', serial_no: '', location: '' })
  const put = (k: keyof typeof v, value: string) => setV({ ...v, [k]: value })
  const kind = options.kinds.find((k) => k.id === v.asset_class_id)
  const text = (k: keyof typeof v, label: string, extra: Record<string, unknown> = {}, hint?: string, required = false) => (
    <Field label={label} hint={hint} required={required}><Input value={v[k]} onChange={(_, d) => put(k, d.value)} {...extra} /></Field>
  )
  return (
    <Ask title="Register an asset" go="Register" ready={v.name.trim() !== '' && !!v.asset_class_id && !!v.branch_id && Number(v.cost) > 0 && v.acquired_on !== ''} onClose={onClose} width={680}
      run={async () => onDone((await api.registerAsset({ ...v, life_months: v.life_months ? Number(v.life_months) : null, serial_no: v.serial_no || null, location: v.location || null })).asset.open)}>
      <div className={s.form}>
        <Text>Registering an asset does not post its cost: record the purchase as a supplier bill (Other charges → the asset account) or in the opening balances.</Text>
        <div className={s.two}>
          {text('name', 'Name', { maxLength: 255 }, undefined, true)}
          <Field label="Kind" required>
            <Dropdown value={kind ? `${kind.label} (${kind.life_months} months)` : ''} selectedOptions={[v.asset_class_id]} onOptionSelect={(_, d) => put('asset_class_id', d.optionValue ?? '')}>
              {options.kinds.map((k) => <Option key={k.id} value={k.id} text={`${k.label} (${k.life_months} months)`}>{k.label} ({k.life_months} months)</Option>)}
            </Dropdown>
          </Field>
          <Field label="Used at" required>
            <Dropdown value={options.branches.find((b) => b.id === v.branch_id)?.label ?? ''} selectedOptions={[v.branch_id]} onOptionSelect={(_, d) => put('branch_id', d.optionValue ?? '')}>
              {options.branches.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
            </Dropdown>
          </Field>
          {text('acquired_on', 'Bought on', { type: 'date', max: today }, undefined, true)}
          {text('cost', 'Cost', { type: 'number', min: 0 }, undefined, true)}
          {text('residual', 'Worth at end of life', { type: 'number', min: 0 })}
          {text('life_months', 'Useful life (months)', { type: 'number', min: 1 }, kind ? `Blank = ${kind.life_months}, the default for its kind.` : 'Blank = the default for its kind.')}
          {text('opening_depreciation', 'Depreciation already in the opening balances', { type: 'number', min: 0 }, 'Only for assets brought over at go-live.')}
          {text('serial_no', 'Serial / registration no.', { maxLength: 80 })}
          {text('location', 'Location', { maxLength: 255 })}
        </div>
      </div>
    </Ask>
  )
}
