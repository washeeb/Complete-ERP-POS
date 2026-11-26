import { Button, makeStyles, MessageBar, MessageBarBody, Spinner, Text, ToggleButton, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type PoForm, type PoLine, type PoValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { UnitPicker } from '../form/UnitPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px 16px', marginBottom: '14px' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  about: { display: 'block', marginBottom: '12px', color: tokens.colorNeutralForeground3 },
  line: { padding: '12px 0', borderBottom: `1px solid ${tokens.colorNeutralStroke2}` },
  lineHead: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px', flexWrap: 'wrap' },
  grow: { flex: '1 1 auto' },
  grid: { display: 'grid', gridTemplateColumns: 'minmax(0, 3fr) repeat(2, minmax(0, 1fr))', gap: '8px 10px', alignItems: 'start',
    '@media (max-width: 760px)': { gridTemplateColumns: '1fr' } },
  note: { display: 'block', marginTop: '6px' },
  one: { display: 'grid', gap: '10px' },
  foot: { position: 'sticky', bottom: 0, zIndex: 2, display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0',
    backgroundColor: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

type Line = PoLine & { pid?: string }
type Info = { rule: string | null; available: string | null }
const blank = (kind: 'stock' | 'service' = 'stock'): Line => ({ kind, product_uom_id: '', label: null, account_id: '', description: '', uom_code: 'EA', department_id: '',
  project_id: '', agreement_line_id: '', qty: '', unit_cost: '' })

/**
 * A purchase order draft (release 5): the supplier (add a missing one here), where it is delivered, when, and the lines —
 * products in their buying unit (the supplier's pack rule and what the store holds are shown), or services and non-stock
 * items with the kind of spending. Leave a cost blank to use the agreed price or the supplier's last cost. A rule the
 * save breaks (a pack rule, a blocked supplier…) is said at the top, and nothing is saved.
 */
export function PurchaseOrderFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<PoForm | null>(null)
  const [v, setV] = useState<(Omit<PoValues, 'lines'> & { lines: Line[] }) | null>(null)
  const [info, setInfo] = useState<Record<number, Info>>({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [adding, setAdding] = useState(false)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    api.purchaseOrderForm(id ?? undefined).then((x) => {
      setForm(x)
      setV({ ...x.values, lines: x.values.lines.length ? x.values.lines : [blank()] })
      onTitle(x.number ? `Edit ${x.number}` : 'New purchase order')
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  // The supplier's pack rule and the store's stock for each product line, when the supplier, store or product changes.
  const keys = v ? v.lines.map((l) => (l.kind === 'stock' ? l.product_uom_id : '')).join('|') + `|${v.supplier_id}|${v.warehouse_id}` : ''
  useEffect(() => {
    if (!v) return
    v.lines.forEach((l, i) => {
      if (l.kind !== 'stock' || !l.product_uom_id) return
      api.poLineInfo(v.supplier_id, l.product_uom_id, v.warehouse_id).then((x) => setInfo((o) => ({ ...o, [i]: x }))).catch(() => undefined)
    })
  }, [keys]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!form || !v) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const c = form.choices
  const set = <K extends keyof PoValues>(k: K) => (x: PoValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const line = (i: number, patch: Partial<Line>) => setV((o) => (o ? { ...o, lines: o.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)) } : o))
  const dupes = (i: number) => {
    const me = v.lines[i]
    return me.kind === 'stock' && me.pid ? v.lines.some((l, j) => j !== i && l.kind === 'stock' && l.pid === me.pid) : false
  }
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/purchase-orders/${id}` : '/office/purchase-orders'))
  const ok = (l: Line) => Number(l.qty) > 0 && (l.kind === 'stock' ? !!l.product_uom_id : !!l.description.trim() && !!l.account_id)
  const ready = !!v.supplier_id && !!v.warehouse_id && v.lines.length > 0 && v.lines.every(ok)
  const save = () => {
    setBusy(true)
    setError('')
    api.savePurchaseOrder(id, { ...v, lines: v.lines.map(({ pid: _pid, ...l }) => l) }).then((r) => navigate(`/office/purchase-orders/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) })
      .finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'The order' : 'Purchase orders'}</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>{id ? `Edit ${form.number}` : 'New purchase order'}</h1>
          <Text className={s.muted}>{form.number ? 'A draft: it can change until it is submitted for approval.' : 'Saved as a draft; submit it for approval from its page.'}</Text>
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      <Panel title="Order">
        <div className={f.grid}>
          <Choose label="Supplier" required options={c.suppliers} value={v.supplier_id} onChange={set('supplier_id')} onAdd={form.can_add_supplier ? () => setAdding(true) : undefined} />
          <Choose label="Deliver to" required options={c.warehouses} value={v.warehouse_id} onChange={set('warehouse_id')} />
          <TextField label="Expected delivery" type="date" value={v.expected_on} onChange={set('expected_on')} />
          <Choose label="Department (cost centre)" options={c.departments} value={v.department_id} onChange={set('department_id')} />
          <LongText label="Note" className={f.spanAll} maxLength={5000} value={v.note} onChange={set('note')} />
        </div>
      </Panel>

      <Panel title="Lines" about="Costs are before tax. Leave the cost blank to use the agreed price (contract) or the supplier's last cost. A service or non-stock item is confirmed as delivered on the order instead of being received into stock.">
        {v.lines.map((l, i) => (
          <div key={i} className={s.line} role="group" aria-label={`Line ${i + 1}`}>
            <div className={s.lineHead}>
              <Text weight="semibold">{i + 1}.</Text>
              <ToggleButton size="small" checked={l.kind === 'stock'} onClick={() => line(i, { ...blank('stock'), qty: l.qty })}>Product</ToggleButton>
              <ToggleButton size="small" checked={l.kind === 'service'} onClick={() => line(i, { ...blank('service'), qty: l.qty })}>Service or non-stock item</ToggleButton>
              <span className={s.grow} />
              <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${i + 1}`} disabled={v.lines.length === 1}
                onClick={() => { setV({ ...v, lines: v.lines.filter((_, j) => j !== i) }); setInfo({}) }} />
            </div>
            <div className={s.grid}>
              {l.kind === 'stock' ? (
                <div>
                  <UnitPicker value={l.product_uom_id} label={l.label} search={api.poUnits} onChange={(u) => line(i, { product_uom_id: u?.id ?? '', label: u?.label ?? null, pid: u?.product_id })} />
                  {(info[i]?.rule || info[i]?.available) && l.product_uom_id && (
                    <Text size={200} className={s.note} style={{ color: tokens.colorNeutralForeground3 }}>
                      {[info[i]?.rule, info[i]?.available ? `In stock there: ${info[i]!.available}` : null].filter(Boolean).join(' · ')}
                    </Text>
                  )}
                  {dupes(i) && <Text size={200} className={s.note} style={{ color: 'var(--zx-warn)' }}>This product is on another line too.</Text>}
                </div>
              ) : (
                <div className={s.one}>
                  <TextField label="What" required maxLength={160} placeholder="e.g. Servicing of the generator" value={l.description} onChange={(x) => line(i, { description: x })} />
                  <Choose label="Kind of spending" required options={c.accounts} value={l.account_id} onChange={(x) => line(i, { account_id: x })} />
                </div>
              )}
              <TextField label="Quantity" required type="number" min={0} step="any" value={l.qty} onChange={(x) => line(i, { qty: x })} />
              <TextField label="Cost per unit" type="number" min={0} step="any" prefix={form.currency} value={l.unit_cost} onChange={(x) => line(i, { unit_cost: x })} />
              {l.kind === 'service' && (
                <>
                  <TextField label="Unit" maxLength={10} value={l.uom_code} onChange={(x) => line(i, { uom_code: x })} hint="EA, JOB, HOUR, MONTH…" />
                  <Choose label="Department (cost centre)" options={c.departments} value={l.department_id} onChange={(x) => line(i, { department_id: x })} />
                  {c.projects && <Choose label="For project" options={c.projects} value={l.project_id} onChange={(x) => line(i, { project_id: x })} />}
                </>
              )}
            </div>
          </div>
        ))}
        <Button icon={<AddRegular />} style={{ marginTop: 10 }} onClick={() => setV({ ...v, lines: [...v.lines, blank()] })}>Add line</Button>
      </Panel>

      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Save draft'}</Button>
      </div>

      {adding && <AddSupplier onClose={() => setAdding(false)} onAdded={(sup) => {
        setForm({ ...form, choices: { ...c, suppliers: c.suppliers.some((x) => x.id === sup.id) ? c.suppliers : [...c.suppliers, { id: sup.id, label: sup.label }] } })
        set('supplier_id')(sup.id)
        setAdding(false)
      }} />}
    </div>
  )
}

function Panel({ title, about, children }: { title: string; about?: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{about && <Text className={s.about}>{about}</Text>}{children}</section>
}

/** A missing supplier, added from the order (one already there under that name or code is chosen instead). */
function AddSupplier({ onClose, onAdded }: { onClose: () => void; onAdded: (s: { id: string; label: string }) => void }) {
  const s = useStyles()
  const [v, setV] = useState({ code: '', name: '', phone: '', email: '' })
  return (
    <Ask title="Add a supplier" go="Add" ready={!!v.name.trim()} onClose={onClose}
      run={() => api.poAddSupplier({ name: v.name.trim(), code: v.code.trim() || undefined, phone: v.phone.trim() || undefined, email: v.email.trim() || undefined }).then(onAdded)}>
      <div className={s.one}>
        <TextField label="Name" required maxLength={255} value={v.name} onChange={(x) => setV({ ...v, name: x })} />
        <TextField label="Code" maxLength={20} placeholder="Given by the system" value={v.code} onChange={(x) => setV({ ...v, code: x })} />
        <TextField label="Phone" maxLength={30} value={v.phone} onChange={(x) => setV({ ...v, phone: x })} />
        <TextField label="Email" maxLength={255} value={v.email} onChange={(x) => setV({ ...v, email: x })} />
      </div>
    </Ask>
  )
}
