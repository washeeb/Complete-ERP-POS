import { Button, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular, WindowArrowUpRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, ApiError, type SupplierForm, type SupplierProductLine, type SupplierValues } from '../api'
import { ConfirmItsYou } from '../form/ConfirmItsYou'
import { Choose, LongText, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { UnitPicker } from '../form/UnitPicker'
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
  line: { display: 'grid', gridTemplateColumns: 'minmax(0, 3fr) repeat(4, minmax(0, 1fr)) auto auto', gap: '8px 10px', alignItems: 'end',
    padding: '10px 0', borderBottom: `1px solid ${tokens.colorNeutralStroke2}`,
    '@media (max-width: 900px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', '& > :first-child': { gridColumn: '1 / -1' } } },
  foot: { position: 'sticky', bottom: 0, zIndex: 2, display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0',
    backgroundColor: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

const blank = (): SupplierProductLine => ({ product_uom_id: '', label: null, supplier_sku: '', last_cost: '', min_order_qty: '0', order_multiple: '0', is_preferred: false })

/**
 * Add or edit a supplier (release 5): the current form — who they are, how they are paid, and the products they supply
 * (their pack, their code, your last cost, the least they accept and the steps they sell in). New or changed bank or
 * MoMo details ask the person to confirm it is them, and then wait for a second person to verify them. A rule the save
 * breaks is said at the top, and nothing is saved.
 */
export function SupplierFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<SupplierForm | null>(null)
  const [v, setV] = useState<SupplierValues | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [confirmUrl, setConfirmUrl] = useState<string | null>(null)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    api.supplierForm(id ?? undefined).then((x) => { setForm(x); setV(x.values); onTitle(x.name ? `Edit ${x.name}` : 'New supplier') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (!form || !v) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const c = form.choices
  const set = <K extends keyof SupplierValues>(k: K) => (x: SupplierValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const str = (k: keyof SupplierValues) => ({ value: v[k] as string, onChange: set(k) as (x: string) => void })
  const line = (i: number, patch: Partial<SupplierProductLine>) => set('products')(v.products.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const classic = classicPathFor(id ? `/admin/suppliers/${id}/edit` : '/admin/suppliers/create')
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/suppliers/${id}` : '/office/suppliers'))
  const save = () => {
    setBusy(true)
    setError('')
    api.saveSupplier(id, v).then((r) => navigate(`/office/suppliers/${r.id}`, true))
      .catch((e: unknown) => {
        if (e instanceof ApiError && e.code === 'reconfirm' && e.url) { setConfirmUrl(e.url); return }
        setError(e instanceof Error ? e.message : String(e))
        top.current?.scrollIntoView({ behavior: 'smooth' })
      })
      .finally(() => setBusy(false))
  }
  const ready = !!v.name.trim() && !!v.supply_kind && (!form.sections.industry || !!v.industry_id) && v.products.every((l) => !!l.product_uom_id)

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'The supplier' : 'Suppliers'}</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>{id ? `Edit ${form.name}` : 'New supplier'}</h1>
          <Text className={s.muted}>{form.code ? `Code ${form.code} · given by the system.` : 'The code is given when you save.'}</Text>
        </div>
        <Link href={classic} onClick={(e) => { e.preventDefault(); navigate(classic) }}><WindowArrowUpRegular /> Classic form</Link>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      <Panel title="Supplier">
        <div className={f.grid}>
          <TextField label="Name" required className={f.span2} maxLength={255} {...str('name')} />
          {form.sections.industry && <Choose label="Industry" required options={c.industries} {...str('industry_id')}
            hint="Only people in this industry see it. Shared ones are seen by every industry." />}
          <TextField label="Contact person" maxLength={255} {...str('contact_name')} />
          <TextField label="Phone" maxLength={30} {...str('phone')} />
          <TextField label="Email" maxLength={255} {...str('email')} />
          <TextField label="TIN / VAT number" maxLength={40} {...str('tax_no')} />
          <TextField label="Payment terms (days)" type="number" min={0} max={365} {...str('payment_terms_days')} hint="0 = cash." />
          <TextField label="Delivery lead time (days)" type="number" min={0} max={365} {...str('lead_time_days')} />
          <TextField label="Takes back expiring stock up to (days before expiry)" type="number" min={0} max={730} {...str('return_days_before_expiry')}
            hint="Leave empty if they take nothing back. For example 90: stock can go back until 90 days before it expires." />
          <LongText label="Address" className={f.span2} maxLength={2000} {...str('address')} />
          <LongText label="Notes" className={f.spanAll} maxLength={5000} {...str('notes')} />
        </div>
      </Panel>

      <Panel title="How they are paid" about="A new or changed account or MoMo number asks you to confirm it is you, and is checked by a second person (Verify bank details) before any transfer or MoMo payment goes to it.">
        <div className={f.grid}>
          <Choose label="They supply" required options={c.supply_kinds} {...str('supply_kind')} hint="Sets the withholding tax on payments to them." />
          <TextField label="Bank" maxLength={80} {...str('bank_name')} />
          <TextField label="Branch" maxLength={80} {...str('bank_branch')} />
          <TextField label="Account name" maxLength={120} {...str('bank_account_name')} />
          <TextField label="Account number" maxLength={40} {...str('bank_account_no')} />
          <TextField label="MoMo merchant / number" maxLength={20} {...str('momo_number')} />
        </div>
        {form.bank_checked && <Text block size={200} className={s.muted} style={{ marginTop: 8 }}>Checked: {form.bank_checked}. Changing them sends them back for checking.</Text>}
      </Panel>

      <Panel title="Products supplied" about="The pack they sell (e.g. a box of 100 or a carton of 24), their code, your last cost before tax, the least they accept and the steps they sell in (e.g. 5 boxes at a time; 0 = any). Purchase orders fill in from here.">
        {v.products.map((l, i) => (
          <div key={i} className={s.line} role="group" aria-label={`Product ${i + 1}`}>
            <UnitPicker value={l.product_uom_id} label={l.label} search={api.supplierUnits}
              onChange={(u) => line(i, { product_uom_id: u?.id ?? '', label: u?.label ?? null })} />
            <TextField label="Their code" maxLength={60} value={l.supplier_sku} onChange={(x) => line(i, { supplier_sku: x })} />
            <TextField label="Cost per pack" type="number" min={0} step="any" prefix={form.currency} value={l.last_cost} onChange={(x) => line(i, { last_cost: x })} />
            <TextField label="Minimum order" type="number" min={0} step="any" value={l.min_order_qty} onChange={(x) => line(i, { min_order_qty: x })} />
            <TextField label="In multiples of" type="number" min={0} step="any" value={l.order_multiple} onChange={(x) => line(i, { order_multiple: x })} />
            <Toggle label="Preferred" value={l.is_preferred} onChange={(x) => line(i, { is_preferred: x })} />
            <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove product ${i + 1}`} onClick={() => set('products')(v.products.filter((_, j) => j !== i))} />
          </div>
        ))}
        <Button icon={<AddRegular />} style={{ marginTop: 10 }} onClick={() => set('products')([...v.products, blank()])}>Add product</Button>
      </Panel>

      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Save'}</Button>
      </div>

      {confirmUrl && (
        <ConfirmItsYou url={confirmUrl} why="New or changed bank or MoMo details are protected." go="Save now"
          onCancel={() => setConfirmUrl(null)} onGo={() => { setConfirmUrl(null); save() }} />
      )}
    </div>
  )
}

function Panel({ title, about, children }: { title: string; about?: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{about && <Text className={s.about}>{about}</Text>}{children}</section>
}
