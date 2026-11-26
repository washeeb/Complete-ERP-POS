import { Button, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type CoForm, type CoLine, type CoValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { UnitPicker } from '../form/UnitPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { SaveBar } from '../form/SaveBar'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px 16px', marginBottom: '14px' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  about: { display: 'block', marginBottom: '12px', color: tokens.colorNeutralForeground3 },
  line: { display: 'grid', gridTemplateColumns: '28px minmax(0, 3fr) minmax(0, 1fr) minmax(0, 1fr) 36px', gap: '8px 10px', alignItems: 'end', padding: '10px 0',
    borderBottom: `1px solid ${tokens.colorNeutralStroke2}`, '@media (max-width: 760px)': { gridTemplateColumns: '1fr' } },
  num: { paddingBottom: '8px', fontWeight: tokens.fontWeightSemibold },
  avail: { paddingBottom: '8px', color: tokens.colorNeutralForeground3 },
  note: { display: 'block', marginTop: '6px' },
  one: { display: 'grid', gap: '10px' },
})

type Line = CoLine & { pid?: string }
const blank = (): Line => ({ product_uom_id: '', qty: '', label: null })

/**
 * A quotation (part 15): the customer (add a missing one here), the store it is supplied from, how long the quote
 * holds, the customer's reference and a note; and the lines — a product in a unit and how many, with what the store
 * holds. Prices come from the customer's price list (with quantity tiers) when it is saved. A rule the save breaks (an
 * inactive customer, a unit with no price…) is said at the top, and nothing is saved.
 */
export function CustomerOrderFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<CoForm | null>(null)
  const [v, setV] = useState<(Omit<CoValues, 'lines'> & { lines: Line[] }) | null>(null)
  const [avail, setAvail] = useState<Record<number, string | null>>({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [adding, setAdding] = useState(false)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    api.customerOrderForm(id ?? undefined).then((x) => {
      setForm(x)
      setV({ ...x.values, lines: x.values.lines.length ? x.values.lines : [blank()] })
      onTitle(x.number ? `Edit ${x.number}` : 'New quotation')
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  // What the chosen store holds of each line's product, when the store or a product changes.
  const keys = v ? v.lines.map((l) => l.product_uom_id).join('|') + `|${v.warehouse_id}` : ''
  useEffect(() => {
    if (!v || !v.warehouse_id) return
    v.lines.forEach((l, i) => {
      if (!l.product_uom_id) return
      api.coAvailable(l.product_uom_id, v.warehouse_id).then((x) => setAvail((o) => ({ ...o, [i]: x.available }))).catch(() => undefined)
    })
  }, [keys]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!form || !v) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const set = <K extends keyof CoValues>(k: K) => (x: CoValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const line = (i: number, patch: Partial<Line>) => setV((o) => (o ? { ...o, lines: o.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)) } : o))
  const dupe = (i: number) => !!v.lines[i].pid && v.lines.some((l, j) => j !== i && l.pid === v.lines[i].pid)
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/customer-orders/${id}` : '/office/customer-orders'))
  const ready = !!v.customer_id && !!v.warehouse_id && v.lines.length > 0 && v.lines.every((l) => !!l.product_uom_id && Number(l.qty) > 0)
  const save = () => {
    setBusy(true)
    setError('')
    api.saveCustomerOrder(id, { customer_id: v.customer_id, warehouse_id: v.warehouse_id, valid_until: v.valid_until, customer_ref: v.customer_ref, note: v.note,
      lines: v.lines.map((l) => ({ product_uom_id: l.product_uom_id, qty: l.qty })) })
      .then((r) => navigate(`/office/customer-orders/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) })
      .finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'The quotation' : 'Quotes & orders'}</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>{id ? `Edit ${form.number}` : 'New quotation'}</h1>
          <Text className={s.muted}>A quotation can change until the customer accepts it and it is turned into an order.</Text>
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      <Panel title="Quotation">
        <div className={f.grid}>
          <SearchPicker label="Customer" required value={v.customer_id} text={v.customer_label} search={api.coCustomers} placeholder="Name, code or phone"
            onChange={(c) => setV({ ...v, customer_id: c?.id ?? '', customer_label: c?.label ?? null })} onAdd={form.can_add_customer ? () => setAdding(true) : undefined} />
          <Choose label="Supply from" required options={form.warehouses} value={v.warehouse_id} onChange={set('warehouse_id')} />
          <TextField label="Quote valid until" type="date" value={v.valid_until} onChange={set('valid_until')} />
          <TextField label="Customer's PO / reference" maxLength={60} value={v.customer_ref} onChange={set('customer_ref')} />
          <LongText label="Note" className={f.spanAll} maxLength={5000} value={v.note} onChange={set('note')} />
        </div>
      </Panel>

      <Panel title="Lines" about="Prices come from the customer's price list (with quantity tiers) when you save.">
        {v.lines.map((l, i) => (
          <div key={i} className={s.line} role="group" aria-label={`Line ${i + 1}`}>
            <Text className={s.num}>{i + 1}.</Text>
            <div>
              <UnitPicker value={l.product_uom_id} label={l.label} search={api.coUnits} onChange={(u) => line(i, { product_uom_id: u?.id ?? '', label: u?.label ?? null, pid: u?.product_id })} />
              {dupe(i) && <Text size={200} className={s.note} style={{ color: 'var(--zx-warn)' }}>This product is on another line too.</Text>}
            </div>
            <TextField label="Quantity" required type="number" min={0} step="any" value={l.qty} onChange={(x) => line(i, { qty: x })} />
            <Text size={200} className={s.avail}>{l.product_uom_id && avail[i] ? `Available: ${avail[i]}` : ''}</Text>
            <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${i + 1}`} disabled={v.lines.length === 1}
              onClick={() => { setV({ ...v, lines: v.lines.filter((_, j) => j !== i) }); setAvail({}) }} />
          </div>
        ))}
        <Button icon={<AddRegular />} style={{ marginTop: 10 }} onClick={() => setV({ ...v, lines: [...v.lines, blank()] })}>Add line</Button>
      </Panel>

      <SaveBar value={v}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Save quotation'}</Button>
      </SaveBar>

      {adding && <AddCustomer onClose={() => setAdding(false)} onAdded={(c) => { setV({ ...v, customer_id: c.id, customer_label: c.label }); setAdding(false) }} />}
    </div>
  )
}

function Panel({ title, about, children }: { title: string; about?: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{about && <Text className={s.about}>{about}</Text>}{children}</section>
}

/** A missing customer, added from the quotation (one already there with that phone is chosen instead). */
function AddCustomer({ onClose, onAdded }: { onClose: () => void; onAdded: (c: { id: string; label: string }) => void }) {
  const s = useStyles()
  const [v, setV] = useState({ name: '', phone: '', email: '' })
  return (
    <Ask title="Add a customer" go="Add" ready={!!v.name.trim()} onClose={onClose}
      run={() => api.coAddCustomer({ name: v.name.trim(), phone: v.phone.trim() || undefined, email: v.email.trim() || undefined }).then(onAdded)}>
      <div className={s.one}>
        <TextField label="Name" required maxLength={255} value={v.name} onChange={(x) => setV({ ...v, name: x })} />
        <TextField label="Phone" maxLength={30} value={v.phone} onChange={(x) => setV({ ...v, phone: x })} />
        <TextField label="Email" maxLength={255} value={v.email} onChange={(x) => setV({ ...v, email: x })} />
      </div>
    </Ask>
  )
}
