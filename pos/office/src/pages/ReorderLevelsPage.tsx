import { Badge, Button, Combobox, Dropdown, Field, Link, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowUndoRegular, EditRegular, LightbulbRegular, SparkleRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type Option as Choice, type RuleOptions, type RuleRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  one: { display: 'grid', gap: '10px' },
  two: { display: 'grid', gap: '10px', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' },
  links: { display: 'flex', gap: '14px', flexWrap: 'wrap' },
})

/**
 * Reorder levels (release 5): the products with their own minimum and maximum (or a smart level from sales) in a store,
 * shown in the buying unit. Every other stock product follows the company default. Add one for a store or every store,
 * change it, or put it back on the default.
 */
export function ReorderLevelsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<RuleOptions | null>(null)
  const [nonce, setNonce] = useState(0)
  const [editing, setEditing] = useState<{ id: string | null } | null>(null)
  const [reverting, setReverting] = useState<RuleRow | null>(null)
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.reorderRules, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.reorderRulesOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const label = (o: Choice[] | undefined, id: string) => o?.find((x) => x.id === id)?.label ?? id
  const finish = (text: string) => { setEditing(null); setReverting(null); setDone(text); setNonce((n) => n + 1) }
  const columns: Column<RuleRow>[] = [
    { key: 'product', label: 'Product', sort: 'name', render: (r) => <><Text weight="semibold">{r.product}</Text><Text block size={200} className={s.muted}>{r.sku}</Text></> },
    { key: 'store', label: 'Store', render: (r) => r.store ?? '—' },
    { key: 'on_hand', label: 'On hand', end: true, render: (r) => r.on_hand },
    { key: 'min', label: 'Min', end: true, render: (r) => r.min },
    { key: 'max', label: 'Max', end: true, render: (r) => r.max },
    { key: 'source', label: 'Set by', render: (r) => <Badge appearance="tint" color={r.source === 'smart' ? 'informative' : 'subtle'}>{r.source_label}</Badge> },
    { key: 'actions', label: '', render: (r) => r.editable && (
      <div className={s.links} onClick={(e) => e.stopPropagation()}>
        <Link onClick={() => setEditing({ id: r.id })}><EditRegular /> Change</Link>
        <Link onClick={() => setReverting(r)}><ArrowUndoRegular /> Use the default</Link>
      </div>
    ) },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Reorder levels" hint="When to reorder each product and how much to hold, per store. What to order uses these." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Product name or SKU" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search reorder levels" />
        <Dropdown aria-label="Store" value={str(params, 'warehouse') ? label(options?.warehouses, str(params, 'warehouse')) : 'Any store'} selectedOptions={[str(params, 'warehouse')]}
          onOptionSelect={(_, d) => set({ warehouse: d.optionValue ?? '' })} style={{ minWidth: 160 }}>
          <Option value="">Any store</Option>{(options?.warehouses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Set by" value={str(params, 'set_by') ? label(options?.set_by, str(params, 'set_by')) : 'Set by anyone'} selectedOptions={[str(params, 'set_by')]}
          onOptionSelect={(_, d) => set({ set_by: d.optionValue ?? '' })} style={{ minWidth: 150 }}>
          <Option value="">Set by anyone</Option>{(options?.set_by ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ExportMenu list="reorder-rules" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_order && <Button icon={<LightbulbRegular />} onClick={() => navigate('/office/what-to-order')}>What to order</Button>}
        {options?.can_suggested && <Button icon={<SparkleRegular />} onClick={() => navigate('/office/suggested-levels')}>Suggested levels</Button>}
        {options?.can_set && <Button appearance="primary" icon={<AddRegular />} onClick={() => setEditing({ id: null })}>Own figure for a product</Button>}
      </div>
      {data && <Text block size={200} className={s.muted} style={{ marginBottom: 10 }}>{data.default_note}</Text>}
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Reorder levels" columns={columns} rows={data?.rows ?? []} sort="name" dir={data?.dir ?? 'asc'} loading={loading}
        empty="No product has its own figure: every stock product follows the company default." onSort={(_, dir) => set({ dir })}
        onOpen={(r) => { if (r.editable) setEditing({ id: r.id }) }} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {editing && options && <RuleDialog id={editing.id} stores={options.stores} onClose={() => setEditing(null)} onDone={() => finish('Saved.')} />}
      {reverting && (
        <Ask title={`${reverting.product}: use the default?`} go="Use the default" ready onClose={() => setReverting(null)}
          run={() => api.reorderUseDefault(reverting.id).then(() => finish('Back to the default.'))}>
          <Text>In {reverting.store} this product goes back to the company default levels.</Text>
        </Ask>
      )}
    </div>
  )
}

/** Own figure for a product (a store or every store), or a change: the unit the figures are in, then min and max. */
function RuleDialog({ id, stores, onClose, onDone }: { id: string | null; stores: Choice[]; onClose: () => void; onDone: () => void }) {
  const s = useStyles()
  const [v, setV] = useState({ warehouse_id: '', product_id: '', product_label: '', product_uom_id: '', min: '', max: '' })
  const [units, setUnits] = useState<Choice[]>([])
  const [found, setFound] = useState<Choice[]>([])
  const [text, setText] = useState('')
  const [title, setTitle] = useState('Own figure for a product')
  useEffect(() => {
    if (!id) return
    api.reorderRuleForm(id).then((f) => {
      setV({ warehouse_id: f.warehouse_id, product_id: f.product_id, product_label: f.product ?? '', product_uom_id: f.product_uom_id, min: f.min, max: f.max })
      setUnits(f.units)
      setTitle(`${f.product} · ${f.store}`)
    }).catch(() => undefined)
  }, [id])
  useEffect(() => {
    if (id || text.trim().length < 2 || text === v.product_label) return
    const t = setTimeout(() => api.reorderProducts(text.trim()).then((r) => setFound(r.results)).catch(() => undefined), 250)
    return () => clearTimeout(t)
  }, [text, id, v.product_label])
  const pick = (productId: string, label: string) => {
    setV((o) => ({ ...o, product_id: productId, product_label: label, product_uom_id: '' }))
    setText(label)
    api.reorderUnits(productId).then((u) => { setUnits(u.units); setV((o) => ({ ...o, product_uom_id: u.buying ?? '' })) }).catch(() => undefined)
  }
  const ready = !!v.warehouse_id && !!v.product_id && !!v.product_uom_id && v.min !== '' && Number(v.max) > 0
  return (
    <Ask title={title} go="Save" ready={ready} onClose={onClose} width={560}
      run={() => api.saveReorderRule({ warehouse_id: v.warehouse_id, product_id: v.product_id, product_uom_id: v.product_uom_id, min: v.min, max: v.max }).then(onDone)}>
      <div className={s.one}>
        {!id && (
          <>
            <Choose label="Store" required options={stores} value={v.warehouse_id} onChange={(x) => setV({ ...v, warehouse_id: x })} />
            <Field label="Product" required>
              <Combobox value={text} placeholder="Name or SKU" onChange={(e) => setText(e.target.value)} selectedOptions={v.product_id ? [v.product_id] : []}
                onOptionSelect={(_, d) => { if (d.optionValue) pick(d.optionValue, d.optionText ?? '') }}>
                {found.map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
                {found.length === 0 && <Option disabled value="">{text.trim().length < 2 ? 'Type at least two letters' : 'Nothing matches'}</Option>}
              </Combobox>
            </Field>
          </>
        )}
        <Choose label="Unit" required options={units} value={v.product_uom_id} onChange={(x) => setV({ ...v, product_uom_id: x })}
          hint="The unit the figures below are in (the buying unit is chosen for you)." />
        <div className={s.two}>
          <TextField label="Minimum" required type="number" min={0} step="any" value={v.min} onChange={(x) => setV({ ...v, min: x })}
            hint="Reorder when stock plus what is on order falls to this." />
          <TextField label="Maximum (order up to)" required type="number" min={0} step="any" value={v.max} onChange={(x) => setV({ ...v, max: x })} hint="Top up to this level." />
        </div>
      </div>
    </Ask>
  )
}
