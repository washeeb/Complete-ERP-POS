import {
  Badge, Button, Field, Input, Link, makeStyles, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner,
  Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import {
  ArrowLeftRegular, ArrowRepeatAllRegular, BoxCheckmarkRegular, CheckmarkCircleRegular, ClipboardTaskRegular, DismissCircleRegular, DocumentTextRegular,
  EditRegular, MoreHorizontalRegular, SendRegular, PrintRegular, ArrowUndoRegular, ArchiveRegular,
} from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type PoAction, type PoActionResult, type PoDetail, type PoReceiveLine } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, Tags, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles, when } from '../list/ListParts'
import { navigate } from '../nav'
import { appPathFor } from '../paths'
import { PO_TONE } from './PurchaseOrdersPage'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '8px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  tiles: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: '10px', margin: '12px 0' },
  tile: { padding: '12px 14px', borderRadius: tokens.borderRadiusLarge, backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}` },
  tileValue: { display: 'block', fontSize: '20px', fontWeight: tokens.fontWeightBold, fontVariantNumeric: 'tabular-nums' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '10px 18px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  one: { display: 'grid', gap: '10px' },
  recv: { display: 'grid', gap: '8px 10px', gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', padding: '10px 0', borderBottom: `1px solid ${tokens.colorNeutralStroke2}`,
    '@media (max-width: 700px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' } },
  item: { gridColumn: '1 / -1', fontWeight: tokens.fontWeightSemibold },
})

const END = { style: { justifyContent: 'flex-end' } }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
type Dialogs = 'submit' | 'approve' | 'reject' | 'send' | 'copy' | 'confirm_delivered' | 'receive' | 'close_short' | 'cancel'
type Run = (body: Record<string, unknown>) => Promise<PoActionResult>

/**
 * One purchase order (release 5): who it is for and where it goes, its lines (ordered and received), who made, approved
 * and sent it and what the supplier answered; and every button of the current page — Submit for approval, Approve, Send
 * back, Send to supplier, Order again, Confirm delivered (services), Receive goods (with batch, expiry and the pack
 * code, and serial numbers), Close short, Cancel, Goods received, Print and Edit.
 */
export function PurchaseOrderPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [po, setPo] = useState<PoDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<Dialogs | null>(null)
  const [done, setDone] = useState<{ text: string; intent: 'success' | 'warning'; open?: string | null } | null>(null)
  const load = useCallback(() => {
    api.purchaseOrder(id).then((x) => { setPo(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !po) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!po) return <div className={page.page}><Spinner label="Opening the order…" /></div>
  const m = (v: number) => money(v, po.currency, po.exponent)
  const a = po.actions
  const run = (action: PoAction, text: string): Run => (body) => api.purchaseOrderAction(po.id, action, body).then((r) => {
    setPo(r.order)
    setDialog(null)
    if (r.copy) { navigate(`/office/purchase-orders/${r.copy.id}/edit`); return r }
    if (r.receipt) {
      setDone(r.receipt.held
        ? { intent: 'warning', text: `${r.receipt.number} is waiting for a manager: more than the over-delivery tolerance was received; nothing is in stock until a manager accepts it.` }
        : { intent: 'success', text: `${r.receipt.number} posted. The goods are in stock.` })
    } else setDone({ intent: 'success', text: r.open ? 'Ready in WhatsApp: open it and press send.' : text, open: r.open })
    return r
  })
  const more = [
    a.copy && { key: 'copy', icon: <ArrowRepeatAllRegular />, label: 'Order again', on: () => setDialog('copy') },
    a.close_short && { key: 'close', icon: <ArchiveRegular />, label: 'Close short', on: () => setDialog('close_short') },
    a.cancel && { key: 'cancel', icon: <DismissCircleRegular />, label: 'Cancel order', on: () => setDialog('cancel') },
    a.receipts && { key: 'receipts', icon: <DocumentTextRegular />, label: 'Goods received', on: () => navigate(appPathFor(a.receipts!)) },
  ].filter(Boolean) as { key: string; icon: ReactNode; label: string; on: () => void }[]

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/purchase-orders'))}>Purchase orders</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{po.number}</span>
          <Badge appearance="tint" color={PO_TONE[po.status] ?? 'informative'}>{po.status_label}</Badge>
        </h1>
        <div className={s.actions}>
          {a.submit && <Button appearance="primary" icon={<SendRegular />} onClick={() => setDialog('submit')}>Submit for approval</Button>}
          {a.approve && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => setDialog('approve')}>Approve</Button>}
          {a.reject && <Button icon={<ArrowUndoRegular />} onClick={() => setDialog('reject')}>Send back</Button>}
          {a.send && <Button appearance={a.send.again ? 'secondary' : 'primary'} icon={<SendRegular />} onClick={() => setDialog('send')}>{a.send.again ? 'Send again' : 'Send to supplier'}</Button>}
          {a.receive && <Button appearance="primary" icon={<BoxCheckmarkRegular />} onClick={() => setDialog('receive')}>Receive goods</Button>}
          {a.confirm_delivered && <Button icon={<ClipboardTaskRegular />} onClick={() => setDialog('confirm_delivered')}>Confirm delivered</Button>}
          <Button icon={<PrintRegular />} onClick={() => window.open(a.print, '_blank', 'noopener')}>Print</Button>
          {a.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/purchase-orders/${po.id}/edit`)}>Edit</Button>}
          {more.length > 0 && (
            <Menu>
              <MenuTrigger disableButtonEnhancement><Button icon={<MoreHorizontalRegular />}>More</Button></MenuTrigger>
              <MenuPopover><MenuList>{more.map((x) => <MenuItem key={x.key} icon={x.icon as never} onClick={x.on}>{x.label}</MenuItem>)}</MenuList></MenuPopover>
            </Menu>
          )}
        </div>
      </div>
      {done && (
        <MessageBar intent={done.intent} style={{ marginBottom: 12 }}><MessageBarBody>
          {done.text} {done.open && <Link href={done.open} target="_blank" rel="noopener">Open WhatsApp</Link>}
        </MessageBarBody></MessageBar>
      )}
      {po.rejected_reason && po.status === 'draft' && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>Sent back because: {po.rejected_reason}</MessageBarBody></MessageBar>}
      {po.budget_note && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>Over budget: {po.budget_note}</MessageBarBody></MessageBar>}

      <div className={s.tiles}>
        <Tile label="Total" value={m(po.total)} />
        <Tile label="Before tax" value={m(po.net)} />
        <Tile label="Tax" value={m(po.tax)} />
        <Tile label="Expected" value={day(po.expected_on)} />
      </div>

      <Panel title="Details">
        <div className={s.facts}>
          <Fact label="Supplier">{po.supplier.open ? <Link onClick={() => navigate(po.supplier.open!)}>{po.supplier.name}</Link> : po.supplier.name}</Fact>
          <Fact label="Deliver to">{po.warehouse ?? '—'}</Fact>
          <Fact label="Created by">{po.created_by ?? '—'}{po.created_at && <Text block size={200} className={s.muted}>{when(po.created_at)}</Text>}</Fact>
          <Fact label="Approved by">{po.approved_by ?? '—'}</Fact>
          {po.sent !== null && <Fact label="Sent to supplier">{po.sent ? `${when(po.sent.at)} · ${po.sent.via} to ${po.sent.to}` : 'Not sent yet'}
            {po.sent && po.sent.by && <Text block size={200} className={s.muted}>by {po.sent.by}</Text>}</Fact>}
          {po.confirmed !== null && <Fact label="Supplier confirmed">{po.confirmed ? `Delivery on ${day(po.confirmed.promised_on)} (confirmed ${when(po.confirmed.at)})` : 'Not yet'}
            {po.confirmed && po.confirmed.reply && <Text block size={200} className={s.muted}>{po.confirmed.reply}</Text>}</Fact>}
          {po.dispatched && <Fact label="On the way">{when(po.dispatched.at)}{po.dispatched.ref ? ` · ${po.dispatched.ref}` : ''}</Fact>}
          {po.department && <Fact label="Department">{po.department}</Fact>}
          {po.agreement && <Fact label="Against agreement"><Link onClick={() => navigate(appPathFor(po.agreement!.open))}>{po.agreement.number}</Link></Fact>}
          {po.close_reason && <Fact label="Closed because">{po.close_reason}</Fact>}
        </div>
        {po.note && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{po.note}</Text>}
      </Panel>

      <Panel title="Lines">
        <Table size="small" aria-label="Lines">
          <TableHeader><TableRow>
            <TableHeaderCell>Item</TableHeaderCell><TableHeaderCell>Ordered</TableHeaderCell><TableHeaderCell>Received</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Cost</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Total incl. tax</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>{po.lines.map((l, i) => (
            <TableRow key={`${l.sku ?? l.name}-${i}`}>
              <TableCell>{l.sku && <Text font="monospace" size={200} className={s.muted}>{l.sku} </Text>}{l.name}
                {l.service && <Text block size={200} className={s.muted}>Service / non-stock{l.department ? ` · ${l.department}` : ''}</Text>}</TableCell>
              <TableCell>{l.ordered}</TableCell><TableCell>{l.received}</TableCell>
              <TableCell className={s.end}>{m(l.cost)}</TableCell><TableCell className={s.end}>{m(l.total)}</TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      {dialog === 'submit' && <Confirm title={`Submit ${po.number} for approval?`} go="Submit" text="Someone who approves orders then checks it." run={() => run('submit', 'Submitted for approval.')({})} onClose={() => setDialog(null)} />}
      {dialog === 'approve' && <Confirm title="Approve" go="Approve" text={`Approve ${po.number} for ${m(po.total)} from ${po.supplier.name}?`} run={() => run('approve', 'Approved.')({})} onClose={() => setDialog(null)} />}
      {dialog === 'copy' && <Confirm title="Order again" go="Make the new draft" text="A new draft with the same supplier, store and lines, at the latest costs. Change it before submitting."
        run={() => run('copy', '')({})} onClose={() => setDialog(null)} />}
      {dialog === 'reject' && <Reason title={`Send ${po.number} back to draft`} go="Send back" run={(reason) => run('reject', 'Sent back to draft.')({ reason })} onClose={() => setDialog(null)} />}
      {dialog === 'close_short' && <Reason title={`Close ${po.number} short`} go="Close" about="Nothing more is expected from the supplier; the rest of the order lapses."
        run={(reason) => run('close_short', 'Closed.')({ reason })} onClose={() => setDialog(null)} />}
      {dialog === 'cancel' && <Reason title={`Cancel ${po.number}`} go="Cancel order" run={(reason) => run('cancel', 'Cancelled.')({ reason })} onClose={() => setDialog(null)} />}
      {dialog === 'send' && a.send && <Send po={po} onClose={() => setDialog(null)} run={run('send', 'Sent to the supplier. See Messages → Message log if it does not arrive.')} />}
      {dialog === 'confirm_delivered' && a.confirm_delivered && <ConfirmDelivered po={po} onClose={() => setDialog(null)} run={run('confirm_delivered', 'Delivery confirmed.')} />}
      {dialog === 'receive' && a.receive && <Receive po={po} onClose={() => setDialog(null)} run={run('receive', 'Received.')} />}
    </div>
  )
}

function Tile({ label, value }: { label: string; value: string }) {
  const s = useStyles()
  return <div className={s.tile}><Text size={200} className={s.muted}>{label}</Text><span className={s.tileValue}>{value}</span></div>
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text weight="semibold">{children}</Text></div>
}

function Confirm({ title, go, text, run, onClose }: { title: string; go: string; text: string; run: () => Promise<unknown>; onClose: () => void }) {
  return <Ask title={title} go={go} ready onClose={onClose} run={run}><Text>{text}</Text></Ask>
}

function Reason({ title, go, about, run, onClose }: { title: string; go: string; about?: string; run: (reason: string) => Promise<unknown>; onClose: () => void }) {
  const [reason, setReason] = useState('')
  return (
    <Ask title={title} go={go} ready={reason.trim().length >= 3} onClose={onClose} run={() => run(reason.trim())}>
      {about && <Text block style={{ marginBottom: 10 }}>{about}</Text>}
      <TextField label="Reason" required maxLength={255} value={reason} onChange={setReason} />
    </Ask>
  )
}

function Send({ po, onClose, run }: { po: PoDetail; onClose: () => void; run: Run }) {
  const s = useStyles()
  const o = po.actions.send!
  const [channel, setChannel] = useState(o.channels[0]?.id ?? 'email')
  const [to, setTo] = useState(o.email ?? o.phone ?? '')
  const [note, setNote] = useState('')
  return (
    <Ask title={o.again ? 'Send again' : 'Send to supplier'} go="Send" ready={!!channel && !!to.trim()} onClose={onClose} width={560}
      run={() => run({ channel, to: to.trim(), note: note.trim() || null })}>
      <Text block style={{ marginBottom: 10 }}>The supplier gets the order as a PDF (by WhatsApp, as a download link valid for 30 days).</Text>
      <div className={s.one}>
        <Field label="How" required>
          <RadioGroup value={channel} onChange={(_, d) => { setChannel(d.value); setTo(d.value === 'email' ? (o.email ?? '') : (o.phone ?? '')) }}>
            {o.channels.map((c) => <Radio key={c.id} value={c.id} label={c.label} />)}
          </RadioGroup>
        </Field>
        <TextField label={channel === 'email' ? 'E-mail address' : 'WhatsApp number'} required maxLength={160} value={to} onChange={setTo} />
        <LongText label="Message to the supplier (optional)" maxLength={500} value={note} onChange={setNote} />
      </div>
    </Ask>
  )
}

function ConfirmDelivered({ po, onClose, run }: { po: PoDetail; onClose: () => void; run: Run }) {
  const s = useStyles()
  const lines = po.actions.confirm_delivered!.lines
  const [qtys, setQtys] = useState<Record<string, string>>(() => Object.fromEntries(lines.map((l) => [l.id, l.qty])))
  const [note, setNote] = useState('')
  return (
    <Ask title="Confirm delivered" go="Confirm" ready={Object.values(qtys).some((q) => Number(q) > 0)} onClose={onClose} width={600} run={() => run({ qtys, note: note.trim() || null })}>
      <Text block style={{ marginBottom: 10 }}>For services done and non-stock items handed over: nothing goes into stock. The bill is then matched to what you confirm here.</Text>
      <div className={s.one}>
        {lines.map((l) => <TextField key={l.id} label={l.item} type="number" min={0} step="any" value={qtys[l.id] ?? ''} onChange={(x) => setQtys({ ...qtys, [l.id]: x })} />)}
        <TextField label="Note (e.g. job card number)" maxLength={255} value={note} onChange={setNote} />
      </div>
    </Ask>
  )
}

type RecvLine = PoReceiveLine & { got: string; bin_id: string; batch_no: string; expires_on: string; serials: string[]; pack: string }

/** Receive goods: what came now on each line, the bin, and for batched medicines the batch and expiry (the pack code fills both). */
function Receive({ po, onClose, run }: { po: PoDetail; onClose: () => void; run: Run }) {
  const s = useStyles()
  const r = po.actions.receive!
  const [ref, setRef] = useState('')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState<RecvLine[]>(() => r.lines.map((l) => ({ ...l, got: l.qty, bin_id: '', batch_no: '', expires_on: '', serials: [], pack: '' })))
  const set = (i: number, patch: Partial<RecvLine>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const scan = (i: number, code: string) => {
    if (!code.trim()) return
    api.poPackCode(code.trim()).then((c) => set(i, { ...(c.batch ? { batch_no: c.batch } : {}), ...(c.expires_on ? { expires_on: c.expires_on } : {}) })).catch(() => undefined)
  }
  const ready = lines.some((l) => Number(l.got) > 0) && lines.every((l) => !(Number(l.got) > 0 && l.batched && !l.batch_no.trim()))
  return (
    <Ask title={`Receive goods: ${po.number}`} go="Receive" ready={ready} onClose={onClose} width={900}
      run={() => run({ supplier_ref: ref.trim() || null, note: note.trim() || null, lines: lines.map((l) => ({
        purchase_order_line_id: l.id, qty: l.got || '0', bin_id: l.bin_id || null, batch_no: l.batched ? l.batch_no.trim() || null : null,
        expires_on: l.batched ? l.expires_on || null : null, serials: l.serialised ? l.serials : null })) })}>
      <div className={s.one}>
        <TextField label="Supplier delivery note / invoice no." maxLength={60} value={ref} onChange={setRef} />
        {lines.map((l, i) => (
          <div key={l.id} className={s.recv} role="group" aria-label={l.item}>
            <Text className={s.item}>{l.item}</Text>
            <TextField label="Received now" required type="number" min={0} step="any" value={l.got} onChange={(x) => set(i, { got: x })} />
            {r.bins && <Choose label="Into bin" options={r.bins} value={l.bin_id} onChange={(x) => set(i, { bin_id: x })} placeholder="Receiving dock" />}
            {l.batched && (
              <>
                <Field label="Scan the pack code (optional)" hint="Fills in the batch and expiry; check them against the box.">
                  <Input placeholder="GS1 DataMatrix on the box" value={l.pack} onChange={(_, d) => set(i, { pack: d.value })} onBlur={() => scan(i, l.pack)}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); scan(i, l.pack) } }} />
                </Field>
                <TextField label="Batch no." required maxLength={60} value={l.batch_no} onChange={(x) => set(i, { batch_no: x })} />
                <TextField label="Expiry" type="date" value={l.expires_on} onChange={(x) => set(i, { expires_on: x })} />
              </>
            )}
            {l.serialised && <Tags label="Serial / IMEI numbers (scan each)" className={s.item} value={l.serials} onChange={(x) => set(i, { serials: x })} placeholder="Scan, then Enter" />}
          </div>
        ))}
        <LongText label="Note" maxLength={2000} value={note} onChange={setNote} />
      </div>
    </Ask>
  )
}
