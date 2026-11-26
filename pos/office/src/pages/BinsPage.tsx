import { Badge, Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Switch, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArchiveRegular, ArrowRepeatAllRegular, ArrowSwapRegular, EditRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type BinRow, type Option as Choice } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '260px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  acts: { display: 'flex', gap: '6px', justifyContent: 'flex-end', flexWrap: 'wrap' },
  one: { display: 'grid', gap: '10px' },
})

type Doing = { kind: 'edit'; bin: BinRow | null } | { kind: 'move'; bin: BinRow } | { kind: 'archive' | 'restore'; bin: BinRow } | null

/**
 * Bins (part 17): shelf locations in warehouses with bins switched on, what each holds, and moving stock between them.
 * New bin suggests the next code; a bin with stock cannot be archived (move it out first). Bins are switched on per
 * warehouse ("Bins on/off"); its receiving bin is made for you.
 */
export function BinsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.bins, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  const [doing, setDoing] = useState<Doing>(null)
  const [v, setV] = useState({ warehouse_id: '', code: '', name: '', pick_order: '100', product_id: '', product_uom_id: '', qty: '', to_bin_id: '', reason: '' })
  const [help, setHelp] = useState<{ bins: Choice[]; units: Choice[] }>({ bins: [], units: [] })
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  // The next free code for a new bin, and for moving: the store's other bins and the product's units.
  useEffect(() => {
    if (doing?.kind === 'edit' && !doing.bin && v.warehouse_id) api.binHelp(v.warehouse_id).then((h) => setV((o) => (o.code ? o : { ...o, code: h.next_code }))).catch(() => undefined)
  }, [doing, v.warehouse_id])
  useEffect(() => {
    if (doing?.kind === 'move') api.binHelp(doing.bin.warehouse_id, doing.bin.id, v.product_id).then((h) => setHelp({ bins: h.bins, units: h.units })).catch(() => undefined)
  }, [doing, v.product_id])

  const finish = (message: string) => { setDoing(null); setDone(message); setNonce((n) => n + 1) }
  const open = (d: Doing) => {
    setDone('')
    setHelp({ bins: [], units: [] })
    const b = d && 'bin' in d ? d.bin : null
    setV({ warehouse_id: b?.warehouse_id ?? str(params, 'warehouse') ?? '', code: d?.kind === 'edit' ? b?.code ?? '' : '', name: b?.name ?? '', pick_order: String(b?.pick_order ?? 100),
      product_id: '', product_uom_id: '', qty: '', to_bin_id: '', reason: '' })
    setDoing(d)
  }
  const columns: Column<BinRow>[] = [
    { key: 'code', label: 'Bin', render: (r) => <><Text font="monospace" weight="semibold">{r.code}</Text>{r.name && <Text block size={200} className={s.muted}>{r.name}</Text>}</> },
    { key: 'warehouse', label: 'Warehouse', render: (r) => r.warehouse ?? '—' },
    { key: 'kind', label: 'Kind', render: (r) => <>{r.kind}{!r.active && <Badge appearance="tint" color="subtle" style={{ marginLeft: 6 }}>Off</Badge>}</> },
    { key: 'pick', label: 'Pick order', end: true, render: (r) => r.pick_order },
    { key: 'holds', label: 'Holds', render: (r) => <Text className={r.holds === 'empty' ? s.muted : undefined}>{r.holds}</Text> },
    { key: 'acts', label: '', render: (r) => (
      <div className={s.acts}>
        {r.can_move && r.products.length > 0 && <Button size="small" icon={<ArrowSwapRegular />} onClick={(e) => { e.stopPropagation(); open({ kind: 'move', bin: r }) }}>Move stock</Button>}
        {r.can_edit && !r.archived && <Button size="small" icon={<EditRegular />} onClick={(e) => { e.stopPropagation(); open({ kind: 'edit', bin: r }) }}>Edit</Button>}
        {r.can_archive && <Button size="small" appearance="subtle" icon={<ArchiveRegular />} onClick={(e) => { e.stopPropagation(); open({ kind: 'archive', bin: r }) }}>Archive</Button>}
        {r.can_restore && <Button size="small" icon={<ArrowRepeatAllRegular />} onClick={(e) => { e.stopPropagation(); open({ kind: 'restore', bin: r }) }}>Restore</Button>}
      </div>
    ) },
  ]
  const wh = str(params, 'warehouse')
  return (
    <div className={page.page}>
      <PageHead title="Bins" hint="Shelf locations in warehouses with bins switched on, what each holds, and moving stock between them." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Code or name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search bins" />
        <Dropdown aria-label="Warehouse" value={wh ? data?.warehouses.find((x) => x.id === wh)?.label ?? '' : 'Any warehouse'} selectedOptions={[wh]}
          onOptionSelect={(_, d) => set({ warehouse: d.optionValue ?? '' })} style={{ minWidth: 200 }}>
          <Option value="">Any warehouse</Option>{(data?.warehouses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Switch label="Archived" checked={str(params, 'archived') === '1'} onChange={(_, d) => set({ archived: d.checked ? '1' : '' })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => open({ kind: 'edit', bin: null })}>New bin</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Bins" columns={columns} rows={data?.rows ?? []} sort="pick" dir="asc" loading={loading}
        empty="No bins. Switch bins on for a warehouse with Bins on/off; its receiving bin is made for you." onSort={() => undefined} onOpen={() => undefined} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {doing?.kind === 'edit' && (
        <Ask title={doing.bin ? `Edit bin ${doing.bin.code}` : 'New bin'} go="Save" ready={!!v.code.trim() && (!!doing.bin || !!v.warehouse_id)} onClose={() => setDoing(null)}
          run={async () => finish((await api.saveBin({ ...(doing.bin ? { id: doing.bin.id } : { warehouse_id: v.warehouse_id }), code: v.code, name: v.name, pick_order: v.pick_order })).message)}>
          <div className={s.one}>
            {!doing.bin && <Choose label="Warehouse" required options={data?.warehouses ?? []} value={v.warehouse_id} onChange={(x) => setV({ ...v, warehouse_id: x, code: '' })}
              hint="Only warehouses with bins switched on are listed." />}
            <TextField label="Code" required maxLength={30} value={v.code} onChange={(x) => setV({ ...v, code: x })} hint="Aisle-bay-level, e.g. A-01-02, as on the shelf label. The next one is suggested." />
            <TextField label="Name" maxLength={255} value={v.name} onChange={(x) => setV({ ...v, name: x })} />
            <TextField label="Pick order" type="number" min={0} step="1" value={v.pick_order} onChange={(x) => setV({ ...v, pick_order: x })} hint="Sales and transfers take stock from lower numbers first." />
          </div>
        </Ask>
      )}
      {doing?.kind === 'move' && (
        <Ask title={`Move stock from ${doing.bin.code}`} go="Move" ready={!!v.product_id && !!v.product_uom_id && Number(v.qty) > 0 && !!v.to_bin_id} onClose={() => setDoing(null)}
          run={async () => finish((await api.moveBinStock(doing.bin.id, { product_id: v.product_id, product_uom_id: v.product_uom_id, qty: v.qty, to_bin_id: v.to_bin_id })).message)}>
          <div className={s.one}>
            <Choose label="Product" required options={doing.bin.products} value={v.product_id} onChange={(x) => setV({ ...v, product_id: x, product_uom_id: '' })} />
            <Choose label="Unit" required options={help.units} value={v.product_uom_id} onChange={(x) => setV({ ...v, product_uom_id: x })} />
            <TextField label="Quantity" required type="number" min={0} step="any" value={v.qty} onChange={(x) => setV({ ...v, qty: x })} />
            <Choose label="To bin" required options={help.bins} value={v.to_bin_id} onChange={(x) => setV({ ...v, to_bin_id: x })} />
          </div>
        </Ask>
      )}
      {(doing?.kind === 'archive' || doing?.kind === 'restore') && (
        <Ask title={`${doing.kind === 'archive' ? 'Archive' : 'Restore'} bin ${doing.bin.code}?`} go={doing.kind === 'archive' ? 'Archive' : 'Restore'}
          ready={doing.kind === 'restore' || v.reason.trim().length >= 3} onClose={() => setDoing(null)}
          run={async () => finish((await api.archiveBin(doing.bin.id, doing.kind as 'archive' | 'restore', v.reason)).message)}>
          {doing.kind === 'archive'
            ? <TextField label="Why" required maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
            : <Text>The bin can take stock again.</Text>}
        </Ask>
      )}
    </div>
  )
}
