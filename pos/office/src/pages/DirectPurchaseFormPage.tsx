import { Button, Field, Link, makeStyles, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, AttachRegular, CameraRegular, DeleteRegular, DismissRegular, SaveRegular, WindowArrowUpRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type DpForm, type DpLine, type DpValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { UnitPicker } from '../form/UnitPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { classicPathFor } from '../paths'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  headActions: { display: 'flex', gap: '12px', alignItems: 'center', flexWrap: 'wrap' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px 16px', marginBottom: '14px' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  about: { display: 'block', marginBottom: '12px', color: tokens.colorNeutralForeground3 },
  line: { padding: '12px 0', borderBottom: `1px solid ${tokens.colorNeutralStroke2}` },
  lineHead: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px' },
  grow: { flex: '1 1 auto' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(6, minmax(0, 1fr))', gap: '8px 10px', alignItems: 'start',
    '@media (max-width: 1100px)': { gridTemplateColumns: 'repeat(3, minmax(0, 1fr))' }, '@media (max-width: 640px)': { gridTemplateColumns: '1fr' } },
  wide: { gridColumn: 'span 3', '@media (max-width: 640px)': { gridColumn: 'auto' } },
  pick: { display: 'flex', gap: '4px', alignItems: 'flex-end', '& > :first-child': { flex: '1 1 auto', minWidth: 0 } },
  check: { display: 'block', marginTop: '6px', color: tokens.colorNeutralForeground3 },
  pages: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center', gridColumn: '1 / -1' },
  one: { display: 'grid', gap: '10px' },
  foot: { position: 'sticky', bottom: 0, zIndex: 2, display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0',
    backgroundColor: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
})

const SETTLE_ABOUT: Record<string, string> = {
  paid: 'The payment is recorded with it.', credit: 'Shows in what you owe the supplier.',
  consignment: "The goods stay the supplier's. Each sale owes them the cost on this invoice; pay from Consignment to pay.",
}
const blankLine = (): DpLine => ({ product_uom_id: '', label: null, read_as: null, batched: false, factor: 1, sell_units: [], qty: '', unit_price: '', tax_group_id: '',
  batch_no: '', expires_on: '', supplier_sku: '', sell_uom_id: '', new_price: '' })

/** Beside a line: the new price's margin, and a batch that expires soon (as the current form). */
function lineCheck(l: DpLine, months: number): string | null {
  const out: string[] = []
  const sell = l.sell_units.find((u) => u.id === l.sell_uom_id)
  if (l.product_uom_id && Number(l.new_price) > 0 && l.unit_price !== '' && !Number.isNaN(Number(l.unit_price))) {
    const cost = (Number(l.unit_price) * (sell?.factor ?? l.factor)) / (l.factor || 1)
    out.push(`Margin ${Math.round(((Number(l.new_price) - cost) * 100) / Number(l.new_price))}%, × ${(Number(l.new_price) / Math.max(cost, 0.0001)).toFixed(2)}`)
  }
  if (months > 0 && l.expires_on) {
    const soon = new Date()
    soon.setMonth(soon.getMonth() + months)
    if (l.expires_on < soon.toISOString().slice(0, 10)) out.push(`Short-dated: under ${months} months`)
  }
  return out.length ? out.join(' · ') : null
}

/**
 * Enter a supplier invoice (release 5): the invoice, how it is paid, and its products with batch, expiry and (if it
 * changes) the new selling price. Scan the invoice fills the form with the AI; check every line against the paper.
 * A product not in the catalogue is added on its line (+). A rule the save breaks (a duplicate invoice number, a batch
 * missing on a medicine, an expired batch…) is said at the top, and nothing is saved.
 */
export function DirectPurchaseFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<DpForm | null>(null)
  const [v, setV] = useState<DpValues | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [scanning, setScanning] = useState(false)
  const [adding, setAdding] = useState<number | null>(null)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    api.directPurchaseForm(id ?? undefined).then((x) => {
      setForm(x)
      setV({ ...x.values, lines: x.values.lines.length ? x.values.lines : [blankLine()] })
      onTitle(x.number ? `Edit ${x.number}` : 'Enter a supplier invoice')
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (!form || !v) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const c = form.choices
  const set = <K extends keyof DpValues>(k: K) => (x: DpValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const str = (k: keyof DpValues) => ({ value: v[k] as string, onChange: set(k) as (x: string) => void })
  const line = (i: number, patch: Partial<DpLine>) => setV((o) => (o ? { ...o, lines: o.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)) } : o))
  const fail = (e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) }
  const pickUnit = (i: number, unitId: string | null) => {
    if (!unitId) { line(i, { product_uom_id: '', label: null, sell_units: [], sell_uom_id: '' }); return }
    api.dpUnit(unitId).then((u) => line(i, { product_uom_id: u.product_uom_id, label: u.label, batched: u.batched, factor: u.factor, sell_units: u.sell_units, sell_uom_id: '', read_as: null }))
      .catch(fail)
  }
  const attach = (files: File[]) => {
    if (!files.length) return
    setUploading(true)
    api.dpFiles<{ paths: string[] }>('upload', files).then((r) => set('attachments')([...v.attachments, ...r.paths])).catch(fail).finally(() => setUploading(false))
  }
  const needsRef = v.settlement === 'paid' && ['bank', 'momo', 'cheque'].includes(v.method)
  const ready = !!v.supplier_id && !!v.invoice_no.trim() && !!v.invoice_date && !!v.warehouse_id && !!v.settlement
    && (v.settlement !== 'paid' || (!!v.method && !!v.account_id && (!needsRef || !!v.reference.trim())))
    && v.lines.length > 0 && v.lines.every((l) => !!l.product_uom_id && Number(l.qty) > 0 && l.unit_price !== '' && (!l.batched || (!!l.batch_no.trim() && !!l.expires_on)))
  const classic = classicPathFor(id ? `/admin/direct-purchases/${id}/edit` : '/admin/direct-purchases/create')
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/direct-purchases/${id}` : '/office/direct-purchases'))
  const save = () => {
    setBusy(true)
    setError('')
    api.saveDirectPurchase(id, v).then((r) => navigate(`/office/direct-purchases/${r.id}`, true)).catch(fail).finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'The purchase' : 'Direct purchases'}</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>{id ? `${form.correcting ? 'Correct' : 'Edit'} ${form.number}` : 'Enter a supplier invoice'}</h1>
          <Text className={s.muted}>{form.correcting
            ? 'Waiting for approval: correct what is wrong and save. It stays waiting for approval, and the correction is recorded in the audit log.'
            : 'Saved as a draft; post it (or send it for approval) from its page.'}</Text>
        </div>
        <div className={s.headActions}>
          {!id && <Button icon={<CameraRegular />} onClick={() => setScanning(true)}>Scan the invoice</Button>}
          <Link href={classic} onClick={(e) => { e.preventDefault(); navigate(classic) }}><WindowArrowUpRegular /> Classic form</Link>
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {notice && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{notice}</MessageBarBody></MessageBar>}

      <Panel title="The invoice">
        <div className={f.grid}>
          <Choose label="Supplier" required className={f.span2} options={c.suppliers} {...str('supplier_id')} />
          <TextField label="Invoice number" required maxLength={60} {...str('invoice_no')} />
          <TextField label="Invoice date" required type="date" {...str('invoice_date')} />
          <Choose label="Store" required options={c.warehouses} {...str('warehouse_id')} />
          <TextField label="Invoice total as printed" type="number" min={0} step="any" prefix={form.currency} {...str('printed_total')}
            hint="Checked against the lines before posting, to catch a mistyped line." />
          <Toggle label="Prices on the invoice include VAT and levies" className={f.span2} value={v.prices_include_tax} onChange={set('prices_include_tax')}
            hint="Most supplier invoices show prices before VAT: leave this off." />
          <div className={s.pages}>
            <Field label="Photo or PDF of the invoice (every page)">
              <input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" disabled={uploading}
                onChange={(e) => { attach(Array.from(e.target.files ?? [])); e.target.value = '' }} />
            </Field>
            {uploading && <Spinner size="tiny" label="Adding…" />}
            {v.attachments.map((p, i) => (
              <Button key={p} size="small" icon={<AttachRegular />} iconPosition="before" onClick={() => set('attachments')(v.attachments.filter((x) => x !== p))}>
                Page {i + 1} <DismissRegular style={{ marginLeft: 4 }} />
              </Button>
            ))}
          </div>
        </div>
      </Panel>

      <Panel title="Payment">
        <Field label="How is it paid?" required>
          <RadioGroup value={v.settlement} onChange={(_, d) => set('settlement')(d.value)}>
            {c.settlements.map((o) => <Radio key={o.id} value={o.id} label={<>{o.label} <Text size={200} className={s.muted}>— {SETTLE_ABOUT[o.id]}</Text></>} />)}
          </RadioGroup>
        </Field>
        <div className={f.grid} style={{ marginTop: 10 }}>
          {v.settlement === 'paid' && (
            <>
              <Choose label="Paid by" required options={c.methods} {...str('method')} />
              <Choose label="Paid from" required options={c.accounts} {...str('account_id')} />
              <TextField label="Reference" required={needsRef} maxLength={80} {...str('reference')} hint="MoMo, transfer or cheque reference." />
            </>
          )}
          {v.settlement === 'credit' && <TextField label="Due on" type="date" {...str('due_on')} hint="Empty: from the supplier's payment terms." />}
        </div>
      </Panel>

      <Panel title="Products">
        {v.lines.map((l, i) => {
          const check = lineCheck(l, form.short_dated_months)
          return (
            <div key={i} className={s.line} role="group" aria-label={`Line ${i + 1}`}>
              <div className={s.lineHead}>
                <Text weight="semibold">{i + 1}.</Text>
                {l.read_as && <Text size={200} style={{ color: 'var(--zx-warn)' }}>Read from the invoice as: {l.read_as}. Choose the product, or add it with +.</Text>}
                <span className={s.grow} />
                <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${i + 1}`} disabled={v.lines.length === 1}
                  onClick={() => set('lines')(v.lines.filter((_, j) => j !== i))} />
              </div>
              <div className={s.grid}>
                <div className={s.wide}>
                  <div className={s.pick}>
                    <UnitPicker value={l.product_uom_id} label={l.label} search={api.dpUnits} onChange={(u) => pickUnit(i, u?.id ?? null)} />
                    {form.can_add_product && <Button icon={<AddRegular />} aria-label={`Add a new product on line ${i + 1}`} onClick={() => setAdding(i)} />}
                  </div>
                </div>
                <TextField label="Qty" required type="number" min={0} step="any" value={l.qty} onChange={(x) => line(i, { qty: x })} />
                <TextField label="Unit price" required type="number" min={0} step="any" prefix={form.currency} value={l.unit_price} onChange={(x) => line(i, { unit_price: x })} />
                <TextField label="Supplier's code" maxLength={60} value={l.supplier_sku} onChange={(x) => line(i, { supplier_sku: x })} />
                <TextField label="Batch / lot" required={l.batched} maxLength={60} value={l.batch_no} onChange={(x) => line(i, { batch_no: x })} />
                <TextField label="Expiry" required={l.batched} type="date" value={l.expires_on} onChange={(x) => line(i, { expires_on: x })} />
                <Choose label="Tax" options={c.tax_groups} value={l.tax_group_id} onChange={(x) => line(i, { tax_group_id: x })} placeholder="As the product" />
                <Choose label="Sell unit" options={l.sell_units} value={l.sell_uom_id} onChange={(x) => line(i, { sell_uom_id: x })} placeholder="Unit bought" />
                <TextField label="New selling price" type="number" min={0} step="any" prefix={form.currency} value={l.new_price} onChange={(x) => line(i, { new_price: x })}
                  hint="Empty: stays as it is." />
              </div>
              {check && <Text size={200} className={s.check}>{check}</Text>}
            </div>
          )
        })}
        <Button icon={<AddRegular />} style={{ marginTop: 10 }} onClick={() => set('lines')([...v.lines, blankLine()])}>Add a line</Button>
      </Panel>

      <Panel title="Prices and note">
        <div className={f.grid}>
          <Choose label="New selling prices go on" options={c.price_lists} {...str('price_list_id')} />
          <LongText label="Note" className={f.span2} maxLength={2000} {...str('note')} />
        </div>
      </Panel>

      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || uploading || !ready} onClick={save}>{busy ? 'Saving…' : 'Save'}</Button>
      </div>

      {scanning && <Scan unavailable={form.ai_unavailable} onClose={() => setScanning(false)} onRead={(x) => {
        setV({ ...v, ...x, supplier_id: x.supplier_id || v.supplier_id, lines: x.lines.length ? x.lines : v.lines, attachments: [...v.attachments, ...x.attachments] })
        setScanning(false)
        setNotice('Read: check every line against the invoice.')
      }} />}
      {adding !== null && <AddProduct form={form} onClose={() => setAdding(null)} onAdded={(u) => {
        line(adding, { product_uom_id: u.product_uom_id, label: u.label, batched: u.batched, factor: u.factor, sell_units: u.sell_units, sell_uom_id: '', read_as: null })
        setAdding(null)
      }} />}
    </div>
  )
}

function Panel({ title, about, children }: { title: string; about?: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{about && <Text className={s.about}>{about}</Text>}{children}</section>
}

/** Scan the invoice: the AI reads every page and fills the form; nothing is entered until it is checked and saved. */
function Scan({ unavailable, onClose, onRead }: { unavailable: string | null; onClose: () => void; onRead: (form: DpValues) => void }) {
  const [files, setFiles] = useState<File[]>([])
  return (
    <Ask title="Scan the invoice" go="Read it" ready={!unavailable && files.length > 0} onClose={onClose} width={560}
      run={() => api.dpFiles<DpValues>('scan', files).then(onRead)}>
      <Text block style={{ marginBottom: 10 }}>{unavailable ?? 'The AI reads every page and fills in this form. Nothing is entered until you check it against the paper and save.'}</Text>
      {!unavailable && (
        <Field label="Photos or PDF, every page" required hint="Up to 10 MB each.">
          <input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(e) => setFiles(Array.from(e.target.files ?? []))} />
        </Field>
      )}
    </Ask>
  )
}

/** A product not in the catalogue, added from its line with the pack it is bought in. */
function AddProduct({ form, onClose, onAdded }: { form: DpForm; onClose: () => void; onAdded: (line: DpLine) => void }) {
  const s = useStyles()
  const c = form.choices
  const [p, setP] = useState({ name: '', industry_id: c.industries?.length === 1 ? c.industries[0].id : '', base_uom_id: '', pack_uom_id: '', pack_factor: '', tax_group_id: '', track_batches: true })
  return (
    <Ask title="Add a product" go="Add" ready={!!p.name.trim() && !!p.base_uom_id && !!p.tax_group_id && (!c.industries || !!p.industry_id)} onClose={onClose} width={560}
      run={() => api.dpAddProduct({ ...p, name: p.name.trim(), industry_id: p.industry_id || null, pack_uom_id: p.pack_uom_id || null,
        pack_factor: p.pack_factor ? Number(p.pack_factor) : null }).then(onAdded)}>
      <div className={s.one}>
        <TextField label="Name" required maxLength={160} value={p.name} onChange={(x) => setP({ ...p, name: x })} />
        {c.industries && <Choose label="Industry" required options={c.industries} value={p.industry_id} onChange={(x) => setP({ ...p, industry_id: x })} />}
        <Choose label="Sold in (smallest unit)" required options={c.uoms} value={p.base_uom_id} onChange={(x) => setP({ ...p, base_uom_id: x })} />
        <Choose label="Bought in (optional)" options={c.uoms} value={p.pack_uom_id} onChange={(x) => setP({ ...p, pack_uom_id: x })} hint="e.g. sold in tablets, bought in boxes." />
        {p.pack_uom_id && <TextField label="How many in one" type="number" min={2} value={p.pack_factor} onChange={(x) => setP({ ...p, pack_factor: x })} hint="e.g. 60 tablets in a box." />}
        <Choose label="Tax" required options={c.tax_groups} value={p.tax_group_id} onChange={(x) => setP({ ...p, tax_group_id: x })} />
        <Toggle label="Track batches and expiry dates" value={p.track_batches} onChange={(x) => setP({ ...p, track_batches: x })} />
      </div>
    </Ask>
  )
}
