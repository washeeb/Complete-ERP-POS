import { Button, Field, Input, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, CameraRegular, ChevronDownRegular, ChevronUpRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type AdjForm, type AdjLine, type UnitStock } from '../api'
import { Choose, LongText, Tags, TextField, useFieldStyles } from '../form/Fields'
import { UnitPicker } from '../form/UnitPicker'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px 16px', marginBottom: '14px' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  line: { padding: '10px 0', borderTop: `1px solid ${tokens.colorNeutralStroke3}` },
  row: { display: 'grid', gap: '8px 12px', alignItems: 'end',
    gridTemplateColumns: '28px minmax(220px, 4fr) minmax(150px, 2fr) minmax(90px, 1fr) minmax(90px, 1.2fr) minmax(90px, 1.2fr) minmax(90px, 1.2fr) auto auto',
    '@media (max-width: 1100px)': { gridTemplateColumns: '28px 1fr 1fr 1fr' } },
  num: { alignSelf: 'center', color: tokens.colorNeutralForeground3, fontVariantNumeric: 'tabular-nums' },
  stat: { display: 'flex', flexDirection: 'column', minWidth: 0 },
  statValue: { fontWeight: tokens.fontWeightSemibold, fontVariantNumeric: 'tabular-nums', padding: '6px 0' },
  more: { display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: '8px 12px', padding: '10px 0 0 40px',
    '@media (max-width: 900px)': { gridTemplateColumns: '1fr', paddingLeft: 0 } },
  span2: { gridColumn: 'span 2', '@media (max-width: 900px)': { gridColumn: 'auto' } },
  total: { marginTop: '12px', fontWeight: tokens.fontWeightSemibold },
  foot: { position: 'sticky', bottom: 0, zIndex: 2, display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0',
    backgroundColor: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

type Line = AdjLine & { k: number }
let seq = 0
const blank = (): Line => ({ k: ++seq, product_uom_id: '', label: null, mode: 'remove', qty: '', unit_cost: '', batch_no: '', expires_on: '', reason_code: '', note: '',
  serials: [], photo_path: '', photo_url: null, batches: false, serialised: false })
const fmt = (n: number) => String(Math.round(n * 1000) / 1000)

/** "Also on line 2": the same product unit (and batch, when given) on other lines — usually a mistake, still allowed (two batches). */
export function alsoOn(lines: Pick<AdjLine, 'product_uom_id' | 'batch_no'>[], i: number): number[] {
  const me = lines[i]
  if (!me.product_uom_id) return []
  return lines.flatMap((l, j) => (j !== i && l.product_uom_id === me.product_uom_id && (!me.batch_no || !l.batch_no || l.batch_no === me.batch_no) ? [j + 1] : []))
}

/** The change a line makes, in its own unit, and its value at average cost (minor units); null until known. */
export function lineEffect(line: Pick<AdjLine, 'mode' | 'qty' | 'unit_cost'>, s: UnitStock | null, exponent: number): { after: number | null; value: number | null } {
  if (!s || line.qty.trim() === '' || Number.isNaN(Number(line.qty))) return { after: null, value: null }
  const q = Number(line.qty)
  const after = line.mode === 'add' ? s.qty + q : line.mode === 'set' ? q : s.qty - q
  const change = after - s.qty
  const cost = line.mode === 'add' && line.unit_cost.trim() !== '' && !Number.isNaN(Number(line.unit_cost)) ? (Number(line.unit_cost) * 10 ** exponent) / s.factor : s.cost
  return { after, value: cost === null ? null : Math.round(Math.abs(change) * s.factor * cost) }
}

/**
 * A stock adjustment's draft (release 3): the warehouse, the reason and reference, and the lines — product and unit,
 * increase / decrease / set to, the quantity, what the warehouse holds now and will hold after, the value at average
 * cost — with each line's details (batch, expiry, cost, its own reason, note, photo of the goods). Nothing changes in
 * stock until the adjustment is posted; above the approval limit a second person approves it.
 */
export function AdjustmentFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<AdjForm | null>(null)
  const [head, setHead] = useState({ warehouse_id: '', reason_code: '', reference: '', note: '' })
  const [lines, setLines] = useState<Line[]>([])
  const [values, setValues] = useState<Record<number, number | null>>({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    api.adjustmentForm(id ?? undefined).then((x) => {
      setForm(x)
      setHead({ warehouse_id: x.values.warehouse_id, reason_code: x.values.reason_code, reference: x.values.reference, note: x.values.note })
      setLines(x.values.lines.length ? x.values.lines.map((l) => ({ ...l, k: ++seq })) : [blank()])
      onTitle(x.number ? `Edit adjustment ${x.number}` : 'New stock adjustment')
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (!form) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const exponent = form.exponent
  const m = (v: number) => money(v, form.currency, exponent)
  const total = lines.reduce((sum, l) => sum + (values[l.k] ?? 0), 0)
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/adjustments/${id}` : '/office/adjustments'))
  const ready = !!head.warehouse_id && !!head.reason_code && lines.length > 0 && lines.every((l) => l.product_uom_id && l.qty.trim() !== '')

  const save = () => {
    setBusy(true)
    setError('')
    api.saveAdjustment(id, { ...head, lines: lines.map(({ k: _k, ...l }) => l) })
      .then((r) => navigate(`/office/adjustments/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) })
      .finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'The adjustment' : 'Stock adjustments'}</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>{form.number ? `Edit adjustment ${form.number}` : 'New stock adjustment'}</h1>
          <Text className={s.muted}>Nothing changes in stock until the adjustment is posted.</Text>
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      <Panel>
        <div className={f.grid}>
          <Choose label="Warehouse" required options={form.choices.warehouses} value={head.warehouse_id} disabled={!!id}
            onChange={(v) => setHead({ ...head, warehouse_id: v })} hint={id ? 'A draft stays in its warehouse.' : undefined} />
          <Choose label="Reason" required options={form.choices.reasons} value={head.reason_code} onChange={(v) => setHead({ ...head, reason_code: v })} />
          <TextField label="Reference (optional)" maxLength={60} value={head.reference} onChange={(v) => setHead({ ...head, reference: v })} hint="e.g. damage report or police report number" />
          <LongText label="Note" className={f.spanAll} maxLength={2000} value={head.note} onChange={(v) => setHead({ ...head, note: v })} />
        </div>
      </Panel>

      <Panel title="Lines" about="Increase (+) adds to stock, Decrease (−) takes away, Set to replaces the quantity on hand with what you counted.">
        {lines.map((l, i) => (
          <LineRow key={l.k} n={i + 1} line={l} warehouse={head.warehouse_id} form={form} exponent={exponent} also={alsoOn(lines, i)}
            onChange={(next) => setLines((ls) => ls.map((x) => (x.k === l.k ? { ...x, ...next } : x)))}
            onRemove={lines.length > 1 ? () => { setLines((ls) => ls.filter((x) => x.k !== l.k)); setValues((v) => { const n = { ...v }; delete n[l.k]; return n }) } : undefined}
            onValue={(v) => setValues((old) => (old[l.k] === v ? old : { ...old, [l.k]: v }))} />
        ))}
        <Button icon={<AddRegular />} appearance="subtle" onClick={() => setLines((ls) => [...ls, blank()])}>Add line</Button>
        <Text block className={s.total}>
          Value at average cost: {m(total)}
          {total > form.approval_limit && <Text weight="regular" style={{ color: 'var(--zx-warn)' }}> — above {m(form.approval_limit)}: submit it for a manager to approve.</Text>}
        </Text>
      </Panel>

      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : id ? 'Save the draft' : 'Create the draft'}</Button>
      </div>
    </div>
  )
}

function Panel({ title, about, children }: { title?: string; about?: string; children: ReactNode }) {
  const s = useStyles()
  return (
    <section className={s.panel} aria-label={title ?? 'Adjustment'}>
      {title && <h2 className={s.h2}>{title}</h2>}
      {about && <Text size={200} block className={s.muted} style={{ marginBottom: 6 }}>{about}</Text>}
      {children}
    </section>
  )
}

/** One line: what it changes, what the warehouse holds now and after, its value; the details below on request. */
function LineRow({ n, line, warehouse, form, exponent, also, onChange, onRemove, onValue }: {
  n: number; line: Line; warehouse: string; form: AdjForm; exponent: number; also: number[]
  onChange: (l: Partial<Line>) => void; onRemove?: () => void; onValue: (v: number | null) => void
}) {
  const s = useStyles()
  const [stock, setStock] = useState<UnitStock | null>(null)
  const [open, setOpen] = useState(() => line.batches || !!(line.batch_no || line.expires_on || line.unit_cost || line.reason_code || line.note || line.photo_path))
  const [photoBusy, setPhotoBusy] = useState(false)
  const [photoError, setPhotoError] = useState('')
  useEffect(() => {
    if (!line.product_uom_id || !warehouse) { setStock(null); return }
    const ctl = new AbortController()
    const t = setTimeout(() => api.unitStock(line.product_uom_id, warehouse, line.batch_no, ctl.signal).then((r) => setStock(r.stock)).catch(() => undefined), 200)
    return () => { clearTimeout(t); ctl.abort() }
  }, [line.product_uom_id, warehouse, line.batch_no])
  const { after, value } = lineEffect(line, stock, exponent)
  useEffect(() => onValue(value), [value]) // eslint-disable-line react-hooks/exhaustive-deps
  const unit = stock?.unit ?? ''
  const reasons = [{ id: '', label: 'Same as the adjustment' }, ...form.choices.reasons]

  return (
    <div className={s.line}>
      <div className={s.row}>
        <Text className={s.num}>{n}</Text>
        <UnitPicker value={line.product_uom_id} label={line.label}
          onChange={(u) => { onChange({ product_uom_id: u?.id ?? '', label: u?.label ?? null, batches: !!u?.batches, serialised: !!u?.serialised }); if (u?.batches) setOpen(true) }} />
        <Choose label="Change" required options={form.choices.modes} value={line.mode} onChange={(v) => onChange({ mode: v })} />
        <TextField label={line.mode === 'set' ? 'New quantity' : 'Quantity'} required type="number" min={0} step="any" value={line.qty} onChange={(v) => onChange({ qty: v })} />
        <Stat label="Available" value={stock ? `${fmt(stock.qty)} ${unit}${stock.batch !== null ? ` · batch ${fmt(stock.batch)}` : ''}` : '—'} bad={!!stock && stock.qty < 0} />
        <Stat label="After" value={after === null ? '—' : `${fmt(after)} ${unit}`} bad={after !== null && after < 0} />
        <Stat label="Value" value={value === null ? '—' : money(value, form.currency, exponent)} />
        <Button appearance="subtle" icon={open ? <ChevronUpRegular /> : <ChevronDownRegular />} onClick={() => setOpen(!open)} aria-expanded={open}>Details</Button>
        {onRemove ? <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${n}`} title={`Remove line ${n}`} onClick={onRemove} /> : <span />}
      </div>
      {also.length > 0 && <Text block size={200} style={{ color: 'var(--zx-warn)', padding: '4px 0 0 40px' }}>Also on line {also.join(', ')}: check it is not typed twice.</Text>}
      {line.serialised && (
        <div style={{ padding: '8px 0 0 40px' }}>
          <Tags label="Serial / IMEI numbers (one per unit)" value={line.serials} onChange={(v) => onChange({ serials: v })} />
        </div>
      )}
      {open && (
        <div className={s.more}>
          <TextField label="Batch no." maxLength={60} value={line.batch_no} onChange={(v) => onChange({ batch_no: v })}
            hint={line.batches ? 'Batch-tracked: an increase needs its batch. Removing without a batch takes the earliest expiry first.' : undefined} />
          <TextField label="Expiry" type="date" value={line.expires_on} onChange={(v) => onChange({ expires_on: v })} />
          {line.mode === 'add' && <TextField label="Unit cost" type="number" min={0} step="any" prefix={form.currency} value={line.unit_cost} onChange={(v) => onChange({ unit_cost: v })} hint="Empty = average cost" />}
          <Choose label="Reason (if different)" options={reasons} value={line.reason_code} onChange={(v) => onChange({ reason_code: v })} clearable={false} />
          <Field label="Note" className={s.span2} style={{ minWidth: 0 }}><Input value={line.note} maxLength={255} onChange={(_, d) => onChange({ note: d.value })} /></Field>
          {form.photos && (
            <Field label={form.photos_required ? 'Photo of the goods (needed for losses)' : 'Photo of the goods'} style={{ minWidth: 0 }}
              validationMessage={photoError || undefined}>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                <Button icon={photoBusy ? <Spinner size="tiny" /> : <CameraRegular />} disabled={photoBusy} onClick={() => {
                  const input = document.createElement('input')
                  input.type = 'file'
                  input.accept = 'image/*'
                  input.setAttribute('capture', 'environment')
                  input.onchange = () => {
                    const file = input.files?.[0]
                    if (!file) return
                    setPhotoBusy(true)
                    setPhotoError('')
                    api.stockPhoto(file).then((r) => onChange({ photo_path: r.path, photo_url: URL.createObjectURL(file) }))
                      .catch((e: unknown) => setPhotoError(e instanceof Error ? e.message : String(e))).finally(() => setPhotoBusy(false))
                  }
                  input.click()
                }}>{line.photo_path ? 'Replace the photo' : 'Add a photo'}</Button>
                {line.photo_url && <a href={line.photo_url} target="_blank" rel="noopener"><img src={line.photo_url} alt={`Line ${n}`} style={{ height: 40, borderRadius: 4 }} /></a>}
                {line.photo_path && <Button appearance="subtle" size="small" onClick={() => onChange({ photo_path: '', photo_url: null })}>Remove</Button>}
              </div>
            </Field>
          )}
        </div>
      )}
    </div>
  )
}

function Stat({ label, value, bad }: { label: string; value: string; bad?: boolean }) {
  const s = useStyles()
  return (
    <div className={s.stat}>
      <Text size={200} className={s.muted}>{label}</Text>
      <span className={s.statValue} style={bad ? { color: 'var(--zx-bad)' } : undefined}>{value}</span>
    </div>
  )
}
