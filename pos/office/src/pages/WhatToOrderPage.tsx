import { Badge, Button, Divider, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, Text, tokens } from '@fluentui/react-components'
import { ArrowSwapRegular, ArrowUndoRegular, DocumentAddRegular, SettingsRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, type Option as Choice, type OrderChange, type OrderList, type OrderRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  one: { display: 'grid', gap: '10px' },
  two: { display: 'grid', gap: '10px', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' },
})

const PER = [10, 25, 50, 100]

/**
 * What to order (release 5): every product at or below its minimum in the store (counting what is on order and in
 * transit), with the supplier, unit, quantity and cost to order. Press a line to change its supplier, unit, quantity or
 * cost (the changes stay on this page until the orders are made; Undo puts the line back) or the product's levels for
 * the store (saved at once). Tick lines, then ask other stores to send what they can spare, or create one draft purchase
 * order per supplier.
 */
export function WhatToOrderPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [options, setOptions] = useState<{ warehouses: Choice[]; suppliers: Choice[] } | null>(null)
  const [warehouse, setWarehouse] = useState(() => new URLSearchParams(location.search).get('warehouse') ?? '') // e.g. from My day's Low stock
  const [supplier, setSupplier] = useState('')
  const [changes, setChanges] = useState<Record<string, OrderChange>>({})
  const [data, setData] = useState<OrderList | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const [pageNo, setPageNo] = useState(1)
  const [per, setPer] = useState(25)
  const [open, setOpen] = useState<OrderRow | null>(null)
  const [confirm, setConfirm] = useState<'drafts' | 'moves' | null>(null)
  const [done, setDone] = useState('')

  useEffect(() => { api.whatToOrderOptions().then(setOptions).catch(() => undefined) }, [])
  const load = useCallback((wh: string, sup: string, ch: Record<string, OrderChange>) => {
    setLoading(true)
    api.whatToOrder(wh, sup, ch).then((d) => { setData(d); setError(''); if (!wh) setWarehouse(d.warehouse) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setLoading(false))
  }, [])
  useEffect(() => { load(warehouse, supplier, changes) }, [warehouse, supplier, changes, load])

  const rows = useMemo(() => data?.rows ?? [], [data])
  const shown = rows.slice((pageNo - 1) * per, pageNo * per)
  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const chosen = rows.filter((r) => ticked.has(r.id))
  const movable = chosen.filter((r) => r.move)
  const change = (productId: string, c: OrderChange | null) => setChanges((o) => {
    const next = { ...o }
    if (c) next[productId] = { ...(o[productId] ?? {}), ...c }
    else delete next[productId]
    return next
  })
  const columns: Column<OrderRow>[] = [
    { key: 'product', label: 'Product', render: (r) => <><Text weight="semibold">{r.name}</Text><Text block size={200} className={s.muted}>{r.sku}{r.changed && ' · changed by you'}</Text></> },
    { key: 'on_hand', label: 'On hand', end: true, render: (r) => r.on_hand },
    { key: 'on_order', label: 'On order', end: true, render: (r) => r.on_order },
    { key: 'in_transit', label: 'In transit', end: true, render: (r) => r.in_transit },
    { key: 'levels', label: 'Min / max', render: (r) => <>{r.min} / {r.max}{r.source && <Text block size={200} className={s.muted}>{r.source}</Text>}</> },
    { key: 'move', label: 'From another store', render: (r) => (r.move ? <Text style={{ color: 'var(--zx-good)' }}>{r.move}</Text> : <Text className={s.muted}>—</Text>) },
    { key: 'supplier', label: 'Supplier', render: (r) => r.supplier ?? <Text style={{ color: 'var(--zx-bad)' }}>none set up — choose</Text> },
    { key: 'order', label: 'Order', end: true, render: (r) => <Text weight="semibold">{r.order ?? 'nothing — moved instead'}</Text> },
    { key: 'cost', label: 'Cost', end: true, render: (r) => (r.cost ? `${r.cost} (yours)` : r.unit_cost === null ? <Text className={s.muted}>— enter</Text> : m(r.unit_cost)) },
  ]

  return (
    <div className={page.page}>
      <PageHead title="What to order" hint="Products at or below their minimum in the store, counting what is already on order or in transit. Press a line to change it; tick lines to order them." classic="/admin/reorder-suggestions" />
      <div className={s.bar}>
        <Dropdown aria-label="Warehouse" value={options?.warehouses.find((w) => w.id === warehouse)?.label ?? 'Warehouse'} selectedOptions={[warehouse]}
          onOptionSelect={(_, d) => { setWarehouse(d.optionValue ?? ''); setChanges({}); setTicked(new Set()); setPageNo(1) }} style={{ minWidth: 200 }}>
          {(options?.warehouses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Supplier" value={supplier ? (options?.suppliers.find((x) => x.id === supplier)?.label ?? supplier) : 'Any supplier'} selectedOptions={[supplier]}
          onOptionSelect={(_, d) => { setSupplier(d.optionValue ?? ''); setTicked(new Set()); setPageNo(1) }} style={{ minWidth: 180 }}>
          <Option value="">Any supplier</Option>{(options?.suppliers ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        {Object.keys(changes).length > 0 && <Badge appearance="tint" color="informative">{Object.keys(changes).length} line(s) changed by you</Badge>}
        <Button icon={<ArrowSwapRegular />} disabled={movable.length === 0} onClick={() => setConfirm('moves')}>Ask other stores to send{movable.length ? ` (${movable.length})` : ''}</Button>
        <Button appearance="primary" icon={<DocumentAddRegular />} disabled={chosen.length === 0} onClick={() => setConfirm('drafts')}>
          Create draft purchase orders{chosen.length ? ` (${chosen.length})` : ''}
        </Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {data && <Cards items={[
        { label: 'Lines to order', value: rows.length.toLocaleString('en-GB') },
        { label: 'No supplier set up', value: rows.filter((r) => !r.supplier_id).length.toLocaleString('en-GB'), tone: rows.some((r) => !r.supplier_id) ? 'var(--zx-bad)' : undefined },
        { label: 'Another store can send', value: rows.filter((r) => r.move).length.toLocaleString('en-GB') },
        { label: 'Ticked', value: chosen.length.toLocaleString('en-GB') },
      ]} />}
      <DataTable label="What to order" columns={columns} rows={shown} sort="" dir="asc" loading={loading}
        empty="Nothing to order: everything is above its minimum (counting what is already on order or in transit). Products without their own figure use the company default from Settings → Stock."
        onSort={() => undefined} onOpen={(r) => setOpen(r)} selected={ticked}
        onSelect={(ids, on) => setTicked((t) => { const n = new Set(t); ids.forEach((i) => (on ? n.add(i) : n.delete(i))); return n })} />
      {rows.length > 0 && <Pager page={pageNo} per={per} total={rows.length} options={PER} onPage={setPageNo} onPer={(n) => { setPer(n); setPageNo(1) }} />}

      {open && data && <Line row={open} warehouse={data.warehouse} canLevels={data.can_levels} currency={data.currency}
        onClose={() => setOpen(null)}
        onChange={(c) => { change(open.product_id, c); setOpen(null) }}
        onLevels={() => { setOpen(null); setDone('Levels saved: the next suggestion follows them.'); load(warehouse, supplier, changes) }} />}
      {confirm === 'moves' && (
        <Ask title="Ask other stores to send" go="Send the requests" ready onClose={() => setConfirm(null)}
          run={() => api.whatToOrderMoves(data!.warehouse, movable.map((r) => r.product_id)).then((r) => {
            setConfirm(null); setTicked(new Set()); setDone(`${r.requests.length} stock request(s) sent: ${r.requests.join(', ')}.`); load(warehouse, supplier, changes)
          })}>
          <Text>One stock request to each store with stock to spare, for the {movable.length} ticked line(s) that show "From another store". That store sends it as a transfer.</Text>
        </Ask>
      )}
      {confirm === 'drafts' && (
        <Ask title="Create draft purchase orders" go="Create them" ready onClose={() => setConfirm(null)}
          run={() => api.whatToOrderDrafts(data!.warehouse, chosen.map((r) => r.product_id), changes).then((r) => {
            setConfirm(null); setChanges({}); setTicked(new Set())
            setDone(`${r.orders.length} draft purchase order(s) created: ${r.orders.map((o) => o.number).join(', ')}. Check them, then submit them for approval.`)
            if (r.orders.length === 1) navigate(`/office/purchase-orders/${r.orders[0].id}`)
          })}>
          <Text>One draft per supplier from the {chosen.length} ticked line(s), with your changes. Lines covered by a move from another store are left out.</Text>
        </Ask>
      )}
    </div>
  )
}

/** A line's supplier, unit, quantity and cost (kept on the page), and the product's levels in this store (saved now). */
function Line({ row, warehouse, canLevels, currency, onClose, onChange, onLevels }: {
  row: OrderRow; warehouse: string; canLevels: boolean; currency: string; onClose: () => void; onChange: (c: OrderChange | null) => void; onLevels: () => void
}) {
  const s = useStyles()
  const [choices, setChoices] = useState<{ suppliers: Choice[]; units: Choice[]; supplier_units: Record<string, string> } | null>(null)
  const [v, setV] = useState({ supplier_id: row.supplier_id ?? '', product_uom_id: row.product_uom_id ?? '', qty: row.qty === '0' ? '' : row.qty, cost: row.cost ?? '' })
  const [levels, setLevels] = useState<{ min: string; max: string } | null>(null)
  useEffect(() => { api.whatToOrderLine(row.product_id).then(setChoices).catch(() => undefined) }, [row.product_id])
  if (levels) {
    return (
      <Ask title={`Levels in this store — ${row.name}`} go="Save the levels" ready={levels.min !== '' && Number(levels.max) > 0} onClose={() => setLevels(null)}
        run={() => api.whatToOrderLevels({ warehouse, product: row.product_id, min: levels.min, max: levels.max }).then(onLevels)}>
        <Text block style={{ marginBottom: 10 }}>In the base unit. Saved for the product now, so the next suggestion follows them.</Text>
        <div className={s.two}>
          <TextField label="Reorder at (minimum)" required type="number" min={0} step="any" value={levels.min} onChange={(x) => setLevels({ ...levels, min: x })} />
          <TextField label="Fill up to (maximum)" required type="number" min={0} step="any" value={levels.max} onChange={(x) => setLevels({ ...levels, max: x })} />
        </div>
      </Ask>
    )
  }
  return (
    <Ask title={row.name} go="Keep these changes" ready={!!v.supplier_id && !!v.product_uom_id && Number(v.qty) > 0} onClose={onClose} width={560}
      run={async () => onChange({ supplier_id: v.supplier_id || null, product_uom_id: v.product_uom_id || null, qty: v.qty, cost: v.cost || null })}>
      <div className={s.one}>
        <Choose label="Supplier" required options={choices?.suppliers ?? []} value={v.supplier_id} placeholder={choices ? 'Choose' : 'Loading…'}
          onChange={(x) => setV({ ...v, supplier_id: x, product_uom_id: choices?.supplier_units[x] ?? v.product_uom_id })} />
        <div className={s.two}>
          <Choose label="Unit" required options={choices?.units ?? []} value={v.product_uom_id} onChange={(x) => setV({ ...v, product_uom_id: x })} />
          <TextField label="Order quantity" required type="number" min={0} step="any" value={v.qty} onChange={(x) => setV({ ...v, qty: x })} />
        </div>
        <TextField label="Cost per unit (before tax)" type="number" min={0} step="any" prefix={currency} value={v.cost} onChange={(x) => setV({ ...v, cost: x })}
          hint="Leave empty to use this supplier's last cost. Needed when they have none yet." />
        <Text size={200} className={s.muted}>Your changes stay on this page until you create the orders.</Text>
        {(row.changed || canLevels) && <Divider />}
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {row.changed && <Button icon={<ArrowUndoRegular />} onClick={() => onChange(null)}>Undo my changes</Button>}
          {canLevels && <Button icon={<SettingsRegular />} onClick={() => setLevels({ min: row.min, max: row.max })}>Levels in this store ({row.min} / {row.max})</Button>}
        </div>
      </div>
    </Ask>
  )
}
