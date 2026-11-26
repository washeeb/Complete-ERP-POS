import { Button, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type TransferForm, type TransferLine, type UnitStock } from '../api'
import { Choose, LongText, Tags, TextField, useFieldStyles } from '../form/Fields'
import { UnitPicker } from '../form/UnitPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { repeated } from './CountPage'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px 16px', marginBottom: '14px' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 6px' },
  line: { padding: '10px 0', borderTop: `1px solid ${tokens.colorNeutralStroke3}` },
  row: { display: 'grid', gap: '8px 12px', alignItems: 'end', gridTemplateColumns: '28px minmax(240px, 4fr) minmax(100px, 1fr) minmax(140px, 1.5fr) auto',
    '@media (max-width: 900px)': { gridTemplateColumns: '28px 1fr 1fr' } },
  num: { alignSelf: 'center', color: tokens.colorNeutralForeground3 },
  stat: { display: 'flex', flexDirection: 'column', minWidth: 0 },
  statValue: { fontWeight: tokens.fontWeightSemibold, fontVariantNumeric: 'tabular-nums', padding: '6px 0' },
  foot: { position: 'sticky', bottom: 0, zIndex: 2, display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0',
    backgroundColor: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

type Line = TransferLine & { k: number }
let seq = 0
const blank = (): Line => ({ k: ++seq, product_uom_id: '', label: null, product_id: '', qty: '', serials: [], serialised: false })
const fmt = (n: number) => String(Math.round(n * 1000) / 1000)

/**
 * A transfer's draft (release 3): from which warehouse (one the person may send from) to which, a note, and the lines —
 * product and unit, the quantity, what the sending warehouse has to send, serial numbers for serialised items. Nothing
 * moves until it is dispatched.
 */
export function TransferFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<TransferForm | null>(null)
  const [head, setHead] = useState({ from_warehouse_id: '', to_warehouse_id: '', note: '' })
  const [lines, setLines] = useState<Line[]>([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    api.transferForm(id ?? undefined).then((x) => {
      setForm(x)
      setHead({ from_warehouse_id: x.values.from_warehouse_id, to_warehouse_id: x.values.to_warehouse_id, note: x.values.note })
      setLines(x.values.lines.length ? x.values.lines.map((l) => ({ ...l, k: ++seq })) : [blank()])
      onTitle(x.number ? `Edit transfer ${x.number}` : 'New transfer')
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (!form) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const twice = repeated(lines)
  const same = !!head.from_warehouse_id && head.from_warehouse_id === head.to_warehouse_id
  const ready = !!head.from_warehouse_id && !!head.to_warehouse_id && !same && lines.every((l) => l.product_uom_id && l.qty.trim() !== '')
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/transfers/${id}` : '/office/transfers'))
  const save = () => {
    setBusy(true)
    setError('')
    api.saveTransfer(id, { ...head, lines: lines.map(({ k: _k, ...l }) => l) }).then((r) => navigate(`/office/transfers/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) })
      .finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'The transfer' : 'Transfers'}</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>{form.number ? `Edit transfer ${form.number}` : 'New transfer'}</h1>
          <Text className={s.muted}>Nothing moves until the transfer is dispatched.</Text>
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      <Panel>
        <div className={f.grid}>
          <Choose label="From" required options={form.choices.from} value={head.from_warehouse_id} onChange={(v) => setHead({ ...head, from_warehouse_id: v })} />
          <Choose label="To" required options={form.choices.to} value={head.to_warehouse_id} onChange={(v) => setHead({ ...head, to_warehouse_id: v })}
            hint={same ? 'Choose two different warehouses.' : undefined} />
          <LongText label="Note" className={f.spanAll} maxLength={2000} value={head.note} onChange={(v) => setHead({ ...head, note: v })} />
        </div>
      </Panel>

      <Panel title="Lines">
        {lines.map((l, i) => (
          <LineRow key={l.k} n={i + 1} line={l} from={head.from_warehouse_id} twice={twice.includes(i + 1)}
            onChange={(next) => setLines((ls) => ls.map((x) => (x.k === l.k ? { ...x, ...next } : x)))}
            onRemove={lines.length > 1 ? () => setLines((ls) => ls.filter((x) => x.k !== l.k)) : undefined} />
        ))}
        <Button icon={<AddRegular />} appearance="subtle" onClick={() => setLines((ls) => [...ls, blank()])}>Add line</Button>
      </Panel>

      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : id ? 'Save the draft' : 'Create the draft'}</Button>
      </div>
    </div>
  )
}

function Panel({ title, children }: { title?: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title ?? 'Transfer'}>{title && <h2 className={s.h2}>{title}</h2>}{children}</section>
}

function LineRow({ n, line, from, twice, onChange, onRemove }: {
  n: number; line: Line; from: string; twice: boolean; onChange: (l: Partial<Line>) => void; onRemove?: () => void
}) {
  const s = useStyles()
  const [stock, setStock] = useState<UnitStock | null>(null)
  useEffect(() => {
    if (!line.product_uom_id || !from) { setStock(null); return }
    const ctl = new AbortController()
    const t = setTimeout(() => api.unitStock(line.product_uom_id, from, '', ctl.signal).then((r) => setStock(r.stock)).catch(() => undefined), 200)
    return () => { clearTimeout(t); ctl.abort() }
  }, [line.product_uom_id, from])
  const short = stock !== null && line.qty.trim() !== '' && Number(line.qty) > stock.qty
  return (
    <div className={s.line}>
      <div className={s.row}>
        <Text className={s.num}>{n}</Text>
        <UnitPicker value={line.product_uom_id} label={line.label}
          onChange={(u) => onChange({ product_uom_id: u?.id ?? '', product_id: u?.product_id ?? '', label: u?.label ?? null, serialised: !!u?.serialised })} />
        <TextField label="Quantity" required type="number" min={0} step="any" value={line.qty} onChange={(v) => onChange({ qty: v })} />
        <div className={s.stat}>
          <Text size={200} className={s.muted}>Available to send</Text>
          <span className={s.statValue} style={short || (stock && stock.qty < 0) ? { color: 'var(--zx-bad)' } : undefined}>{stock ? `${fmt(stock.qty)} ${stock.unit}` : '—'}</span>
        </div>
        {onRemove ? <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${n}`} title={`Remove line ${n}`} onClick={onRemove} /> : <span />}
      </div>
      {twice && <Text block size={200} style={{ color: 'var(--zx-warn)', padding: '4px 0 0 40px' }}>This product is also on an earlier line: check it is not typed twice.</Text>}
      {short && <Text block size={200} style={{ color: 'var(--zx-bad)', padding: '4px 0 0 40px' }}>More than the sending warehouse holds.</Text>}
      {line.serialised && <div style={{ padding: '8px 0 0 40px' }}><Tags label="Serial / IMEI numbers (one per unit)" value={line.serials} onChange={(v) => onChange({ serials: v })} /></div>}
    </div>
  )
}
