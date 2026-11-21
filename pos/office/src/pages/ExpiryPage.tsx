import { Badge, Button, Checkbox, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, Textarea, tokens } from '@fluentui/react-components'
import { DeleteRegular, PrintRegular, ReceiptRegular, VehicleTruckRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ExpiryOptions, type ExpiryRow, type Option as Choice } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '260px' },
  grow: { flex: '1 1 auto' },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

type Doing = 'dispose' | 'return' | 'markdown' | 'register' | null
const day = (d: string) => new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
const left = (n: number) => (n < 0 ? `expired ${-n} d ago` : n === 0 ? 'today' : `${n} d`)

/**
 * Expiry & returns (stock): expired and near-expiry batches in one list. Tick the batches of one store, then Dispose,
 * Return to supplier or Mark down. A disposal or return waits for someone else with "approve stock adjustments" to
 * approve it; the stock leaves only then.
 */
export function ExpiryPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<ExpiryOptions | null>(null)
  const [nonce, setNonce] = useState(0)
  const [ticked, setTicked] = useState<Map<string, ExpiryRow>>(new Map())
  const [doing, setDoing] = useState<Doing>(null)
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.expiry, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { void api.expiryOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '—')
  const status = str(params, 'status') || 'soon'
  const pick = (label: string, key: string, list: Choice[], all: string) => (
    <Dropdown aria-label={label} style={{ minWidth: 170 }} value={str(params, key) === '' ? all : list.find((x) => x.id === str(params, key))?.label ?? ''}
      selectedOptions={[str(params, key)]} onOptionSelect={(_, d) => set({ [key]: d.optionValue ?? '' })}>
      <Option value="">{all}</Option>{list.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
    </Dropdown>
  )
  const rows = data?.rows ?? []
  const select = (ids: string[], on: boolean) => setTicked((t) => {
    const n = new Map(t)
    ids.forEach((id) => { const r = rows.find((x) => x.id === id); if (on && r) n.set(id, r); else n.delete(id) })
    return n
  })
  const tickedRows = [...ticked.values()]
  const oneStore = new Set(tickedRows.map((r) => r.warehouse_id)).size === 1
  const finish = (msg: string, open?: string) => { setDoing(null); setTicked(new Map()); if (open) navigate(open); else { setDone(msg); setNonce((n) => n + 1) } }
  const columns: Column<ExpiryRow>[] = [
    { key: 'product', label: 'Product', render: (r) => <>{r.product}<Text block size={200} className={s.muted}>{r.sku}{r.controlled ? ' · controlled' : ''}</Text></> },
    { key: 'batch', label: 'Batch', render: (r) => r.batch_no },
    { key: 'expires', label: 'Expires', render: (r) => day(r.expires_on) },
    { key: 'left', label: 'Days left', render: (r) => <Badge appearance="tint" color={r.days_left < 0 ? 'danger' : r.days_left <= 30 ? 'warning' : 'subtle'}>{left(r.days_left)}</Badge> },
    { key: 'on_hand', label: 'On hand', end: true, render: (r) => r.on_hand },
    { key: 'value', label: 'Value (cost)', end: true, render: (r) => m(r.value) },
    { key: 'store', label: 'Store', render: (r) => r.warehouse },
    { key: 'supplier', label: 'Supplier', render: (r) => <>{r.supplier ?? '—'}{r.returnable && <Text block size={200} className={s.muted}>can still go back</Text>}</> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Expiry & returns" hint="Tick the batches of one store, then Dispose, Return to supplier or Mark down. A disposal or return waits for someone else to approve it; the stock leaves only then." classic="/admin/expiry-returns" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Product, code or batch" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search batches" />
        <Dropdown aria-label="Show" style={{ minWidth: 240 }} value={options?.statuses.find((x) => x.id === status)?.label ?? ''} selectedOptions={[status]}
          onOptionSelect={(_, d) => set({ status: d.optionValue === 'soon' ? '' : d.optionValue ?? '' })}>
          {(options?.statuses ?? []).map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
        </Dropdown>
        {options && pick('Store', 'warehouse', options.warehouses, 'Every store')}
        {options && pick('Supplier', 'supplier', options.suppliers, 'Every supplier')}
        <Checkbox label="Controlled drugs only" checked={str(params, 'controlled') === '1'} onChange={(_, d) => set({ controlled: d.checked ? '1' : '' })} />
        <Checkbox label="Supplier still takes back" checked={str(params, 'returnable') === '1'} onChange={(_, d) => set({ returnable: d.checked ? '1' : '' })} />
        <span className={s.grow} />
        <Button icon={<PrintRegular />} disabled={!options?.warehouses.length} onClick={() => { setDone(''); setDoing('register') }}>Disposal register</Button>
        <Button icon={<VehicleTruckRegular />} onClick={() => navigate('/admin/supplier-returns')}>Returns to suppliers</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {data && <Cards items={[{ label: 'Batches', value: data.total.toLocaleString('en-GB') }, { label: 'Value at cost', value: m(data.value) }, { label: 'Ticked', value: String(ticked.size) }]} />}
      <div className={s.bar}>
        <Button icon={<DeleteRegular />} disabled={!ticked.size || !oneStore} onClick={() => { setDone(''); setDoing('dispose') }}>Dispose{ticked.size ? ` (${ticked.size})` : ''}</Button>
        <Button icon={<VehicleTruckRegular />} disabled={!ticked.size || !oneStore} onClick={() => { setDone(''); setDoing('return') }}>Return to supplier</Button>
        {options?.can_mark_down && <Button icon={<ReceiptRegular />} disabled={!ticked.size} onClick={() => { setDone(''); setDoing('markdown') }}>Mark down</Button>}
        {ticked.size > 0 && !oneStore && <Text className={s.muted}>Dispose and Return take one store at a time.</Text>}
      </div>
      <DataTable label="Expiring batches" columns={columns} rows={rows} sort="expires" dir="asc" loading={loading}
        empty="Nothing here: no batch in stock is expired or expiring in this period. Batches need an expiry date (received with one, or on the stock sheet)."
        selected={new Set(ticked.keys())} onSelect={select} onSort={() => undefined} onOpen={(r) => select([r.id], !ticked.has(r.id))} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {doing === 'dispose' && options && <Dispose options={options} rows={tickedRows} onClose={() => setDoing(null)} onDone={finish} />}
      {doing === 'return' && options && <ReturnToSupplier options={options} rows={tickedRows} onClose={() => setDoing(null)} onDone={finish} />}
      {doing === 'markdown' && <MarkDown rows={tickedRows} onClose={() => setDoing(null)} onDone={finish} />}
      {doing === 'register' && options && <Register options={options} onClose={() => setDoing(null)} />}
    </div>
  )
}

function Choose({ label, list, value, onChange, required = true }: { label: string; list: Choice[]; value: string; onChange: (v: string) => void; required?: boolean }) {
  return (
    <Field label={label} required={required}>
      <Dropdown value={list.find((x) => x.id === value)?.label ?? ''} selectedOptions={[value]} onOptionSelect={(_, d) => onChange(d.optionValue ?? '')}>
        {list.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
      </Dropdown>
    </Field>
  )
}

function Dispose({ options, rows, onClose, onDone }: { options: ExpiryOptions; rows: ExpiryRow[]; onClose: () => void; onDone: (m: string, open?: string) => void }) {
  const s = useStyles()
  const [reason, setReason] = useState('expired')
  const [method, setMethod] = useState('')
  const [on, setOn] = useState(options.today)
  const [witness, setWitness] = useState('')
  const [note, setNote] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const controlled = rows.some((r) => r.controlled)
  const needMethod = options.evidence !== 'reason'
  const needWitness = needMethod || controlled
  const needFile = options.evidence === 'method_witness_certificate'
  const ready = (!needMethod || method !== '') && (!needWitness || witness.trim() !== '') && (!needFile || file !== null) && on !== ''
  return (
    <Ask title={`Dispose of ${rows.length} batch(es)`} go="Prepare the disposal" ready={ready} width={560} onClose={onClose}
      run={async () => {
        const body = new FormData()
        rows.forEach((r) => body.append('keys[]', r.id))
        Object.entries({ reason, method, disposed_on: on, witness, note }).forEach(([k, v]) => body.append(k, v))
        if (file) body.append('certificate', file)
        const r = await api.disposeBatches(body)
        onDone(r.message, r.open)
      }}>
      <div className={s.form}>
        <Text>The whole batch is removed once someone else approves it. Keep the packs aside, marked "not for sale", until then.</Text>
        <Choose label="Reason" list={[{ id: 'expired', label: 'Expired' }, { id: 'damaged', label: 'Damaged' }]} value={reason} onChange={setReason} />
        {needMethod && <Choose label="How it is disposed of" list={options.methods} value={method} onChange={setMethod} />}
        <Field label="Disposed of on" required><Input type="date" value={on} max={options.today} onChange={(_, d) => setOn(d.value)} /></Field>
        <Field label="Witness (full name and role)" required={needWitness} hint={controlled ? 'Controlled drugs are always disposed of in front of a witness.' : undefined}>
          <Input value={witness} maxLength={120} onChange={(_, d) => setWitness(d.value)} />
        </Field>
        {needFile && <Field label="Disposal certificate (photo or PDF)" required>
          <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>}
        <Field label="Note"><Textarea value={note} maxLength={500} onChange={(_, d) => setNote(d.value)} /></Field>
      </div>
    </Ask>
  )
}

function ReturnToSupplier({ options, rows, onClose, onDone }: { options: ExpiryOptions; rows: ExpiryRow[]; onClose: () => void; onDone: (m: string, open?: string) => void }) {
  const s = useStyles()
  const [supplier, setSupplier] = useState(rows.map((r) => r.supplier_id).find((x) => x) ?? '')
  const [settlement, setSettlement] = useState('credit_note')
  const [reason, setReason] = useState(rows.every((r) => r.days_left < 0) ? 'expired' : 'near_expiry')
  const [note, setNote] = useState('')
  return (
    <Ask title={`Return ${rows.length} batch(es) to a supplier`} go="Prepare the return" ready={supplier !== '' && settlement !== '' && reason !== ''} width={560} onClose={onClose}
      run={async () => { const r = await api.returnBatches({ keys: rows.map((x) => x.id), supplier, settlement, reason, note }); onDone(r.message, r.open) }}>
      <div className={s.form}>
        <Choose label="Supplier" list={options.suppliers} value={supplier} onChange={setSupplier} />
        <Choose label="The supplier gives" list={options.settlements} value={settlement} onChange={setSettlement} />
        <Choose label="Reason" list={options.reasons} value={reason} onChange={setReason} />
        <Field label="Note (e.g. the supplier rep's name, collection date)"><Textarea value={note} maxLength={500} onChange={(_, d) => setNote(d.value)} /></Field>
      </div>
    </Ask>
  )
}

function MarkDown({ rows, onClose, onDone }: { rows: ExpiryRow[]; onClose: () => void; onDone: (m: string) => void }) {
  const [percent, setPercent] = useState('30')
  const expired = rows.filter((r) => r.days_left < 0).length
  return (
    <Ask title={`Mark down ${rows.length} batch(es)`} go="Mark down" ready={Number(percent) >= 1 && Number(percent) <= 90} onClose={onClose}
      run={async () => onDone((await api.markDownBatches(rows.map((r) => r.id), percent)).message)}>
      <Text block style={{ marginBottom: 10 }}>{expired ? `${expired} ticked batch(es) have already expired and are skipped. ` : ''}Print the stickers afterwards from Batches &amp; expiry.</Text>
      <Field label="% off" required><Input type="number" min={1} max={90} value={percent} onChange={(_, d) => setPercent(d.value)} /></Field>
    </Ask>
  )
}

function Register({ options, onClose }: { options: ExpiryOptions; onClose: () => void }) {
  const s = useStyles()
  const [warehouse, setWarehouse] = useState(options.warehouses[0]?.id ?? '')
  const [from, setFrom] = useState(options.month_start)
  const [to, setTo] = useState(options.today)
  return (
    <Ask title="Disposal register" go="Open to print" ready={warehouse !== '' && from !== '' && to !== '' && from <= to} onClose={onClose}
      run={async () => { const r = await api.disposalRegister(warehouse, from, to); window.open(r.url, '_blank', 'noopener'); onClose() }}>
      <div className={s.form}>
        <Choose label="Store" list={options.warehouses} value={warehouse} onChange={setWarehouse} />
        <Field label="From" required><Input type="date" value={from} onChange={(_, d) => setFrom(d.value)} /></Field>
        <Field label="To" required><Input type="date" value={to} onChange={(_, d) => setTo(d.value)} /></Field>
      </div>
    </Ask>
  )
}
