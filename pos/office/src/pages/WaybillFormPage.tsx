import { Button, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState, type ReactNode } from 'react'
import { api, type Option } from '../api'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  muted: { color: tokens.colorNeutralForeground3 },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px 16px', margin: '12px 0' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  foot: { display: 'flex', justifyContent: 'flex-end', gap: '8px', padding: '10px 0' },
})

const BLANK = { kind: '', source_id: '', reference: '', transporter: '', driver_name: '', driver_phone: '', vehicle_no: '', packages: '', weight_kg: '', to_address: '', to_phone: '', notes: '' }

/**
 * A new waybill (part 17), as the current form: what it is for (a delivery, a transfer, a return to a supplier, or the
 * supplier's waybill on goods received) and the document — the goods are copied from it, as sent — then transport and,
 * for goods going out, where they go. Opened from a document with its kind and number filled in.
 */
export function WaybillFormPage() {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const q = new URLSearchParams(location.search)
  const [v, setV] = useState({ ...BLANK, kind: q.get('kind') ?? '', source_id: q.get('source') ?? '' })
  const [kinds, setKinds] = useState<Option[] | null>(null)
  const [sources, setSources] = useState<Option[]>([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    api.waybillForm(v.kind).then((r) => { setKinds(r.kinds); setSources(r.sources) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [v.kind])
  if (!kinds) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const set = (k: keyof typeof BLANK) => (x: string) => setV({ ...v, [k]: x })
  const inbound = v.kind === 'inbound'
  const ready = !!v.kind && !!v.source_id && (!inbound || v.reference.trim() !== '')
  const save = () => {
    setBusy(true)
    setError('')
    api.prepareWaybill(v).then((r) => navigate(`/office/waybills/${r.id}`, true)).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/waybills'))}>Waybills</Button>
      <h1 className={s.title}>New waybill</h1>
      <Text className={s.muted}>The goods are copied from the document, as sent.</Text>
      {error && <MessageBar intent="error" style={{ marginTop: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Panel title="What it carries">
        <div className={f.grid}>
          <Choose label="Waybill for" required options={kinds} value={v.kind} onChange={(x) => setV({ ...v, kind: x, source_id: '' })} />
          <Choose label="Document" required options={sources} value={v.source_id} onChange={set('source_id')} placeholder={v.kind ? 'Choose the document' : 'Choose what it is for first'} />
          <TextField label={inbound ? "Supplier's waybill number" : "Transporter's reference (optional)"} required={inbound} maxLength={80} value={v.reference} onChange={set('reference')} />
        </div>
      </Panel>
      <Panel title="Transport">
        <div className={f.grid}>
          <TextField label="Transport company" maxLength={120} value={v.transporter} onChange={set('transporter')} />
          <TextField label="Driver" maxLength={120} value={v.driver_name} onChange={set('driver_name')} />
          <TextField label="Driver's phone" maxLength={30} value={v.driver_phone} onChange={set('driver_phone')} />
          <TextField label="Vehicle registration" maxLength={30} placeholder="GR 1234-24" value={v.vehicle_no} onChange={set('vehicle_no')} />
          <TextField label="Packages" type="number" min={1} step="1" value={v.packages} onChange={set('packages')} />
          <TextField label="Weight (kg)" type="number" min={0} step="any" value={v.weight_kg} onChange={set('weight_kg')} />
        </div>
      </Panel>
      {!inbound && (
        <Panel title="Delivery">
          <div className={f.grid}>
            <TextField label="Deliver to (address or directions; empty = on file)" maxLength={255} value={v.to_address} onChange={set('to_address')} />
            <TextField label="Receiver's phone (empty = on file)" maxLength={30} value={v.to_phone} onChange={set('to_phone')} />
            <LongText label="Notes" className={f.spanAll} maxLength={500} value={v.notes} onChange={set('notes')} />
          </div>
        </Panel>
      )}
      <div className={s.foot}>
        <Button onClick={() => (history.length > 1 ? history.back() : navigate('/office/waybills'))}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Preparing…' : 'Prepare waybill'}</Button>
      </div>
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}
