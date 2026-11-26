import { Button, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SendRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type Held, type RequestForm, type RequestLine } from '../api'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
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
  row: { display: 'grid', gap: '8px 12px', alignItems: 'end',
    gridTemplateColumns: '28px minmax(240px, 4fr) minmax(100px, 1fr) minmax(100px, 1fr) minmax(100px, 1fr) auto',
    '@media (max-width: 900px)': { gridTemplateColumns: '28px 1fr 1fr' } },
  num: { alignSelf: 'center', color: tokens.colorNeutralForeground3 },
  stat: { display: 'flex', flexDirection: 'column', minWidth: 0 },
  statValue: { fontWeight: tokens.fontWeightSemibold, fontVariantNumeric: 'tabular-nums', padding: '6px 0' },
  foot: { position: 'sticky', bottom: 0, zIndex: 2, display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0',
    backgroundColor: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

type Line = RequestLine & { k: number; product_id: string }
let seq = 0
const blank = (): Line => ({ k: ++seq, product_uom_id: '', label: null, product_id: '', qty: '' })
const held = (h: Held) => (h ? `${String(Math.round(h.qty * 1000) / 1000)} ${h.unit}` : '—')

/**
 * Ask for stock (release 3): for which of your warehouses, whom to ask (any warehouse; another industry's branch must
 * approve, and so must a manager of yours, before anything is sent), why and by when, and the lines — items as your
 * branch sells them, with what you and they hold now. A request cannot be edited: cancel it and ask again.
 */
export function RequestFormPage({ onTitle }: { onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<RequestForm | null>(null)
  const [head, setHead] = useState({ to_warehouse_id: '', from_warehouse_id: '', note: '' })
  const [lines, setLines] = useState<Line[]>([blank()])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    onTitle('Ask for stock')
    api.requestForm().then((x) => {
      setForm(x)
      setHead({ to_warehouse_id: x.values.to_warehouse_id, from_warehouse_id: x.values.from_warehouse_id, note: x.values.note })
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [onTitle])

  if (!form) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const twice = repeated(lines)
  const same = !!head.to_warehouse_id && head.to_warehouse_id === head.from_warehouse_id
  const ready = !!head.to_warehouse_id && !!head.from_warehouse_id && !same && lines.every((l) => l.product_uom_id && Number(l.qty) > 0)
  const back = () => (history.length > 1 ? history.back() : navigate('/office/requests'))
  const save = () => {
    setBusy(true)
    setError('')
    api.saveRequest({ ...head, lines: lines.map((l) => ({ product_uom_id: l.product_uom_id, label: l.label, qty: l.qty })) })
      .then((r) => navigate(`/office/requests/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) })
      .finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Stock requests</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>Ask for stock</h1>
          <Text className={s.muted}>Choose items as your branch sells them. A request is not edited later: cancel it and ask again.</Text>
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      <Panel>
        <div className={f.grid}>
          <Choose label="For (your warehouse)" required options={form.choices.to} value={head.to_warehouse_id} onChange={(v) => setHead({ ...head, to_warehouse_id: v })} />
          <Choose label="Ask" required options={form.choices.from} value={head.from_warehouse_id} onChange={(v) => setHead({ ...head, from_warehouse_id: v })}
            hint={same ? 'Ask a different warehouse from your own.' : 'A branch of another industry must approve, and so must a manager of yours, before anything is sent.'} />
          <LongText label="Note (why, by when)" className={f.spanAll} maxLength={2000} value={head.note} onChange={(v) => setHead({ ...head, note: v })} />
        </div>
      </Panel>

      <Panel title="What you need">
        {lines.map((l, i) => (
          <LineRow key={l.k} n={i + 1} line={l} forWh={head.to_warehouse_id} fromWh={head.from_warehouse_id} twice={twice.includes(i + 1)}
            onChange={(next) => setLines((ls) => ls.map((x) => (x.k === l.k ? { ...x, ...next } : x)))}
            onRemove={lines.length > 1 ? () => setLines((ls) => ls.filter((x) => x.k !== l.k)) : undefined} />
        ))}
        <Button icon={<AddRegular />} appearance="subtle" onClick={() => setLines((ls) => [...ls, blank()])}>Add line</Button>
      </Panel>

      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SendRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Asking…' : 'Ask for it'}</Button>
      </div>
    </div>
  )
}

function Panel({ title, children }: { title?: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title ?? 'Stock request'}>{title && <h2 className={s.h2}>{title}</h2>}{children}</section>
}

function LineRow({ n, line, forWh, fromWh, twice, onChange, onRemove }: {
  n: number; line: Line; forWh: string; fromWh: string; twice: boolean; onChange: (l: Partial<Line>) => void; onRemove?: () => void
}) {
  const s = useStyles()
  const [stock, setStock] = useState<{ mine: Held; theirs: Held } | null>(null)
  useEffect(() => {
    if (!line.product_uom_id) { setStock(null); return }
    const ctl = new AbortController()
    const t = setTimeout(() => api.requestStock(line.product_uom_id, forWh, fromWh, ctl.signal).then(setStock).catch(() => undefined), 200)
    return () => { clearTimeout(t); ctl.abort() }
  }, [line.product_uom_id, forWh, fromWh])
  const short = !!stock?.theirs && Number(line.qty) > stock.theirs.qty
  return (
    <div className={s.line}>
      <div className={s.row}>
        <Text className={s.num}>{n}</Text>
        <UnitPicker value={line.product_uom_id} label={line.label}
          onChange={(u) => onChange({ product_uom_id: u?.id ?? '', product_id: u?.product_id ?? '', label: u?.label ?? null })} />
        <TextField label="Quantity" required type="number" min={0} step="any" value={line.qty} onChange={(v) => onChange({ qty: v })} />
        <div className={s.stat}><Text size={200} className={s.muted}>You have</Text><span className={s.statValue}>{held(stock?.mine ?? null)}</span></div>
        <div className={s.stat}>
          <Text size={200} className={s.muted}>They have</Text>
          <span className={s.statValue} style={short ? { color: 'var(--zx-warn)' } : undefined}>{held(stock?.theirs ?? null)}</span>
        </div>
        {onRemove ? <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${n}`} title={`Remove line ${n}`} onClick={onRemove} /> : <span />}
      </div>
      {twice && <Text block size={200} style={{ color: 'var(--zx-warn)', padding: '4px 0 0 40px' }}>This product is also on an earlier line: check it is not typed twice.</Text>}
      {short && <Text block size={200} style={{ color: 'var(--zx-warn)', padding: '4px 0 0 40px' }}>More than they hold now: they may send less.</Text>}
    </div>
  )
}
