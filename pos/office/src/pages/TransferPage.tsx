import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option,
  Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, Textarea, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, BoxCheckmarkRegular, DismissCircleRegular, EditRegular, PrintRegular, VehicleTruckProfileRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type TransferDetail } from '../api'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { TRANSFER_STATUS } from './TransfersPage'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '10px 18px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  arrived: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 90px 110px', gap: '8px', alignItems: 'center', padding: '6px 0', borderTop: `1px solid ${tokens.colorNeutralStroke3}` },
})

const END = { style: { justifyContent: 'flex-end' } }

/**
 * One transfer (release 3): from where to where, who made, dispatched and received it, each line sent and received,
 * and what may be done next — edit the draft, dispatch it (the goods leave the sending warehouse), receive what actually
 * arrived (into a bin, with a note), cancel a draft; print it, or make its waybill.
 */
export function TransferPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [t, setT] = useState<TransferDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<'dispatch' | 'receive' | 'cancel' | null>(null)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.transfer(id).then((x) => { setT(x); onTitle(`Transfer ${x.number}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !t) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!t) return <div className={page.page}><Spinner label="Opening the transfer…" /></div>
  const finish = (next: TransferDetail, text: string) => { setT(next); setDialog(null); setError(''); setDone(text) }

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/transfers'))}>Transfers</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          Transfer {t.number}
          <Badge appearance="tint" color={TRANSFER_STATUS[t.status] ?? 'informative'}>{t.status_label}</Badge>
          {t.difference !== null && (t.difference ? <Badge appearance="tint" color="danger">Arrived different from sent</Badge> : <Badge appearance="tint" color="success">Arrived as sent</Badge>)}
        </h1>
        <div className={s.actions}>
          {t.actions.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/transfers/${t.id}/edit`)}>Edit</Button>}
          {t.actions.dispatch && <Button appearance="primary" icon={<VehicleTruckProfileRegular />} onClick={() => setDialog('dispatch')}>Dispatch</Button>}
          {t.actions.receive && <Button appearance="primary" icon={<BoxCheckmarkRegular />} onClick={() => setDialog('receive')}>Receive</Button>}
          {t.actions.cancel && <Button icon={<DismissCircleRegular />} onClick={() => setDialog('cancel')}>Cancel</Button>}
          <Button icon={<PrintRegular />} onClick={() => window.open(t.actions.print, '_blank', 'noopener')}>Print</Button>
          <Button icon={<VehicleTruckProfileRegular />} onClick={() => navigate(t.actions.waybill)}>{t.actions.waybill_label}</Button>
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {t.difference && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>What arrived differs from what was sent: it is in Sales → Review for someone to look into.</MessageBarBody></MessageBar>}

      <Panel title="Details">
        <div className={s.facts}>{Object.entries(t.facts).map(([k, v]) => <div key={k}><Text size={200} block className={s.muted}>{k}</Text><Text weight="semibold">{v}</Text></div>)}</div>
        {t.note && <Text block style={{ marginTop: 10 }}>{t.note}</Text>}
      </Panel>
      <Panel title="Lines">
        <Table size="small" aria-label="Lines">
          <TableHeader><TableRow>
            <TableHeaderCell>Product</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Sent</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Received</TableHeaderCell><TableHeaderCell>Serial numbers</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>{t.lines.map((l) => (
            <TableRow key={l.id}>
              <TableCell><Text weight="semibold">{l.name}</Text><Text block size={200} font="monospace" className={s.muted}>{l.sku}</Text></TableCell>
              <TableCell className={s.end}>{l.sent} {l.unit}</TableCell>
              <TableCell className={s.end}>{l.received === null ? '—' : <Text weight="semibold" style={{ color: l.received !== l.sent ? 'var(--zx-bad)' : undefined }}>{l.received} {l.unit}</Text>}</TableCell>
              <TableCell><Text size={200}>{l.serials.join(', ') || '—'}</Text></TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      {dialog === 'dispatch' && <Simple title="Dispatch" text="Takes the goods out of the sending warehouse now. They show as in transit until received." go="Dispatch"
        onClose={() => setDialog(null)} run={() => api.transferAction(t.id, 'dispatch')} onDone={(n) => finish(n, 'Dispatched: the goods are in transit.')} />}
      {dialog === 'cancel' && <Simple title="Cancel the transfer" text="Nothing moves; the draft stays on record as cancelled, with your reason." go="Cancel it" reason
        onClose={() => setDialog(null)} run={(reason) => api.transferAction(t.id, 'cancel', { reason })} onDone={(n) => finish(n, 'Cancelled.')} />}
      {dialog === 'receive' && <Receive t={t} onClose={() => setDialog(null)} onDone={(n) => finish(n, n.difference ? 'Received, with a difference: it is in the review queue.' : 'Received as sent.')} />}
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}

function Simple({ title, text, go, reason: asks, onClose, run, onDone }: {
  title: string; text: string; go: string; reason?: boolean; onClose: () => void; run: (reason?: string) => Promise<TransferDetail>; onDone: (t: TransferDetail) => void
}) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 480 }}>
        <DialogBody>
          <DialogTitle>{title}</DialogTitle>
          <DialogContent>
            <Text block>{text}</Text>
            {asks && <Field label="Reason" required style={{ marginTop: 10 }}><Input value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} /></Field>}
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 8 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Back</Button>
            <Button appearance="primary" disabled={busy || (asks && !reason.trim())} onClick={() => {
              setBusy(true)
              setError('')
              run(asks ? reason.trim() : undefined).then(onDone).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Working…' : go}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}

/** What actually arrived, line by line (filled with what was sent), the bin it goes into, and a note on any damage. */
function Receive({ t, onClose, onDone }: { t: TransferDetail; onClose: () => void; onDone: (t: TransferDetail) => void }) {
  const s = useStyles()
  const [qty, setQty] = useState<Record<string, string>>(() => Object.fromEntries(t.lines.map((l) => [l.id, l.sent])))
  const [bin, setBin] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const different = t.lines.some((l) => Number(qty[l.id]) !== Number(l.sent))
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 600 }}>
        <DialogBody>
          <DialogTitle>Receive</DialogTitle>
          <DialogContent>
            <Text block size={200} className={s.muted} style={{ marginBottom: 6 }}>Count what arrived. A line that differs from what was sent goes to the review queue.</Text>
            {t.lines.map((l) => (
              <div key={l.id} className={s.arrived}>
                <Text>{l.sku} · {l.name}</Text>
                <Text size={200} className={s.muted} style={{ textAlign: 'right' }}>sent {l.sent} {l.unit}</Text>
                <Input aria-label={`Arrived: ${l.name}`} type="number" min={0} step="any" value={qty[l.id] ?? ''} style={{ minWidth: 0 }}
                  onChange={(_, d) => setQty({ ...qty, [l.id]: d.value })} contentAfter={<Text size={200}>{l.unit}</Text>} />
              </div>
            ))}
            {t.bins.length > 0 && (
              <Field label="Into bin" style={{ marginTop: 10 }}>
                <Dropdown placeholder="Receiving dock" value={t.bins.find((b) => b.id === bin)?.label ?? ''} selectedOptions={bin ? [bin] : []} onOptionSelect={(_, d) => setBin(d.optionValue ?? '')}>
                  <Option value="">Receiving dock</Option>{t.bins.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
                </Dropdown>
              </Field>
            )}
            <Field label="Note (e.g. what was damaged)" style={{ marginTop: 10 }}><Textarea value={note} maxLength={2000} onChange={(_, d) => setNote(d.value)} /></Field>
            {different && <Text block size={200} style={{ color: 'var(--zx-warn)', marginTop: 6 }}>Different from what was sent: say why in the note.</Text>}
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 8 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Back</Button>
            <Button appearance="primary" disabled={busy || t.lines.some((l) => (qty[l.id] ?? '').trim() === '')} onClick={() => {
              setBusy(true)
              setError('')
              api.transferAction(t.id, 'receive', { received: qty, to_bin_id: bin || null, note: note.trim() || null }).then(onDone)
                .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Receiving…' : 'Receive'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
