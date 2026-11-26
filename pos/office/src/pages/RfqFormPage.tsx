import { Button, MessageBar, MessageBarBody, Spinner, Text } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api, type RfqForm } from '../api'
import { Choose, ChooseMany, LongText, TextField, useFieldStyles } from '../form/Fields'
import { UnitPicker } from '../form/UnitPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

type Line = { product_uom_id: string; label: string | null; qty: string; note: string }
const blank = (): Line => ({ product_uom_id: '', label: null, qty: '', note: '' })

/**
 * A new request for quotation (part 18), as the current form: a title, where it is delivered, when quotes close (prices
 * stay sealed until then), when it is needed, the suppliers to ask, what they are told, and the items with what the
 * store holds now.
 */
export function RfqFormPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<RfqForm | null>(null)
  const [v, setV] = useState({ title: '', warehouse_id: '', closes_at: '', needed_by: '', supplier_ids: [] as string[], terms: '' })
  const [lines, setLines] = useState<Line[]>([blank()])
  const [avail, setAvail] = useState<Record<number, string | null>>({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const top = useRef<HTMLDivElement>(null)
  useEffect(() => {
    api.rfqForm().then((x) => { setForm(x); setV((o) => ({ ...o, warehouse_id: x.warehouse_id, closes_at: x.closes_at })) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])
  const keys = lines.map((l) => l.product_uom_id).join('|') + `|${v.warehouse_id}`
  useEffect(() => {
    lines.forEach((l, i) => {
      if (!l.product_uom_id || !v.warehouse_id) return
      api.procurementAvailable(l.product_uom_id, v.warehouse_id).then((x) => setAvail((o) => ({ ...o, [i]: x.available }))).catch(() => undefined)
    })
  }, [keys]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!form) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const line = (i: number, patch: Partial<Line>) => setLines((o) => o.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const ready = !!v.title.trim() && !!v.warehouse_id && !!v.closes_at && v.supplier_ids.length > 0 && lines.every((l) => !!l.product_uom_id && Number(l.qty) > 0)
  const back = () => (history.length > 1 ? history.back() : navigate('/office/rfqs'))
  const save = () => {
    setBusy(true)
    setError('')
    api.saveRfq({ ...v, needed_by: v.needed_by || null, lines: lines.map(({ label: _l, ...l }) => l) }).then((r) => navigate(`/office/rfqs/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Requests for quotation</Button>
      <h1 className={s.title} style={{ marginBottom: 4 }}>New request for quotation</h1>
      <Text className={s.muted}>The same list to several suppliers; their prices stay sealed until the closing time.</Text>
      {error && <MessageBar intent="error" style={{ margin: '12px 0' }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <div style={{ height: 12 }} />
      <Panel title="Request">
        <div className={f.grid}>
          <TextField label="Title" required maxLength={160} placeholder="e.g. Antimalarials for November" value={v.title} onChange={(x) => setV({ ...v, title: x })} />
          <Choose label="Deliver to" required options={form.warehouses} value={v.warehouse_id} onChange={(x) => setV({ ...v, warehouse_id: x })} />
          <TextField label="Quotes close" required type="datetime-local" value={v.closes_at} onChange={(x) => setV({ ...v, closes_at: x })} hint="Prices stay sealed until then, unless you close the request earlier." />
          <TextField label="Needed by (optional)" type="date" value={v.needed_by} onChange={(x) => setV({ ...v, needed_by: x })} />
          <ChooseMany label="Suppliers to ask" options={form.suppliers} value={v.supplier_ids} onChange={(x) => setV({ ...v, supplier_ids: x })} hint="Three or more give a fair comparison." />
          <LongText label="What the suppliers are told (optional)" className={f.spanAll} maxLength={2000} value={v.terms} onChange={(x) => setV({ ...v, terms: x })} />
        </div>
      </Panel>
      <Panel title="Items">
        {lines.map((l, i) => (
          <div key={i} className={s.line} role="group" aria-label={`Item ${i + 1}`}>
            <div className={s.lineHead}>
              <Text weight="semibold">{i + 1}.</Text><span className={s.grow} />
              <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove item ${i + 1}`} disabled={lines.length === 1} onClick={() => { setLines(lines.filter((_, j) => j !== i)); setAvail({}) }} />
            </div>
            <div className={s.grid}>
              <div>
                <UnitPicker value={l.product_uom_id} label={l.label} search={api.procurementUnits} onChange={(u) => line(i, { product_uom_id: u?.id ?? '', label: u?.label ?? null })} />
                {l.product_uom_id && avail[i] && <Text block size={200} className={s.muted} style={{ marginTop: 6 }}>In stock now: {avail[i]}</Text>}
              </div>
              <TextField label="Quantity" required type="number" min={0} step="any" value={l.qty} onChange={(x) => line(i, { qty: x })} />
              <TextField label="Note to suppliers (optional)" maxLength={255} value={l.note} onChange={(x) => line(i, { note: x })} />
            </div>
          </div>
        ))}
        <Button icon={<AddRegular />} style={{ marginTop: 10 }} onClick={() => setLines([...lines, blank()])}>Add item</Button>
      </Panel>
      <SaveBar value={[v, lines]}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Make the request'}</Button>
      </SaveBar>
    </div>
  )
}
