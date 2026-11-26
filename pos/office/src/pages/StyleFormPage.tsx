import { Button, MessageBar, MessageBarBody, Spinner, Text } from '@fluentui/react-components'
import { ArrowLeftRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type StyleForm, type StyleValues } from '../api'
import { Choose, LongText, Tags, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'

/**
 * New style or change one (part 36), as the current form: name (the code is made from it once and never changes; SKUs
 * become CODE-COLOUR-SIZE), industry, category, brand, tax, season, collection, price, sizes and colours in display order,
 * description and whether it is for sale. Sizes or colours that already have products cannot be removed.
 */
export function StyleFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<StyleForm | null>(null)
  const [v, setV] = useState<StyleValues | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    api.styleForm(id).then((x) => { setD(x); setV(x.values); onTitle(x.code ? `Change ${x.code}` : 'New style') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the form…" /></div>
  const put = <K extends keyof StyleValues>(k: K) => (x: StyleValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/fashion/styles/${id}` : '/office/fashion/styles'))
  const ready = v.name.trim() !== '' && !!v.tax_group_id && v.sizes.length > 0 && v.colours.length > 0
  const save = () => {
    setBusy(true)
    setError('')
    api.saveStyle(id, v).then((x) => navigate(`/office/fashion/styles/${x.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'Style' : 'Styles'}</Button>
      <h1 className={s.title} style={{ marginBottom: 8 }}>{d.code ? `Change ${d.code}` : 'New style'}</h1>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Panel title="Style">
        <div className={f.grid}>
          {!d.code && <Text className={f.spanAll}>The style code is made from the name when saved; SKUs become CODE-COLOUR-SIZE.</Text>}
          <TextField label="Name" required className={f.spanAll} maxLength={255} value={v.name} onChange={put('name')} />
          {d.industries.length > 0 && <Choose label="Industry" options={d.industries} value={v.industry_id} onChange={put('industry_id')} placeholder="Your own industry"
            hint="Only people in this industry see it. Shared ones are seen by every industry." />}
          <Choose label="Category" options={d.categories} value={v.category_id} onChange={put('category_id')} clearable />
          <Choose label="Brand" options={d.brands} value={v.brand_id} onChange={put('brand_id')} clearable />
          <Choose label="Tax" required options={d.taxes} value={v.tax_group_id} onChange={put('tax_group_id')} />
          <TextField label="Season" maxLength={30} placeholder="SS26" value={v.season} onChange={put('season')} />
          <TextField label="Collection" maxLength={60} value={v.collection} onChange={put('collection')} />
          <TextField label="Price (default list)" type="number" min={0} step="any" value={v.price} onChange={put('price')} />
          <Toggle label="For sale" value={v.is_active} onChange={put('is_active')} />
        </div>
      </Panel>
      <Panel title="Sizes and colours" about="Each size × colour becomes its own product when you press Create sizes and colours on the style. Sizes or colours that already have products cannot be removed (switch those products off instead).">
        <div className={f.grid}>
          <Tags label="Sizes" className={f.spanAll} hint="In display order, e.g. S M L XL or 38 40 42." placeholder="Add a size, then Enter" value={v.sizes} onChange={put('sizes')} />
          <Tags label="Colours" className={f.spanAll} placeholder="Add a colour, then Enter" value={v.colours} onChange={put('colours')} />
          <LongText label="Description" className={f.spanAll} maxLength={5000} value={v.description} onChange={put('description')} />
        </div>
      </Panel>
      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Save'}</Button>
      </div>
    </div>
  )
}
