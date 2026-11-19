import { Button, Dropdown, Field, Link, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular, WindowArrowUpRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type SbBillable, type SbExpenseLine, type SbForm, type SbValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { classicPathFor } from '../paths'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px 16px', marginBottom: '14px' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  about: { display: 'block', marginBottom: '12px', color: tokens.colorNeutralForeground3 },
  line: { display: 'grid', gridTemplateColumns: 'minmax(0, 3fr) repeat(2, minmax(0, 1fr)) auto', gap: '8px 10px', alignItems: 'end', padding: '8px 0',
    borderBottom: `1px solid ${tokens.colorNeutralStroke2}`, '@media (max-width: 760px)': { gridTemplateColumns: '1fr' } },
  charge: { display: 'grid', gridTemplateColumns: 'repeat(4, minmax(0, 1fr)) auto', gap: '8px 10px', alignItems: 'end', padding: '8px 0',
    borderBottom: `1px solid ${tokens.colorNeutralStroke2}`, '@media (max-width: 900px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' } },
  one: { display: 'grid', gap: '10px' },
  foot: { position: 'sticky', bottom: 0, zIndex: 2, display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0',
    backgroundColor: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

const blankCharge = (): SbExpenseLine => ({ account_id: '', description: '', amount: '', tax_group_id: '', department_id: '', project_id: '', maintenance_order_id: '' })

/**
 * Enter a supplier bill (release 6): the supplier's invoice, then what it is for — goods received (bill only what came;
 * the order price is filled in, change it to what they charged), services confirmed on orders, and other charges
 * straight to an account (rent, electricity…). Filled in from a scanned invoice, or from an invoice the supplier sent,
 * when opened that way. Prices that differ from the order send the bill for approval.
 */
export function SupplierBillFormPage({ onTitle }: { onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<SbForm | null>(null)
  const [v, setV] = useState<SbValues | null>(null)
  const [billable, setBillable] = useState<SbBillable | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [adding, setAdding] = useState(false)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const q = new URLSearchParams(location.search)
    onTitle('Enter a supplier bill')
    api.supplierBillForm(q.get('scan'), q.get('invoice')).then((x) => { setForm(x); setV(x.values) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [onTitle])
  const supplier = v?.supplier_id ?? ''
  useEffect(() => {
    if (!supplier) { setBillable(null); return }
    api.supplierBillable(supplier).then(setBillable).catch(() => setBillable(null))
  }, [supplier])

  if (!form || !v) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const c = form.choices
  const set = <K extends keyof SbValues>(k: K) => (x: SbValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const stock = billable?.stock ?? []
  const orders = billable?.orders ?? []
  const addFromGrns = (ids: string[]) => {
    const have = new Set(v.stock_lines.map((l) => l.goods_receipt_line_id))
    set('stock_lines')([...v.stock_lines, ...stock.filter((l) => l.grn && ids.includes(l.grn) && !have.has(l.id)).map((l) => ({ goods_receipt_line_id: l.id, qty: l.qty, unit_cost: l.cost }))])
  }
  const lines = v.stock_lines.length + v.order_lines.length + v.expense_lines.length
  const ready = !!v.supplier_id && !!v.supplier_invoice_no.trim() && !!v.branch_id && !!v.bill_date && lines > 0
    && v.stock_lines.every((l) => l.goods_receipt_line_id && Number(l.qty) > 0 && l.unit_cost !== '')
    && v.order_lines.every((l) => l.purchase_order_line_id && Number(l.qty) > 0 && l.unit_cost !== '')
    && v.expense_lines.every((l) => l.account_id && Number(l.amount) > 0)
  const back = () => (history.length > 1 ? history.back() : navigate('/office/supplier-bills'))
  const classic = classicPathFor(`/admin/supplier-bills/create${location.search}`)
  const save = () => {
    setBusy(true)
    setError('')
    api.saveSupplierBill(v, form.scan, form.invoice).then((r) => navigate(`/office/supplier-bills/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) })
      .finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Supplier bills</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>Enter a supplier bill</h1>
          <Text className={s.muted}>Prices are before tax. A bill whose prices differ from the order waits for someone else to approve it.</Text>
        </div>
        <Link href={classic} onClick={(e) => { e.preventDefault(); navigate(classic) }}><WindowArrowUpRegular /> Classic form</Link>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {form.notice && (
        <MessageBar intent={form.checks.length ? 'warning' : 'info'} style={{ marginBottom: 12 }}><MessageBarBody>
          {form.notice}
          {form.checks.map((x) => <Text block key={x}>• {x}</Text>)}
        </MessageBarBody></MessageBar>
      )}

      <Panel title="The bill">
        <div className={f.grid}>
          <Choose label="Supplier" required options={c.suppliers} value={v.supplier_id} onAdd={form.can_add_supplier ? () => setAdding(true) : undefined}
            onChange={(x) => setV({ ...v, supplier_id: x, stock_lines: [], order_lines: [] })} />
          <TextField label="Supplier's invoice number" required maxLength={60} value={v.supplier_invoice_no} onChange={set('supplier_invoice_no')} />
          <Choose label="Branch" required options={c.branches} value={v.branch_id} onChange={set('branch_id')} />
          <TextField label="Invoice date" required type="date" value={v.bill_date} onChange={set('bill_date')} />
          <TextField label="Due" type="date" value={v.due_on} onChange={set('due_on')} hint="Blank = the supplier's payment terms." />
          <LongText label="Note" className={f.spanAll} maxLength={2000} value={v.note} onChange={set('note')} />
        </div>
      </Panel>

      <Panel title="Goods received" about="Bill only what was received. Prices are before tax; the order price is filled in — change it to what the supplier charged.">
        {!v.supplier_id ? <Text className={s.muted}>Choose the supplier first.</Text> : (
          <>
            {billable && billable.grns.length > 0 && (
              <Field label="Add everything still to bill from" style={{ marginBottom: 8 }}>
                <Dropdown multiselect placeholder="Goods received notes" selectedOptions={[]} onOptionSelect={(_, d) => d.optionValue && addFromGrns([d.optionValue])}>
                  {billable.grns.map((g) => <Option key={g.id} value={g.id}>{g.label}</Option>)}
                </Dropdown>
              </Field>
            )}
            {v.stock_lines.map((l, i) => (
              <div key={i} className={s.line} role="group" aria-label={`Received line ${i + 1}`}>
                <Choose label="Received" required options={stock} value={l.goods_receipt_line_id} onChange={(x) => {
                  const b = stock.find((y) => y.id === x)
                  set('stock_lines')(v.stock_lines.map((y, j) => (j === i ? { goods_receipt_line_id: x, qty: b?.qty ?? y.qty, unit_cost: b?.cost ?? y.unit_cost } : y)))
                }} />
                <TextField label="Quantity" required type="number" min={0} step="any" value={l.qty}
                  onChange={(x) => set('stock_lines')(v.stock_lines.map((y, j) => (j === i ? { ...y, qty: x } : y)))} />
                <TextField label="Price per unit (before tax)" required type="number" min={0} step="any" prefix={form.currency} value={l.unit_cost}
                  onChange={(x) => set('stock_lines')(v.stock_lines.map((y, j) => (j === i ? { ...y, unit_cost: x } : y)))} />
                <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove received line ${i + 1}`} onClick={() => set('stock_lines')(v.stock_lines.filter((_, j) => j !== i))} />
              </div>
            ))}
            {stock.length === 0 && v.stock_lines.length === 0 && <Text block className={s.muted}>Nothing received from this supplier is waiting to be billed.</Text>}
            {stock.length > 0 && <Button icon={<AddRegular />} style={{ marginTop: 8 }} onClick={() => set('stock_lines')([...v.stock_lines, { goods_receipt_line_id: '', qty: '', unit_cost: '' }])}>Add a received line</Button>}
          </>
        )}
      </Panel>

      {(orders.length > 0 || v.order_lines.length > 0) && (
        <Panel title="Services and non-stock items on orders" about="Confirmed as delivered on a purchase order and not yet billed. The order price is filled in.">
          {v.order_lines.map((l, i) => (
            <div key={i} className={s.line} role="group" aria-label={`Order line ${i + 1}`}>
              <Choose label="On order" required options={orders} value={l.purchase_order_line_id} onChange={(x) => {
                const b = orders.find((y) => y.id === x)
                set('order_lines')(v.order_lines.map((y, j) => (j === i ? { purchase_order_line_id: x, qty: b?.qty ?? y.qty, unit_cost: b?.cost ?? y.unit_cost } : y)))
              }} />
              <TextField label="Quantity" required type="number" min={0} step="any" value={l.qty}
                onChange={(x) => set('order_lines')(v.order_lines.map((y, j) => (j === i ? { ...y, qty: x } : y)))} />
              <TextField label="Price per unit (before tax)" required type="number" min={0} step="any" prefix={form.currency} value={l.unit_cost}
                onChange={(x) => set('order_lines')(v.order_lines.map((y, j) => (j === i ? { ...y, unit_cost: x } : y)))} />
              <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove order line ${i + 1}`} onClick={() => set('order_lines')(v.order_lines.filter((_, j) => j !== i))} />
            </div>
          ))}
          <Button icon={<AddRegular />} style={{ marginTop: 8 }} onClick={() => set('order_lines')([...v.order_lines, { purchase_order_line_id: '', qty: '', unit_cost: '' }])}>Add an order line</Button>
        </Panel>
      )}

      <Panel title="Other charges" about="Rent, electricity, transport… charged straight to an expense account.">
        {v.expense_lines.map((l, i) => {
          const patch = (p: Partial<SbExpenseLine>) => set('expense_lines')(v.expense_lines.map((y, j) => (j === i ? { ...y, ...p } : y)))
          return (
            <div key={i} className={s.charge} role="group" aria-label={`Charge ${i + 1}`}>
              <Choose label="Account" required options={c.accounts} value={l.account_id} onChange={(x) => patch({ account_id: x })} />
              <TextField label="Description" maxLength={255} value={l.description} onChange={(x) => patch({ description: x })} />
              <TextField label="Amount (before tax)" required type="number" min={0} step="any" prefix={form.currency} value={l.amount} onChange={(x) => patch({ amount: x })} />
              <Choose label="Tax" options={c.tax_groups} value={l.tax_group_id} onChange={(x) => patch({ tax_group_id: x })} placeholder="No tax" />
              <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove charge ${i + 1}`} onClick={() => set('expense_lines')(v.expense_lines.filter((_, j) => j !== i))} />
              <Choose label="Department (cost centre)" options={c.departments} value={l.department_id} onChange={(x) => patch({ department_id: x })} />
              {c.projects && <Choose label="For project" options={c.projects} value={l.project_id} onChange={(x) => patch({ project_id: x })} />}
              {c.work_orders && <Choose label="For work order" options={c.work_orders} value={l.maintenance_order_id} onChange={(x) => patch({ maintenance_order_id: x })} />}
            </div>
          )
        })}
        <Button icon={<AddRegular />} style={{ marginTop: 8 }} onClick={() => set('expense_lines')([...v.expense_lines, blankCharge()])}>Add a charge</Button>
      </Panel>

      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Create the bill'}</Button>
      </div>

      {adding && <AddSupplier onClose={() => setAdding(false)} onAdded={(sup) => {
        setForm({ ...form, choices: { ...c, suppliers: c.suppliers.some((x) => x.id === sup.id) ? c.suppliers : [...c.suppliers, { id: sup.id, label: sup.label }] } })
        setV({ ...v, supplier_id: sup.id, stock_lines: [], order_lines: [] })
        setAdding(false)
      }} />}
    </div>
  )
}

function Panel({ title, about, children }: { title: string; about?: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{about && <Text className={s.about}>{about}</Text>}{children}</section>
}

function AddSupplier({ onClose, onAdded }: { onClose: () => void; onAdded: (s: { id: string; label: string }) => void }) {
  const s = useStyles()
  const [v, setV] = useState({ code: '', name: '', phone: '', email: '' })
  return (
    <Ask title="Add a supplier" go="Add" ready={!!v.name.trim()} onClose={onClose}
      run={() => api.sbAddSupplier({ name: v.name.trim(), code: v.code.trim() || undefined, phone: v.phone.trim() || undefined, email: v.email.trim() || undefined }).then(onAdded)}>
      <div className={s.one}>
        <TextField label="Name" required maxLength={255} value={v.name} onChange={(x) => setV({ ...v, name: x })} />
        <TextField label="Code" maxLength={20} placeholder="Given by the system" value={v.code} onChange={(x) => setV({ ...v, code: x })} />
        <TextField label="Phone" maxLength={30} value={v.phone} onChange={(x) => setV({ ...v, phone: x })} />
        <TextField label="Email" maxLength={255} value={v.email} onChange={(x) => setV({ ...v, email: x })} />
      </div>
    </Ask>
  )
}
