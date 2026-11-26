import { Button, MessageBar, MessageBarBody, Spinner, Text } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type BomForm, type BomLineValues, type BomOperationValues, type BomValues } from '../api'
import { Choose, LongText, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { UnitPicker } from '../form/UnitPicker'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'

const LINE: BomLineValues = { kind: 'material', product_uom_id: '', unit: null, qty: '', scrap_pct: '0', backflush: true }
const STEP: BomOperationValues = { name: '', work_centre_id: '', setup_minutes: '0', run_minutes: '0', backflush: true }

/**
 * New or change a bill of materials (part 35; only a draft changes), as the current form: what it makes, one batch, the
 * quality check before stock, method; lines (material or by-product, the item and unit, quantity per batch, expected loss
 * and backflush); operations (name, work centre, set-up and run minutes, backflushed time).
 */
export function BomFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<BomForm | null>(null)
  const [v, setV] = useState<BomValues | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    api.bomForm(id).then((x) => { setD(x); setV(x.values); onTitle(x.code ? `Change ${x.code}` : 'New bill of materials') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the form…" /></div>
  const line = (i: number, p: Partial<BomLineValues>) => setV({ ...v, lines: v.lines.map((l, j) => (j === i ? { ...l, ...p } : l)) })
  const step = (i: number, p: Partial<BomOperationValues>) => setV({ ...v, operations: v.operations.map((o, j) => (j === i ? { ...o, ...p } : o)) })
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/manufacturing/boms/${id}` : '/office/manufacturing/boms'))
  const ready = (!!id || !!v.product_id) && Number(v.output_qty) > 0 && v.lines.length > 0 && v.lines.every((l) => l.product_uom_id && Number(l.qty) > 0)
    && v.operations.every((o) => o.name.trim() !== '' && !!o.work_centre_id)
  const save = () => {
    setBusy(true)
    setError('')
    api.saveBom(id, { ...v, lines: v.lines.map(({ unit: _u, ...l }) => l), product: undefined }).then((x) => navigate(`/office/manufacturing/boms/${x.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'Bill of materials' : 'Bills of materials'}</Button>
      <h1 className={s.title} style={{ marginBottom: 8 }}>{d.code ? `Change ${d.code}` : 'New bill of materials'}</h1>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Panel title="Bill of materials">
        <div className={f.grid}>
          <div className={f.spanAll}>
            {id ? <Text>Makes <b>{v.product}</b></Text> : (
              <SearchPicker label="Makes" required value={v.product_id} text={v.product} search={api.mfgProducts} placeholder="Name or SKU"
                onChange={(x) => setV({ ...v, product_id: x?.id ?? '', product: x?.label ?? null })} />
            )}
          </div>
          <TextField label="One batch makes (base units)" required type="number" min={0.001} step="any" value={v.output_qty} onChange={(x) => setV({ ...v, output_qty: x })} />
          <Toggle label="Quality check before stock" value={v.qc_required} onChange={(x) => setV({ ...v, qc_required: x })} />
          <LongText label="Method / notes" className={f.spanAll} maxLength={5000} value={v.note} onChange={(x) => setV({ ...v, note: x })} />
        </div>
      </Panel>
      <Panel title="Lines" about="Materials used for one batch, and by-products it gives. Backflushed materials come out of stock as output is reported; the rest are issued by hand.">
        {v.lines.map((l, i) => (
          <div key={i} className={s.line} role="group" aria-label={`Line ${i + 1}`}>
            <div className={f.grid}>
              <Choose label="Type" required options={d.kinds} value={l.kind} onChange={(x) => line(i, { kind: x || 'material' })} />
              <div className={f.spanAll}>
                <UnitPicker value={l.product_uom_id} label={l.unit} search={api.mfgUnits} onChange={(u) => line(i, { product_uom_id: u?.id ?? '', unit: u?.label ?? null })} />
              </div>
              <TextField label="Quantity" required type="number" min={0.001} step="any" value={l.qty} onChange={(x) => line(i, { qty: x })} />
              {l.kind === 'material' && <TextField label="Expected loss %" type="number" min={0} max={50} step="any" value={l.scrap_pct} onChange={(x) => line(i, { scrap_pct: x })} />}
              {l.kind === 'material' && <Toggle label="Backflush" value={l.backflush} onChange={(x) => line(i, { backflush: x })} />}
              <div style={{ display: 'flex', alignItems: 'flex-end' }}>
                {v.lines.length > 1 && <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${i + 1}`} onClick={() => setV({ ...v, lines: v.lines.filter((_, j) => j !== i) })}>Remove</Button>}
              </div>
            </div>
          </div>
        ))}
        <div style={{ marginTop: 10 }}><Button icon={<AddRegular />} onClick={() => setV({ ...v, lines: [...v.lines, { ...LINE }] })}>Add line</Button></div>
      </Panel>
      <Panel title="Operations" about="Optional. Steps on work centres; their minutes cost labour and overhead at the work centre's hourly cost. Backflushed time is charged as output is reported.">
        {v.operations.length === 0 && <Text className={s.muted}>No operations: only materials are costed.</Text>}
        {v.operations.map((o, i) => (
          <div key={i} className={s.line} role="group" aria-label={`Operation ${i + 1}`}>
            <div className={f.grid}>
              <TextField label="Operation" required maxLength={120} value={o.name} onChange={(x) => step(i, { name: x })} />
              <Choose label="Work centre" required options={d.work_centres} value={o.work_centre_id} onChange={(x) => step(i, { work_centre_id: x })} />
              <TextField label="Set-up min" type="number" min={0} step="any" value={o.setup_minutes} onChange={(x) => step(i, { setup_minutes: x })} />
              <TextField label="Run min" type="number" min={0} step="any" value={o.run_minutes} onChange={(x) => step(i, { run_minutes: x })} />
              <Toggle label="Backflush" value={o.backflush} onChange={(x) => step(i, { backflush: x })} />
              <div style={{ display: 'flex', alignItems: 'flex-end' }}>
                <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove operation ${i + 1}`} onClick={() => setV({ ...v, operations: v.operations.filter((_, j) => j !== i) })}>Remove</Button>
              </div>
            </div>
          </div>
        ))}
        <div style={{ marginTop: 10 }}>
          <Button icon={<AddRegular />} disabled={d.work_centres.length === 0} onClick={() => setV({ ...v, operations: [...v.operations, { ...STEP }] })}>Add operation</Button>
          {d.work_centres.length === 0 && <Text className={s.muted} style={{ marginLeft: 8 }}>Add a work centre first (Manufacturing → Work centres).</Text>}
        </div>
      </Panel>
      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Save draft'}</Button>
      </div>
    </div>
  )
}
