import {
  Badge, Button, Field, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkCircleRegular, DismissRegular, PrintRegular, VehicleTruckProfileRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type WaybillDoc } from '../api'
import { Ask } from '../form/Ask'
import { LongText, TextField } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { WB_TONE } from './WaybillsPage'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '10px 18px' },
  end: { textAlign: 'right' },
  one: { display: 'grid', gap: '10px' },
})

const END = { style: { justifyContent: 'flex-end' } }

/**
 * One waybill (part 17): what it carries and for which document, from and to, vehicle and driver, packages and weight,
 * who prepared, dispatched and received it, the proof of delivery, and the goods. Print, Dispatch (driver and vehicle),
 * Mark delivered (from the signed copy brought back, with a photo of it; the receiver can also confirm by scanning the
 * QR code on the printed waybill), Cancel.
 */
export function WaybillPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [w, setW] = useState<WaybillDoc | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<'dispatch' | 'deliver' | 'cancel' | null>(null)
  const [v, setV] = useState({ driver_name: '', driver_phone: '', vehicle_no: '', transporter: '', received_by: '', phone: '', note: '', reason: '' })
  const [pod, setPod] = useState<File | null>(null)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.waybill(id).then((x) => { setW(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !w) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!w) return <div className={page.page}><Spinner label="Opening the waybill…" /></div>
  const a = w.actions
  const open = (x: 'dispatch' | 'deliver' | 'cancel') => {
    setDone('')
    setPod(null)
    setV({ driver_name: a.dispatch?.driver_name ?? '', driver_phone: a.dispatch?.driver_phone ?? '', vehicle_no: a.dispatch?.vehicle_no ?? '', transporter: a.dispatch?.transporter ?? '',
      received_by: '', phone: '', note: '', reason: '' })
    setDialog(x)
  }
  const finish = (r: { message: string; waybill: WaybillDoc }) => { setW(r.waybill); setDialog(null); setDone(r.message) }

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/waybills'))}>Waybills</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{w.number}</span><Badge appearance="tint" color={WB_TONE[w.status] ?? 'informative'}>{w.status_label}</Badge></h1>
        <div className={s.actions}>
          {a.dispatch && <Button appearance="primary" icon={<VehicleTruckProfileRegular />} onClick={() => open('dispatch')}>Dispatch</Button>}
          {a.deliver && <Button appearance={a.dispatch ? 'secondary' : 'primary'} icon={<CheckmarkCircleRegular />} onClick={() => open('deliver')}>Mark delivered</Button>}
          <Button icon={<PrintRegular />} onClick={() => window.open(a.print, '_blank', 'noopener')}>Print waybill</Button>
          {a.cancel && <Button icon={<DismissRegular />} onClick={() => open('cancel')}>Cancel</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      <section className={s.panel} aria-label="Details">
        <h2 className={s.h2}>Details</h2>
        <div className={s.facts}>
          <Fact label="For">{w.kind}{w.source && ` · ${w.source}`}</Fact>
          <Fact label="Their reference">{w.reference ?? '—'}</Fact>
          <Fact label="From">{w.from ?? '—'}{w.from_address && <Text block size={200} className={s.muted}>{w.from_address}</Text>}</Fact>
          <Fact label="To">{w.to ?? '—'}{w.to_address && <Text block size={200} className={s.muted}>{w.to_address}</Text>}</Fact>
          <Fact label="Vehicle and driver">{w.vehicle ?? '—'}</Fact>
          <Fact label="Packages / weight">{w.packages}</Fact>
          <Fact label="Prepared">{w.prepared || '—'}</Fact>
          <Fact label="Dispatched">{w.dispatched ?? '—'}</Fact>
          <Fact label="Received by">{w.received ?? '—'}</Fact>
          <Fact label="Proof of delivery">{w.pod ? <Link href={w.pod} target="_blank" rel="noopener">Open</Link> : '—'}</Fact>
          {w.pod_note && <Fact label="Receiver's note">{w.pod_note}</Fact>}
          {w.cancel_reason && <Fact label="Cancelled because">{w.cancel_reason}</Fact>}
        </div>
        {w.notes && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{w.notes}</Text>}
      </section>
      <section className={s.panel} aria-label="Goods">
        <h2 className={s.h2}>Goods</h2>
        <Table aria-label="Goods" size="small" style={{ minWidth: 480 }}>
          <TableHeader><TableRow><TableHeaderCell>SKU</TableHeaderCell><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell button={END}>Quantity</TableHeaderCell></TableRow></TableHeader>
          <TableBody>{w.lines.map((l, i) => <TableRow key={i}><TableCell>{l.sku}</TableCell><TableCell>{l.name}</TableCell><TableCell className={s.end}>{l.qty}</TableCell></TableRow>)}</TableBody>
        </Table>
      </section>

      {dialog === 'dispatch' && (
        <Ask title="Dispatch" go="Dispatch" ready onClose={() => setDialog(null)}
          run={() => api.waybillStep(w.id, 'dispatch', { driver_name: v.driver_name, driver_phone: v.driver_phone, vehicle_no: v.vehicle_no, transporter: v.transporter }).then(finish)}>
          <div className={s.one}>
            <TextField label="Driver" maxLength={120} value={v.driver_name} onChange={(x) => setV({ ...v, driver_name: x })} />
            <TextField label="Driver's phone" maxLength={30} value={v.driver_phone} onChange={(x) => setV({ ...v, driver_phone: x })} />
            <TextField label="Vehicle registration" maxLength={30} value={v.vehicle_no} onChange={(x) => setV({ ...v, vehicle_no: x })} />
            <TextField label="Transport company" maxLength={120} value={v.transporter} onChange={(x) => setV({ ...v, transporter: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'deliver' && (
        <Ask title="Mark delivered" go="Mark delivered" ready={v.received_by.trim() !== ''} onClose={() => setDialog(null)}
          run={() => {
            const body = new FormData()
            body.append('received_by', v.received_by)
            if (v.phone) body.append('phone', v.phone)
            if (v.note) body.append('note', v.note)
            if (pod) body.append('pod', pod)
            return api.deliverWaybill(w.id, body).then(finish)
          }}>
          <div className={s.one}>
            <Text>From the signed copy brought back. The receiver can also confirm by scanning the QR code on the printed waybill.</Text>
            <TextField label="Received by" required maxLength={120} value={v.received_by} onChange={(x) => setV({ ...v, received_by: x })} />
            <TextField label="Their phone" maxLength={30} value={v.phone} onChange={(x) => setV({ ...v, phone: x })} />
            <Field label="Signed waybill or photo" hint="A photo (JPEG, PNG, WebP) or a PDF, up to 5 MB.">
              <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(e) => setPod(e.target.files?.[0] ?? null)} />
            </Field>
            <LongText label="Missing or damaged?" maxLength={500} value={v.note} onChange={(x) => setV({ ...v, note: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'cancel' && (
        <Ask title="Cancel the waybill?" go="Cancel the waybill" ready={v.reason.trim() !== ''} onClose={() => setDialog(null)}
          run={() => api.waybillStep(w.id, 'cancel', { reason: v.reason }).then(finish)}>
          <TextField label="Reason" required maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
        </Ask>
      )}
    </div>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text>{children}</Text></div>
}
