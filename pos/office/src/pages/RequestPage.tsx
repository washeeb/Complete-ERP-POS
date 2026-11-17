import {
  Badge, Button, Combobox, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Field, Input, Link, makeStyles, MessageBar, MessageBarBody,
  Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkCircleRegular, DismissCircleRegular, PrintRegular, ProhibitedRegular, VehicleTruckProfileRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type Option as Choice, type RequestDetail } from '../api'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { REQUEST_STATUS } from './RequestsPage'

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
  send: { padding: '8px 0', borderTop: `1px solid ${tokens.colorNeutralStroke3}`, display: 'grid', gap: '6px 10px', alignItems: 'center',
    gridTemplateColumns: 'minmax(0, 1fr) 120px' },
  own: { gridColumn: '1 / -1', minWidth: 0 },
  box: { width: '100%', minWidth: 0 },
})

const END = { style: { justifyContent: 'flex-end' } }

/** Who must act next, in words. */
export function nextStep(r: Pick<RequestDetail, 'waiting_for' | 'status'>): string | null {
  switch (r.waiting_for) {
    case 'requester': return 'Waiting for a manager of the asking branch (not the person who asked) to approve.'
    case 'supplier': return 'Approved by the asking side. Waiting for a manager of the supplying branch to approve.'
    case 'supply': return 'Waiting for the supplying branch to send it.'
    default: return r.status === 'supplied' ? 'Sent: the request closes when the transfer is received.' : null
  }
}

/**
 * One stock request (release 3): for which warehouse, asked of which, who asked, approved and sent it, the lines (asked
 * for and sent) and its transfer; and what may be done next — approve (between industries, each side in turn), send
 * (what can be spared; between industries, which of your own products), reject or cancel with a reason; print it.
 */
export function RequestPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [r, setR] = useState<RequestDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<'approve' | 'supply' | 'reject' | 'cancel' | null>(null)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.request(id).then((x) => { setR(x); onTitle(`Stock request ${x.number}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !r) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!r) return <div className={page.page}><Spinner label="Opening the request…" /></div>
  const finish = (next: RequestDetail, text: string) => { setR(next); setDialog(null); setError(''); setDone(text) }
  const next = nextStep(r)

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/requests'))}>Stock requests</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          Stock request {r.number}
          <Badge appearance="tint" color={REQUEST_STATUS[r.status] ?? 'informative'}>{r.status_label}</Badge>
          {r.cross && <Badge appearance="outline">Between industries</Badge>}
        </h1>
        <div className={s.actions}>
          {r.actions.approve && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => setDialog('approve')}>Approve</Button>}
          {r.actions.supply && <Button appearance="primary" icon={<VehicleTruckProfileRegular />} onClick={() => setDialog('supply')}>Send</Button>}
          {r.actions.reject && <Button icon={<ProhibitedRegular />} onClick={() => setDialog('reject')}>Reject</Button>}
          {r.actions.cancel && <Button icon={<DismissCircleRegular />} onClick={() => setDialog('cancel')}>Cancel request</Button>}
          <Button icon={<PrintRegular />} onClick={() => window.open(r.actions.print, '_blank', 'noopener')}>Print</Button>
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {next && !done && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>{next}</MessageBarBody></MessageBar>}

      <Panel title="Details">
        <div className={s.facts}>
          {Object.entries(r.facts).map(([k, v]) => <div key={k}><Text size={200} block className={s.muted}>{k}</Text><Text weight="semibold">{v}</Text></div>)}
          {r.transfer && (
            <div><Text size={200} block className={s.muted}>Transfer</Text>
              <Link onClick={() => navigate(`/office/transfers/${r.transfer!.id}`)}>{r.transfer.number}</Link> <Text size={200} className={s.muted}>{r.transfer.status_label}</Text></div>
          )}
        </div>
        {r.note && <Text block style={{ marginTop: 10 }}>{r.note}</Text>}
      </Panel>
      <Panel title="Lines">
        <Table size="small" aria-label="Lines">
          <TableHeader><TableRow>
            <TableHeaderCell>Product</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Asked for</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Sent</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>{r.lines.map((l) => (
            <TableRow key={l.id}>
              <TableCell><Text weight="semibold">{l.name}</Text><Text block size={200} font="monospace" className={s.muted}>{l.sku}</Text></TableCell>
              <TableCell className={s.end}>{l.qty} {l.unit}</TableCell>
              <TableCell className={s.end}>{l.sent === null ? '—' : <Text weight="semibold" style={{ color: Number(l.sent) < Number(l.qty) ? 'var(--zx-warn)' : undefined }}>{l.sent} {l.unit}</Text>}</TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      {dialog === 'approve' && <Simple title="Approve" text={r.actions.approve_text} go="Approve"
        onClose={() => setDialog(null)} run={() => api.requestAction(r.id, 'approve')} onDone={(n) => finish(n, 'Approved.')} />}
      {dialog === 'reject' && <Simple title="Reject the request" text="Nothing is sent; the request stays on record as rejected, with your reason." go="Reject it" reason
        onClose={() => setDialog(null)} run={(reason) => api.requestAction(r.id, 'reject', { reason })} onDone={(n) => finish(n, 'Rejected.')} />}
      {dialog === 'cancel' && <Simple title="Cancel the request" text="Nothing is sent; the request stays on record as cancelled, with your reason." go="Cancel it" reason
        onClose={() => setDialog(null)} run={(reason) => api.requestAction(r.id, 'cancel', { reason })} onDone={(n) => finish(n, 'Cancelled.')} />}
      {dialog === 'supply' && <Supply r={r} onClose={() => setDialog(null)} onDone={(n) => finish(n, `Sent: transfer ${n.transfer?.number ?? ''} is on its way.`)} />}
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}

function Simple({ title, text, go, reason: asks, onClose, run, onDone }: {
  title: string; text: string; go: string; reason?: boolean; onClose: () => void; run: (reason?: string) => Promise<RequestDetail>; onDone: (r: RequestDetail) => void
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

/** Between industries: which of the supplier's own products is sent for a line (one base unit for one base unit). */
function OwnProduct({ requestId, line, value, onChange }: { requestId: string; line: string; value: Choice | null; onChange: (c: Choice | null) => void }) {
  const s = useStyles()
  const [text, setText] = useState(value?.label ?? '')
  const [found, setFound] = useState<Choice[]>([])
  useEffect(() => {
    if (text.trim().length < 2 || text === value?.label) return
    const ctl = new AbortController()
    const t = setTimeout(() => api.requestProducts(requestId, text.trim(), ctl.signal).then(setFound).catch(() => undefined), 250)
    return () => { clearTimeout(t); ctl.abort() }
  }, [text, value, requestId])
  return (
    <Field label={`Your product for ${line}`} required className={s.own}>
      <Combobox className={s.box} input={{ className: s.box }} value={text} selectedOptions={value ? [value.id] : []} placeholder="Name or SKU"
        onChange={(e) => setText(e.target.value)} onBlur={() => setText(value?.label ?? '')}
        onOptionSelect={(_, d) => { const c = found.find((x) => x.id === d.optionValue) ?? null; onChange(c); setText(c?.label ?? '') }}>
        {found.map((c) => <Option key={c.id} value={c.id}>{c.label}</Option>)}
        {found.length === 0 && <Option disabled value="">{text.trim().length < 2 ? 'Type at least two letters' : 'Nothing matches'}</Option>}
      </Combobox>
    </Field>
  )
}

/** Send: what can be spared per line (filled with what was asked; 0 for none) and, between industries, your own product for each. */
function Supply({ r, onClose, onDone }: { r: RequestDetail; onClose: () => void; onDone: (r: RequestDetail) => void }) {
  const s = useStyles()
  const [qty, setQty] = useState<Record<string, string>>(() => Object.fromEntries(r.lines.map((l) => [l.id, l.qty])))
  const [own, setOwn] = useState<Record<string, Choice | null>>({})
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const sending = r.lines.filter((l) => Number(qty[l.id]) > 0)
  const ready = r.lines.every((l) => (qty[l.id] ?? '').trim() !== '' && Number(qty[l.id]) >= 0) && sending.length > 0
    && (!r.cross || sending.every((l) => own[l.id]))
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 620 }}>
        <DialogBody>
          <DialogTitle>Send</DialogTitle>
          <DialogContent>
            <Text block size={200} className={s.muted} style={{ marginBottom: 6 }}>
              Creates the transfer and takes the goods out of your warehouse now. Enter 0 for anything you cannot spare.
              {r.cross && ' Between industries, choose which of your own products you send for each item (one base unit for one base unit).'}
            </Text>
            {r.lines.map((l) => (
              <div key={l.id} className={s.send}>
                <Text>{l.sku} · {l.name} <Text size={200} className={s.muted}>(asked {l.qty} {l.unit})</Text></Text>
                <Input aria-label={`Send: ${l.name}`} type="number" min={0} step="any" value={qty[l.id] ?? ''} style={{ minWidth: 0 }}
                  onChange={(_, d) => setQty({ ...qty, [l.id]: d.value })} contentAfter={<Text size={200}>{l.unit}</Text>} />
                {r.cross && Number(qty[l.id]) > 0 && <OwnProduct requestId={r.id} line={l.name} value={own[l.id] ?? null} onChange={(c) => setOwn({ ...own, [l.id]: c })} />}
              </div>
            ))}
            {sending.length === 0 && <Text block size={200} style={{ color: 'var(--zx-warn)', marginTop: 6 }}>Send at least one item, or reject the request.</Text>}
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 8 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Back</Button>
            <Button appearance="primary" disabled={busy || !ready} onClick={() => {
              setBusy(true)
              setError('')
              const lines = Object.fromEntries(r.lines.map((l) => [l.id, { qty: qty[l.id], send_product_id: own[l.id]?.id ?? null }]))
              api.requestAction(r.id, 'supply', { lines }).then(onDone).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Sending…' : 'Send'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
