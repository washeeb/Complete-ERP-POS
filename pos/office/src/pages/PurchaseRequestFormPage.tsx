import { Button, MessageBar, MessageBarBody, Spinner, Text } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api, type PrForm, type SpendLine } from '../api'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { blankSpend, SpendItem, SpendKind, spendReady } from '../form/SpendLineFields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

type Line = SpendLine & { qty: string; est_unit_cost: string; note: string }
const blank = (kind: 'stock' | 'service' = 'stock'): Line => ({ ...blankSpend(kind), qty: '', est_unit_cost: '', note: '' })

/**
 * A new purchase request (part 18), as the current form: the store it is for, when it is needed, the department, a note,
 * and what to order — products (with what the store holds now) or services with an estimated price for the budget
 * check. A buyer then orders it or asks for quotes.
 */
export function PurchaseRequestFormPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<PrForm | null>(null)
  const [v, setV] = useState({ warehouse_id: '', needed_by: '', department_id: '', note: '' })
  const [lines, setLines] = useState<Line[]>([blank()])
  const [avail, setAvail] = useState<Record<number, string | null>>({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const top = useRef<HTMLDivElement>(null)
  useEffect(() => {
    api.purchaseRequestForm().then((x) => { setForm(x); setV((o) => ({ ...o, warehouse_id: x.warehouse_id })) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])
  const keys = lines.map((l) => (l.kind === 'stock' ? l.product_uom_id : '')).join('|') + `|${v.warehouse_id}`
  useEffect(() => {
    lines.forEach((l, i) => {
      if (l.kind !== 'stock' || !l.product_uom_id || !v.warehouse_id) return
      api.procurementAvailable(l.product_uom_id, v.warehouse_id).then((x) => setAvail((o) => ({ ...o, [i]: x.available }))).catch(() => undefined)
    })
  }, [keys]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!form) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const line = (i: number, patch: Partial<Line>) => setLines((o) => o.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const ready = !!v.warehouse_id && lines.length > 0 && lines.every((l) => spendReady(l) && Number(l.qty) > 0)
  const back = () => (history.length > 1 ? history.back() : navigate('/office/purchase-requests'))
  const save = () => {
    setBusy(true)
    setError('')
    api.savePurchaseRequest({ ...v, lines: lines.map(({ label: _l, ...l }) => l) }).then((r) => navigate(`/office/purchase-requests/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Purchase requests</Button>
      <h1 className={s.title} style={{ marginBottom: 4 }}>New purchase request</h1>
      <Text className={s.muted}>What the store needs. A buyer orders it or asks suppliers for prices.</Text>
      {error && <MessageBar intent="error" style={{ margin: '12px 0' }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <div style={{ height: 12 }} />
      <Panel title="Request">
        <div className={f.grid}>
          <Choose label="For the store" required options={form.warehouses} value={v.warehouse_id} onChange={(x) => setV({ ...v, warehouse_id: x })} />
          <TextField label="Needed by (optional)" type="date" value={v.needed_by} onChange={(x) => setV({ ...v, needed_by: x })} />
          <Choose label="Department (cost centre)" options={form.departments} value={v.department_id} onChange={(x) => setV({ ...v, department_id: x })} />
          <LongText label="Note" className={f.spanAll} maxLength={2000} value={v.note} onChange={(x) => setV({ ...v, note: x })} />
        </div>
      </Panel>
      <Panel title="What to order" about="A service's estimated price is for the budget check; the buyer gets the real price.">
        {lines.map((l, i) => (
          <div key={i} className={s.line} role="group" aria-label={`Line ${i + 1}`}>
            <div className={s.lineHead}>
              <Text weight="semibold">{i + 1}.</Text>
              <SpendKind line={l} onChange={(p) => line(i, { ...p, qty: l.qty })} />
              <span className={s.grow} />
              <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${i + 1}`} disabled={lines.length === 1} onClick={() => { setLines(lines.filter((_, j) => j !== i)); setAvail({}) }} />
            </div>
            <div className={s.grid}>
              <div>
                <SpendItem line={l} accounts={form.accounts} onChange={(p) => line(i, p)} />
                {l.kind === 'stock' && l.product_uom_id && avail[i] && <Text block size={200} className={s.muted} style={{ marginTop: 6 }}>In stock now: {avail[i]}</Text>}
              </div>
              <TextField label="Quantity" required type="number" min={0} step="any" value={l.qty} onChange={(x) => line(i, { qty: x })} />
              {l.kind === 'service'
                ? <TextField label="Estimated price per unit" type="number" min={0} step="any" value={l.est_unit_cost} onChange={(x) => line(i, { est_unit_cost: x })} />
                : <TextField label="Note (optional)" maxLength={255} value={l.note} onChange={(x) => line(i, { note: x })} />}
            </div>
          </div>
        ))}
        <Button icon={<AddRegular />} style={{ marginTop: 10 }} onClick={() => setLines([...lines, blank()])}>Add line</Button>
      </Panel>
      <SaveBar value={[v, lines]}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Sending…' : 'Send the request'}</Button>
      </SaveBar>
    </div>
  )
}
