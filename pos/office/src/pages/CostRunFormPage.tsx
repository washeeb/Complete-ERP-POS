import { Button, MessageBar, MessageBarBody, Spinner, Tag, TagGroup, Text } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, CalculatorRegular, DeleteRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api, type CostRunForm, type CostRunValues } from '../api'
import { Choose, ChooseMany, LongText, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

type Override = { kind: 'category' | 'brand'; id: string; value: string }
const FOR = [{ id: 'category', label: 'Category' }, { id: 'brand', label: 'Brand' }]

/**
 * A new cost run (part 19), as the current form: how the cost is worked out (selling price ÷ a factor, or less a profit
 * percentage; a different factor for some categories or brands), which products (all in stock, or chosen products,
 * categories, brands, suppliers or stores; products ticked on the products list come already chosen), and the books
 * (the date, the cost of past sales, the account). Saving works out the preview; nothing moves until it is posted.
 */
export function CostRunFormPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<CostRunForm | null>(null)
  const [v, setV] = useState<CostRunValues | null>(null)
  const [pick, setPick] = useState({ category_ids: [] as string[], brand_ids: [] as string[], supplier_ids: [] as string[], warehouse_ids: [] as string[] })
  const [overrides, setOverrides] = useState<Override[]>([])
  const [account, setAccount] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const top = useRef<HTMLDivElement>(null)
  useEffect(() => {
    api.costRunForm().then((x) => { setForm(x); setV(x.values) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])

  if (!form || !v) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const set = <K extends keyof CostRunValues>(k: K) => (x: CostRunValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const margin = v.method === 'margin'
  const ready = !!v.method && Number(v.value) > 0 && !!v.price_list_id && !!v.tax_mode && !!v.effective_on && overrides.every((o) => !!o.id && Number(o.value) > 0)
  const back = () => (history.length > 1 ? history.back() : navigate('/office/cost-runs'))
  const save = () => {
    setBusy(true)
    setError('')
    api.saveCostRun({ ...v, products: undefined, product_ids: v.products.map((x) => x.id), ...pick, overrides, account_id: account || null, note: note || null })
      .then((r) => navigate(`/office/cost-runs/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  const override = (i: number, patch: Partial<Override>) => setOverrides((o) => o.map((x, j) => (j === i ? { ...x, ...patch } : x)))
  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Cost runs</Button>
      <h1 className={s.title} style={{ marginBottom: 4 }}>New cost run</h1>
      <Text className={s.muted}>Saving works out a preview. Nothing changes until it is posted.</Text>
      {error && <MessageBar intent="error" style={{ margin: '12px 0' }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <div style={{ height: 12 }} />
      <Panel title="How to work out the cost">
        <div className={f.grid}>
          <Choose label="Method" required options={form.methods} value={v.method} onChange={set('method')} className={f.spanAll} />
          <TextField label={margin ? 'Profit, % of the selling price' : 'Factor'} required type="number" step="any" value={v.value} onChange={set('value')}
            hint={margin ? 'A 14.00 item at 40% costs 8.40.' : 'A 14.00 item at 1.4 costs 10.00.'} />
          <Choose label="Selling prices from" required options={form.price_lists} value={v.price_list_id} onChange={set('price_list_id')} />
          <Choose label="VAT and levies" required options={form.tax_modes} value={v.tax_mode} onChange={set('tax_mode')} className={f.spanAll}
            hint="Use the price the customer pays if that is the price you multiply your cost by. Remove them first if you add your profit before tax." />
        </div>
        <Text weight="semibold" block style={{ margin: '14px 0 4px' }}>A different factor for some categories or brands (optional)</Text>
        {overrides.map((o, i) => (
          <div key={i} className={s.grid} role="group" aria-label={`Different factor ${i + 1}`}>
            <Choose label="Which" required options={o.kind === 'brand' ? form.brands : form.categories} value={o.id} onChange={(x) => override(i, { id: x })} />
            <Choose label="For" required options={FOR} value={o.kind} onChange={(x) => override(i, { kind: x as Override['kind'], id: '' })} />
            <div style={{ display: 'flex', gap: 6, alignItems: 'flex-end' }}>
              <TextField label="Factor or %" required type="number" step="any" value={o.value} onChange={(x) => override(i, { value: x })} />
              <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove different factor ${i + 1}`} onClick={() => setOverrides(overrides.filter((_, j) => j !== i))} />
            </div>
          </div>
        ))}
        <Button icon={<AddRegular />} style={{ marginTop: 6 }} onClick={() => setOverrides([...overrides, { kind: 'category', id: '', value: '' }])}>Add a different factor</Button>
      </Panel>
      <Panel title="Which products" about="Leave everything empty for all products in stock.">
        <div className={f.grid}>
          <div className={f.spanAll}>
            <SearchPicker label="Only these products" value="" text={null} placeholder="Type a name or SKU to add"
              search={api.costRunProducts} onChange={(x) => { if (x && !v.products.some((p) => p.id === x.id)) set('products')([...v.products, x]) }} />
            {v.products.length > 0 && (
              <TagGroup aria-label="Products chosen" style={{ flexWrap: 'wrap', gap: 4, marginTop: 6 }}
                onDismiss={(_, d) => set('products')(v.products.filter((p) => p.id !== d.value))}>
                {v.products.map((p) => <Tag key={p.id} value={p.id} dismissible dismissIcon={{ 'aria-label': `Remove ${p.label}` }} size="small">{p.label}</Tag>)}
              </TagGroup>
            )}
          </div>
          <ChooseMany label="Categories" options={form.categories} value={pick.category_ids} onChange={(x) => setPick({ ...pick, category_ids: x })} placeholder="Any" />
          <ChooseMany label="Brands" options={form.brands} value={pick.brand_ids} onChange={(x) => setPick({ ...pick, brand_ids: x })} placeholder="Any" />
          <ChooseMany label="Suppliers" options={form.suppliers} value={pick.supplier_ids} onChange={(x) => setPick({ ...pick, supplier_ids: x })} placeholder="Any" />
          <ChooseMany label="Stores" options={form.warehouses} value={pick.warehouse_ids} onChange={(x) => setPick({ ...pick, warehouse_ids: x })} placeholder="All yours" />
          <Toggle label="Also replace costs products already have" className={f.spanAll} value={v.overwrite} onChange={set('overwrite')}
            hint="Off: only products with no cost are filled in, and real costs from deliveries stay. On: their real cost is lost from the average. To re-cost products you chose, switch this on." />
        </div>
      </Panel>
      <Panel title="The books">
        <div className={f.grid}>
          <TextField label="Date in the books" required type="date" value={v.effective_on} onChange={set('effective_on')} hint="Usually the go-live date. It must be in an open month." />
          <Toggle label="Add the cost of past sales" value={v.catch_up} onChange={set('catch_up')}
            hint="Sales made with no cost stay as they are; one entry per branch adds their estimated cost, so profit is realistic." />
          {form.accounts && (
            <Choose label="Against account (optional)" options={form.accounts} value={account} onChange={setAccount} className={f.spanAll}
              hint="Empty: stock that had no cost goes against Opening balances (to clear); a replaced cost goes to Stock losses and count differences." />
          )}
          <LongText label="Note" className={f.spanAll} maxLength={255} value={note} onChange={setNote} />
        </div>
      </Panel>
      <SaveBar value={[v, pick, overrides, account, note]}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<CalculatorRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Working it out…' : 'Work it out'}</Button>
      </SaveBar>
    </div>
  )
}
