import { Button, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, SaveRegular, WindowArrowUpRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type CustomerForm, type CustomerValues } from '../api'
import { Choose, LongText, Tags, TextField, Toggle, useFieldStyles } from '../form/Fields'
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
  foot: { position: 'sticky', bottom: 0, zIndex: 2, display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0',
    backgroundColor: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

/**
 * Add or edit a customer (release 4): the current form — who they are, marketing consent (only what they agreed to,
 * kept with who recorded it, when and how) and, when adding at head office, their credit. Credit of an existing
 * customer is changed with "Credit" on their page. A rule the save breaks (a phone number already used…) is said at
 * the top, and nothing is saved.
 */
export function CustomerFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<CustomerForm | null>(null)
  const [v, setV] = useState<CustomerValues | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    api.customerForm(id ?? undefined).then((x) => { setForm(x); setV(x.values); onTitle(x.name ? `Edit ${x.name}` : 'New customer') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (!form || !v) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const c = form.choices
  const set = <K extends keyof CustomerValues>(k: K) => (x: CustomerValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const str = (k: keyof CustomerValues) => ({ value: v[k] as string, onChange: set(k) as (x: string) => void })
  const classic = classicPathFor(id ? `/admin/customers/${id}/edit` : '/admin/customers/create')
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/customers/${id}` : '/office/customers'))
  const save = () => {
    setBusy(true)
    setError('')
    api.saveCustomer(id, v).then((r) => navigate(`/office/customers/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) })
      .finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'The customer' : 'Customers'}</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>{id ? `Edit ${form.name}` : 'New customer'}</h1>
          <Text className={s.muted}>{form.code ? `Code ${form.code} · given by the system.` : 'The code is given when you save.'}</Text>
        </div>
        <Link href={classic} onClick={(e) => { e.preventDefault(); navigate(classic) }}><WindowArrowUpRegular /> Classic form</Link>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      <Panel title="Customer">
        <div className={f.grid}>
          <Choose label="Type" required options={c.types} value={v.type} onChange={set('type')} />
          <TextField label="Name" required className={f.span2} maxLength={255} {...str('name')} />
          {form.sections.industry && <Choose label="Industry" required options={c.industries} {...str('industry_id')} hint="Only people in this industry see this customer." />}
          <TextField label="Phone" maxLength={30} {...str('phone')} hint="Ghana numbers are stored as +233…" />
          <TextField label="Email" maxLength={255} {...str('email')} />
          <TextField label="Contact person" maxLength={255} {...str('contact_name')} />
          <TextField label="TIN / Ghana Card" maxLength={40} {...str('tax_no')} />
          <Choose label="Price list" options={c.price_lists} placeholder="Company default" {...str('price_list_id')} />
          <Choose label="Home branch" options={c.branches} {...str('home_branch_id')} />
          <LongText label="Address" className={f.span2} maxLength={2000} {...str('address')} />
          <Tags label="Tags" value={v.tags} onChange={set('tags')} placeholder="e.g. wholesale, VIP, then Enter" />
          <LongText label="Notes" className={f.spanAll} maxLength={5000} {...str('notes')} />
        </div>
      </Panel>

      <Panel title="Marketing consent" about="Only tick what the customer agreed to. Every change is kept with who recorded it, when and how.">
        <div className={f.grid}>
          {c.consent_channels.map((ch) => (
            <Toggle key={ch.id} label={ch.label} value={!!v.consents[ch.id]} onChange={(x) => set('consents')({ ...v.consents, [ch.id]: x })} />
          ))}
          <Choose label="How they agreed" required options={c.consent_sources} {...str('consent_source')} />
        </div>
      </Panel>

      {form.sections.credit && (
        <Panel title="Credit (head office)" about="Leave the limit blank for a cash customer. Later changes are made with Credit on the customer's page.">
          <div className={f.grid}>
            <TextField label="Credit limit" type="number" min={0} step="any" {...str('credit_limit')} hint="Leave blank for a cash customer (no credit)." />
            <TextField label="Payment terms (days)" type="number" min={0} max={365} {...str('payment_terms_days')} />
          </div>
        </Panel>
      )}

      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !v.name.trim() || !v.type || (form.sections.industry && !v.industry_id) || !v.consent_source} onClick={save}>{busy ? 'Saving…' : 'Save'}</Button>
      </div>
    </div>
  )
}

function Panel({ title, about, children }: { title: string; about?: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{about && <Text className={s.about}>{about}</Text>}{children}</section>
}
