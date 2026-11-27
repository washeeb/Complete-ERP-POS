import {
  Button, Combobox, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody,
  Option, Spinner, Text, Textarea, tokens,
} from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular, SparkleRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api, type Option as Choice, type PriceRow, type ProductFormData, type ProductValues, type UnitRow } from '../api'
import { Choose, ChooseMany, LongText, Tags, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { SaveBar } from '../form/SaveBar'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '12px', flexWrap: 'wrap', alignItems: 'center' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px 16px', marginBottom: '14px' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  about: { display: 'block', marginBottom: '12px', color: tokens.colorNeutralForeground3 },
  row: { display: 'grid', gap: '8px 12px', alignItems: 'end', padding: '10px 0', borderTop: `1px solid ${tokens.colorNeutralStroke3}` },
  units: { gridTemplateColumns: 'minmax(160px, 2fr) minmax(90px, 1fr) auto auto minmax(160px, 2fr) auto', '@media (max-width: 900px)': { gridTemplateColumns: '1fr 1fr' } },
  prices: { gridTemplateColumns: 'minmax(130px, 1.4fr) minmax(110px, 1fr) minmax(70px, .7fr) minmax(100px, 1fr) minmax(150px, 1.3fr) minmax(150px, 1.3fr) auto',
    '@media (max-width: 1100px)': { gridTemplateColumns: '1fr 1fr 1fr' } },
  pack: { gridTemplateColumns: 'minmax(200px, 3fr) minmax(80px, 1fr) minmax(140px, 2fr) auto', '@media (max-width: 760px)': { gridTemplateColumns: '1fr 1fr' } },
  recipe: { gridTemplateColumns: 'minmax(220px, 3fr) minmax(90px, 1fr) auto', '@media (max-width: 760px)': { gridTemplateColumns: '1fr 1fr' } },
})

type Add = { kind: 'category' | 'brand' | 'uom'; then: (id: string) => void }

/**
 * Add or edit a product (release 3): the current form, section by section — the product, the sections of the modules
 * the company uses (medicine, serial numbers, containers, consignment, health check, services, restaurant), barcodes and
 * units, and prices — saved by the same rules. A missing category, brand or unit is added from the form ("+"); on an
 * existing product the AI drafts a description. A rule the save breaks is said at the top, and nothing is saved.
 */
export function ProductFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<ProductFormData | null>(null)
  const [v, setV] = useState<ProductValues | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [adding, setAdding] = useState<Add | null>(null)
  const [describing, setDescribing] = useState(false)
  const top = useRef<HTMLDivElement>(null)

  useEffect(() => {
    api.productForm(id ?? undefined).then((x) => { setForm(x); setV(x.values); onTitle(x.name ? `Edit ${x.name}` : 'New product') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (!form || !v) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const c = form.choices
  const sec = form.sections
  const set = <K extends keyof ProductValues>(k: K) => (x: ProductValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const str = (k: keyof ProductValues) => ({ value: v[k] as string, onChange: set(k) as (x: string) => void })
  const choice = (k: keyof ProductValues, options: Choice[]) => ({ options, ...str(k) })
  const quick = (kind: Add['kind'], k: keyof ProductValues) => (form.can.quick_add ? () => setAdding({ kind, then: (newId) => set(k)(newId as never) }) : undefined)
  const units = v.units
  const setUnit = (i: number, u: Partial<UnitRow>) => set('units')(units.map((x, j) => (j === i ? { ...x, ...u } : x)))
  const setPrice = (i: number, p: Partial<PriceRow>) => set('prices')(v.prices.map((x, j) => (j === i ? { ...x, ...p } : x)))
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/products/${id}` : '/office/products'))

  const save = () => {
    setBusy(true)
    setError('')
    api.saveProduct(id, v).then((r) => navigate(`/office/products/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) })
      .finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'The product' : 'Products'}</Button>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>{id ? `Edit ${form.name}` : 'New product'}</h1>
          <Text className={s.muted}>{form.sku ? `SKU ${form.sku} · given by the system; it never changes.` : 'The SKU is given when you save, so no two products share one.'}</Text>
        </div>
        <div className={s.actions}>
          {form.can.describe && <Button icon={<SparkleRegular />} onClick={() => setDescribing(true)}>Write description (AI)</Button>}
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      <Panel title="Product">
        <div className={f.grid}>
          <TextField label="Name" required className={f.span2} maxLength={160} {...str('name')} />
          {sec.industry && <Choose label="Industry" required {...choice('industry_id', c.industries)}
            hint="Each industry has its own catalogue: its tills and screens show only its products. Shared items are sold by every industry." />}
          <TextField label="Location / shelf" placeholder="e.g. Shelf B2" maxLength={60} {...str('location')} hint="Shows on the stock and count sheets and in the till's search." />
          <Choose label="Category" {...choice('category_id', c.categories)} onAdd={quick('category', 'category_id')} />
          <Choose label="Brand" {...choice('brand_id', c.brands)} onAdd={quick('brand', 'brand_id')} />
          <Choose label="Tax" required {...choice('tax_group_id', c.tax_groups)} />
          <Choose label="Base unit (stock is kept in this)" required {...choice('base_uom_id', c.uoms)} disabled={form.base_unit_locked} onAdd={quick('uom', 'base_uom_id')}
            hint={form.base_unit_locked ? 'Stock has moved: use Change the unit on the product if it is wrong.' : undefined} />
          <Choose label="Usually bought in" {...choice('buying_uom_id', c.uoms)} placeholder="The largest unit bought"
            hint="e.g. Box: purchase orders offer it first. Add the box under Barcodes and units, marked Bought." />
          <TextField label="Scale code (PLU)" maxLength={5} {...str('plu')} hint="For items weighed on the label scale: the item number the scale prints in the barcode." />
          <Toggle label="Track stock" value={v.track_stock} onChange={set('track_stock')} />
          <Toggle label="For sale" value={v.is_active} onChange={set('is_active')} />
          <Toggle label="Batches and expiry dates" value={v.track_batches} onChange={set('track_batches')}
            hint="Received with batch number and expiry; sold oldest expiry first; expired stock cannot be sold." />
          <LongText label="Description" className={f.spanAll} maxLength={5000} {...str('description')} />
        </div>
      </Panel>

      {sec.medicine && (
        <Panel title="Medicine" about="Pharmacy: prescription-only and controlled medicines need a pharmacist's approval at the till. Medicines must track batches and expiry.">
          <div className={f.grid}>
            <Choose label="Schedule" placeholder="Not a medicine" {...choice('drug_schedule', c.schedules)} />
            <TextField label="Generic name" maxLength={255} {...str('generic_name')} />
            <TextField label="Strength" placeholder="500 mg" maxLength={60} {...str('strength')} />
            <TextField label="Form" placeholder="Capsule" maxLength={40} {...str('dosage_form')} />
            <TextField label="FDA registration no." maxLength={60} {...str('fda_reg_no')} />
            <Tags label="Active ingredients" placeholder="Type, then Enter" value={v.active_ingredients} onChange={set('active_ingredients')} hint="Used for allergy and interaction warnings." />
            <TextField label="Usual dosage (directions)" className={f.spanAll} maxLength={255} placeholder="1 TABLET 3 TIMES DAILY AFTER MEALS FOR 5 DAYS" {...str('dosage')}
              hint="Filled in on the till for each sale (it can be changed there) and printed on the dosage label and the receipt." />
            <TextField label="NHIS price (per base unit)" type="number" min={0} step="any" prefix={form.currency} {...str('nhis_price')}
              hint="What the NHIS pays for one base unit, from the NHIS Medicines List. Empty = not covered." />
            <TextField label="Used a day (refill reminders)" type="number" min={0} max={1000} step="any" {...str('daily_use')}
              hint="For medicines taken every day: how much one patient uses a day, in the base unit. Customers who buy it get a reminder before it runs out." />
          </div>
        </Panel>
      )}

      {sec.serials && (
        <Panel title="Serial numbers and warranty" about="Electronics: each unit is received, transferred and sold by its serial / IMEI number; the warranty runs from the date sold.">
          <div className={f.grid}>
            <Toggle label="Serial / IMEI numbers" value={v.track_serials} onChange={set('track_serials')} hint='Needs "Track stock"; not with batches.' />
            <TextField label="Warranty (months)" type="number" min={0} max={120} {...str('warranty_months')} />
          </div>
        </Panel>
      )}

      {sec.containers && (
        <Panel title="Returnable containers (empties)" about="Crates, bottles, gas cylinders: the container is a product of its own, sold at its deposit and bought back at the same price when the empty comes back.">
          <div className={f.grid}>
            <Toggle label="This product is a returnable container" value={v.is_container} onChange={set('is_container')} hint="Its price is the deposit." />
            <Choose label="Sold with this container (deposit)" placeholder="No deposit" {...choice('deposit_product_id', c.containers)} />
            <TextField label="Containers per unit sold" type="number" min={0.001} step="any" {...str('deposit_per_unit')} hint="1 for a bottle; 0.042 if a crate holds 24 and you sell single bottles." />
          </div>
        </Panel>
      )}

      {sec.consignment && (
        <Panel title="Consignment" about="Stock that belongs to a supplier: received at no cost; each sale owes them their share, settled from Fashion → Sell-through & consignment.">
          <div className={f.grid}>
            <Choose label="Consignor (supplier)" placeholder="Our own stock" {...choice('consignor_id', c.suppliers)} />
            <TextField label="Their share (basis points of the price before tax)" type="number" min={1} max={10000} {...str('consignment_bp')} hint="6000 = 60 %." />
          </div>
        </Panel>
      )}

      {sec.health && (
        <Panel title="Health check" about="A fee service such as a blood pressure check: switch off stock for it, and choose the reading. The till then asks for it.">
          <div className={f.grid}><Choose label="Reading taken" placeholder="None" {...choice('reading_kind', c.readings)} /></div>
        </Panel>
      )}

      {sec.services && (
        <Panel title="Services" about="A bookable service has a duration (it appears in the appointment book). A prepaid pack is sold once and gives the customer sessions, used later at the till at no charge. Packs and licence-key products do not track stock.">
          <div className={f.grid}>
            <TextField label="Duration (minutes)" type="number" min={5} max={720} {...str('service_minutes')} hint="For a service that is booked, e.g. 45 for a haircut." />
            <TextField label="Commission (basis points)" type="number" min={0} max={10000} {...str('commission_bp')} hint="Empty = the staff member's own rate. 1000 = 10 %." />
            <Toggle label="Hands out a licence key" value={v.licence_keys} onChange={set('licence_keys')} hint="Software: one key per unit sold, from the keys loaded under Services → Licence keys." />
          </div>
          <Text weight="semibold" block style={{ margin: '14px 0 2px' }}>Prepaid pack: what it includes</Text>
          <Text size={200} className={s.muted}>Leave empty if this is not a pack. Example: "10 massages" = one row with the massage services and 10 sessions.</Text>
          {v.pack.items.map((it, i) => (
            <div key={i} className={`${s.row} ${s.pack}`}>
              <ChooseMany label="Used for (services)" options={c.services} value={it.product_ids}
                onChange={(ids) => set('pack')({ ...v.pack, items: v.pack.items.map((x, j) => (j === i ? { ...x, product_ids: ids } : x)) })} />
              <TextField label="Sessions" type="number" min={1} max={1000} value={it.sessions}
                onChange={(x) => set('pack')({ ...v.pack, items: v.pack.items.map((y, j) => (j === i ? { ...y, sessions: x } : y)) })} />
              <TextField label="Shown as (optional)" maxLength={120} value={it.label}
                onChange={(x) => set('pack')({ ...v.pack, items: v.pack.items.map((y, j) => (j === i ? { ...y, label: x } : y)) })} />
              <Remove what="this pack item" onClick={() => set('pack')({ ...v.pack, items: v.pack.items.filter((_, j) => j !== i) })} />
            </div>
          ))}
          <Button icon={<AddRegular />} appearance="subtle" onClick={() => set('pack')({ ...v.pack, items: [...v.pack.items, { product_ids: [], sessions: '', label: '' }] })}>Add a pack item</Button>
          <div className={f.grid} style={{ marginTop: 8 }}>
            <TextField label="Pack valid for (days)" type="number" min={1} max={3650} value={v.pack.valid_days} onChange={(x) => set('pack')({ ...v.pack, valid_days: x })} hint="Empty = no expiry." />
          </div>
        </Panel>
      )}

      {sec.restaurant && (
        <Panel title="Restaurant" about="Dishes and drinks: where they are prepared, the choices offered, and the ingredients one portion uses (taken from stock when the bill is paid; switch Track stock off for the dish itself).">
          <div className={f.grid}>
            <Choose label="Prepared at (kitchen station)" placeholder="Not sent to a kitchen" {...choice('station', c.stations)} />
            <ChooseMany label="Choices offered" options={c.modifier_groups} value={v.modifier_group_ids} onChange={set('modifier_group_ids')} />
          </div>
          <Text weight="semibold" block style={{ margin: '14px 0 2px' }}>Recipe (per one)</Text>
          {v.recipe.map((r, i) => (
            <div key={i} className={`${s.row} ${s.recipe}`}>
              <Ingredient value={r.product_uom_id} label={r.label} onChange={(uid, lbl) => set('recipe')(v.recipe.map((x, j) => (j === i ? { ...x, product_uom_id: uid, label: lbl } : x)))} />
              <TextField label="Quantity" type="number" min={0.001} step="any" value={r.qty} onChange={(q) => set('recipe')(v.recipe.map((x, j) => (j === i ? { ...x, qty: q } : x)))} />
              <Remove what="this ingredient" onClick={() => set('recipe')(v.recipe.filter((_, j) => j !== i))} />
            </div>
          ))}
          <Button icon={<AddRegular />} appearance="subtle" onClick={() => set('recipe')([...v.recipe, { product_uom_id: '', qty: '', label: null }])}>Add an ingredient</Button>
        </Panel>
      )}

      <Panel title="Barcodes and units" about="Scanning a barcode adds that unit to the till. Buy in boxes or cartons, sell in tablets, strips or pieces: add each pack with how many base units it holds, and mark whether it is Sold, Bought, or both.">
        <Tags label="Barcodes for the base unit" value={v.base_barcodes} onChange={set('base_barcodes')} />
        {units.map((u, i) => (
          <div key={i} className={`${s.row} ${s.units}`}>
            <Choose label="Unit" required options={c.uoms} value={u.uom_id} onChange={(x) => setUnit(i, { uom_id: x })} onAdd={form.can.quick_add ? () => setAdding({ kind: 'uom', then: (nid) => setUnit(i, { uom_id: nid }) }) : undefined} />
            <TextField label="Base units in one" required type="number" min={0.000001} step="any" value={u.factor} onChange={(x) => setUnit(i, { factor: x })} />
            <Toggle label="Sold" value={u.is_sellable} onChange={(x) => setUnit(i, { is_sellable: x })} />
            <Toggle label="Bought" value={u.is_purchasable} onChange={(x) => setUnit(i, { is_purchasable: x })} />
            <Tags label="Barcodes" value={u.barcodes} onChange={(x) => setUnit(i, { barcodes: x })} />
            <Remove what="this unit" onClick={() => set('units')(units.filter((_, j) => j !== i))} />
          </div>
        ))}
        <Button icon={<AddRegular />} appearance="subtle" onClick={() => set('units')([...units, { uom_id: '', factor: '', is_sellable: true, is_purchasable: true, barcodes: [] }])}>Add a unit</Button>
      </Panel>

      {sec.prices && (
        <Panel title="Prices" about="One row per price list and unit. Add rows with a minimum quantity for bulk prices, or with dates for a promotion.">
          <Text block size={200} style={{ marginBottom: 6, color: form.below_cost ? 'var(--zx-bad)' : tokens.colorNeutralForeground3 }}>
            {form.pricing_note ?? "After saving, the pricing rules (Pricing & tax → Pricing rules) set this product's prices from its cost."}
          </Text>
          {v.prices.map((p, i) => (
            <div key={i} className={`${s.row} ${s.prices}`}>
              <Choose label="List" required options={c.price_lists} value={p.price_list_id} onChange={(x) => setPrice(i, { price_list_id: x })} />
              <Choose label="Unit" required options={c.uoms.filter((o) => o.id === v.base_uom_id || units.some((u) => u.uom_id === o.id))} value={p.uom_id} onChange={(x) => setPrice(i, { uom_id: x })} />
              <TextField label="From qty" required type="number" min={0} step="any" value={p.min_qty} onChange={(x) => setPrice(i, { min_qty: x })} />
              <TextField label="Price" required type="number" min={0} step="any" prefix={form.currency} value={p.amount} onChange={(x) => setPrice(i, { amount: x })} />
              <TextField label="From" type="datetime-local" value={p.starts_at} onChange={(x) => setPrice(i, { starts_at: x })} />
              <TextField label="Until" type="datetime-local" value={p.ends_at} onChange={(x) => setPrice(i, { ends_at: x })} />
              <Remove what="this price" onClick={() => set('prices')(v.prices.filter((_, j) => j !== i))} />
            </div>
          ))}
          <Button icon={<AddRegular />} appearance="subtle" onClick={() => set('prices')([...v.prices, {
            price_list_id: c.price_lists[0]?.id ?? '', uom_id: v.base_uom_id, min_qty: '1', amount: '', starts_at: '', ends_at: '',
          }])}>Add a price</Button>
        </Panel>
      )}

      {id && (
        <Panel title="Reason">
          <TextField label="Reason for change (optional, kept in the audit log)" maxLength={255} {...str('reason')} />
        </Panel>
      )}

      <SaveBar value={v}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !v.name.trim()} onClick={save}>{busy ? 'Saving…' : id ? 'Save changes' : 'Create product'}</Button>
      </SaveBar>

      {adding && <QuickAdd kind={adding.kind} onClose={() => setAdding(null)} onAdded={(o) => {
        const key = adding.kind === 'category' ? 'categories' : adding.kind === 'brand' ? 'brands' : 'uoms'
        setForm((x) => (x && !x.choices[key].some((y) => y.id === o.id) ? { ...x, choices: { ...x.choices, [key]: [...x.choices[key], o].sort((a, b) => a.label.localeCompare(b.label)) } } : x))
        adding.then(o.id)
        setAdding(null)
      }} />}
      {describing && id && <Describe id={id} unavailable={form.ai_unavailable} current={v.description} onClose={() => setDescribing(false)}
        onUse={(text) => { set('description')(text); setDescribing(false) }} />}
    </div>
  )
}

/** One section of the form, with what it is for. (Outside the page, so typing never rebuilds the fields.) */
function Panel({ title, about, children }: { title: string; about?: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{about ? <Text size={200} className={s.about}>{about}</Text> : <div style={{ height: 8 }} />}{children}</section>
}

function Remove({ what, onClick }: { what: string; onClick: () => void }) {
  return <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove ${what}`} title={`Remove ${what}`} onClick={onClick} />
}

/** A recipe ingredient: type part of its name; products that track stock are offered, by unit. */
function Ingredient({ value, label, onChange }: { value: string; label: string | null; onChange: (id: string, label: string | null) => void }) {
  const [text, setText] = useState(label ?? '')
  const [found, setFound] = useState<Choice[]>(value && label ? [{ id: value, label }] : [])
  useEffect(() => {
    if (!text.trim() || text === label) return
    const ctl = new AbortController()
    const t = setTimeout(() => api.ingredients(text, ctl.signal).then((r) => setFound(r.results)).catch(() => undefined), 250)
    return () => { clearTimeout(t); ctl.abort() }
  }, [text, label])
  return (
    <Field label="Ingredient" required>
      <Combobox value={text} selectedOptions={value ? [value] : []} placeholder="Type to find a product" onChange={(e) => setText(e.target.value)}
        onOptionSelect={(_, d) => { onChange(d.optionValue ?? '', d.optionText ?? null); setText(d.optionText ?? '') }}>
        {found.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        {found.length === 0 && <Option disabled value="">Type part of the name</Option>}
      </Combobox>
    </Field>
  )
}

/** "+": a missing category, brand or unit, added and picked without leaving the form. */
function QuickAdd({ kind, onClose, onAdded }: { kind: Add['kind']; onClose: () => void; onAdded: (o: Choice) => void }) {
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [decimals, setDecimals] = useState('0')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const what = kind === 'uom' ? 'a unit' : `a ${kind}`
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 440 }}>
        <DialogBody>
          <DialogTitle>Add {what}</DialogTitle>
          <DialogContent>
            <div style={{ display: 'grid', gap: 10 }}>
              {kind === 'uom' && <Field label="Code" required><Input value={code} maxLength={10} placeholder="PK" onChange={(_, d) => setCode(d.value)} /></Field>}
              <Field label="Name" required><Input value={name} maxLength={120} placeholder={kind === 'uom' ? 'Pack' : undefined} onChange={(_, d) => setName(d.value)} /></Field>
              {kind === 'uom' && <Field label="Decimal places when selling (0 = whole units)"><Input type="number" min={0} max={3} value={decimals} onChange={(_, d) => setDecimals(d.value)} /></Field>}
              {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
            </div>
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Cancel</Button>
            <Button appearance="primary" disabled={busy || !name.trim() || (kind === 'uom' && !code.trim())} onClick={() => {
              setBusy(true)
              api.quickAdd(kind, { name: name.trim(), ...(kind === 'uom' ? { code: code.trim(), decimals: Number(decimals) || 0 } : {}) })
                .then((r) => onAdded({ id: r.id, label: r.label })).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Adding…' : 'Add'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}

/** A draft online-shop description from the product's details; the person edits it, and it is saved with the form. */
function Describe({ id, unavailable, current, onClose, onUse }: { id: string; unavailable: string | null; current: string; onClose: () => void; onUse: (t: string) => void }) {
  const [tone, setTone] = useState('friendly')
  const [text, setText] = useState(current)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const tones: Choice[] = [{ id: 'friendly', label: 'Friendly' }, { id: 'professional', label: 'Professional' }, { id: 'short', label: 'Very short' }]
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 560 }}>
        <DialogBody>
          <DialogTitle>Write description (AI)</DialogTitle>
          <DialogContent>
            <div style={{ display: 'grid', gap: 10 }}>
              <Text>{unavailable ?? 'A draft from the product details. Edit it as you like; nothing is saved until you save the product.'}</Text>
              <Field label="Style">
                <Dropdown value={tones.find((t) => t.id === tone)?.label} selectedOptions={[tone]} onOptionSelect={(_, d) => setTone(d.optionValue ?? 'friendly')}>
                  {tones.map((t) => <Option key={t.id} value={t.id}>{t.label}</Option>)}
                </Dropdown>
              </Field>
              <Field label="Description"><Textarea rows={6} value={text} maxLength={2000} onChange={(_, d) => setText(d.value)} /></Field>
              {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
            </div>
          </DialogContent>
          <DialogActions>
            <Button icon={<SparkleRegular />} disabled={busy || unavailable !== null} onClick={() => {
              setBusy(true)
              setError('')
              api.describeProduct(id, tone).then((r) => setText(r.description)).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Drafting…' : 'Draft with AI'}</Button>
            <Button onClick={onClose}>Cancel</Button>
            <Button appearance="primary" onClick={() => onUse(text)}>Use this description</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
