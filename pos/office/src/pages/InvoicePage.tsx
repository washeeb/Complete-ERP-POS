import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Link, makeStyles, MessageBar, MessageBarBody, Spinner,
  Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, PrintRegular, ReceiptRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, ApiError, type InvoiceDetail } from '../api'
import { Choose, TextField } from '../form/Fields'
import { ConfirmItsYou } from '../form/ConfirmItsYou'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

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
  form: { display: 'grid', gap: '10px' },
})

const END = { style: { justifyContent: 'flex-end' } }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * One customer invoice (release 4): who it is for, when it is due, what is still owed, its lines and taxes, what
 * settled it (payments, credit notes, till returns) and its credit notes; Print / PDF, and Credit note, which cancels
 * it on the customer's account (goods may come back into a warehouse). An invoice itself never changes.
 */
export function InvoicePage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [inv, setInv] = useState<InvoiceDetail | null>(null)
  const [error, setError] = useState('')
  const [crediting, setCrediting] = useState(false)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.invoice(id).then((x) => { setInv(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !inv) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!inv) return <div className={page.page}><Spinner label="Opening the invoice…" /></div>
  const m = (v: number) => money(v, inv.currency, inv.exponent)

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/invoices'))}>Invoices</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{inv.number}</span>
          <Badge appearance="tint" color={inv.status === 'credited' ? 'subtle' : 'brand'}>{inv.status_label}</Badge>
          {inv.overdue && <Badge appearance="tint" color="danger">Past due</Badge>}
        </h1>
        <div className={s.actions}>
          <Button icon={<PrintRegular />} onClick={() => window.open(inv.actions.print, '_blank', 'noopener')}>Print / PDF</Button>
          {inv.actions.credit && <Button icon={<ReceiptRegular />} onClick={() => setCrediting(true)}>Credit note</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      <div className={s.tiles}>
        <Tile label="Total" value={m(inv.total)} />
        <Tile label="Still owed" value={inv.open ? m(inv.open) : 'Paid'} tone={inv.open ? (inv.overdue ? 'var(--zx-bad)' : 'var(--zx-warn)') : 'var(--zx-good)'} />
        <Tile label="Issued" value={day(inv.issued_on)} />
        <Tile label="Due" value={day(inv.due_on)} tone={inv.overdue ? 'var(--zx-bad)' : undefined} />
      </div>

      <Panel title="Details">
        <div className={s.facts}>
          <Fact label="Customer">
            {inv.customer.open ? <Link onClick={() => navigate(inv.customer.open!)}>{inv.customer.name}</Link> : inv.customer.name}
            {inv.customer.tax_no && <Text block size={200} className={s.muted}>TIN {inv.customer.tax_no}</Text>}
          </Fact>
          <Fact label="Customer ref.">{inv.ref ?? '—'}</Fact>
          <Fact label="Branch">{inv.branch ?? '—'}</Fact>
          <Fact label="Prices">{inv.prices_include_tax ? 'Include tax' : 'Before tax'}</Fact>
        </div>
      </Panel>

      <Panel title="Lines">
        <Table size="small" aria-label="Lines">
          <TableHeader><TableRow>
            <TableHeaderCell>Item</TableHeaderCell><TableHeaderCell>Quantity</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Price</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Line total</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>
            {inv.lines.map((l, i) => (
              <TableRow key={`${l.sku}-${i}`}>
                <TableCell><Text font="monospace" size={200} className={s.muted}>{l.sku}</Text> {l.name}</TableCell>
                <TableCell>{l.qty}</TableCell>
                <TableCell className={s.end}>{m(l.unit_price)}</TableCell>
                <TableCell className={s.end}>{m(l.total)}</TableCell>
              </TableRow>
            ))}
            <TableRow><TableCell colSpan={3} className={s.end}>Before tax</TableCell><TableCell className={s.end}>{m(inv.net)}</TableCell></TableRow>
            {inv.tax_breakdown.map((t) => <TableRow key={t.name}><TableCell colSpan={3} className={s.end}>{t.name}</TableCell><TableCell className={s.end}>{m(t.amount)}</TableCell></TableRow>)}
            <TableRow><TableCell colSpan={3} className={s.end}><Text weight="semibold">Total</Text></TableCell><TableCell className={s.end}><Text weight="semibold">{m(inv.total)}</Text></TableCell></TableRow>
          </TableBody>
        </Table>
      </Panel>

      <Panel title="Settled by">
        {inv.settled.length === 0 ? <Text className={s.muted}>Nothing paid against it yet.</Text> : (
          <Table size="small" aria-label="Settled by">
            <TableHeader><TableRow>
              <TableHeaderCell>What</TableHeaderCell><TableHeaderCell>Number</TableHeaderCell><TableHeaderCell>Date</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Amount</TableHeaderCell>
            </TableRow></TableHeader>
            <TableBody>{inv.settled.map((r, i) => (
              <TableRow key={`${r.number}-${i}`}>
                <TableCell>{r.kind}</TableCell><TableCell>{r.number ?? '—'}</TableCell><TableCell>{day(r.on)}</TableCell><TableCell className={s.end}>{m(r.amount)}</TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </Panel>

      {inv.credit_notes.length > 0 && (
        <Panel title="Credit notes">
          {inv.credit_notes.map((n) => (
            <Text block key={n.number} style={{ marginBottom: 6 }}>
              <Text weight="semibold">{n.number}</Text> · {day(n.on)} · {m(n.total)} · {n.reason}
              <Text className={s.muted}> — {n.goods_returned ? 'goods came back' : 'written off'}, by {n.by}</Text>
            </Text>
          ))}
        </Panel>
      )}

      {crediting && inv.actions.credit && (
        <CreditNote inv={inv} onClose={() => setCrediting(false)} onDone={(next) => { setInv(next); setCrediting(false); setDone('Credit note issued.') }} />
      )}
    </div>
  )
}

function Tile({ label, value, tone }: { label: string; value: string; tone?: string }) {
  const s = useStyles()
  return <div className={s.tile}><Text size={200} className={s.muted}>{label}</Text><span className={s.tileValue} style={tone ? { color: tone } : undefined}>{value}</span></div>
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text weight="semibold">{children}</Text></div>
}

/** Credit note: why, and (optionally) the warehouse the goods come back into. Writing off asks to confirm it's you. */
function CreditNote({ inv, onClose, onDone }: { inv: InvoiceDetail; onClose: () => void; onDone: (next: InvoiceDetail) => void }) {
  const s = useStyles()
  const [reason, setReason] = useState('')
  const [warehouse, setWarehouse] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [confirmUrl, setConfirmUrl] = useState<string | null>(null)
  const go = () => {
    setBusy(true)
    setError('')
    api.creditInvoice(inv.id, reason.trim(), warehouse || null).then(onDone).catch((e: unknown) => {
      if (e instanceof ApiError && e.code === 'reconfirm' && e.url) setConfirmUrl(e.url)
      else setError(e instanceof Error ? e.message : String(e))
    }).finally(() => setBusy(false))
  }
  if (confirmUrl) {
    return <ConfirmItsYou url={confirmUrl} why={warehouse ? 'A credit note this size is protected.' : 'Writing off what a customer owes is protected.'}
      go="Issue credit note" onCancel={() => setConfirmUrl(null)} onGo={() => { setConfirmUrl(null); go() }} />
  }
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 520 }}>
        <DialogBody>
          <DialogTitle>Credit note for {inv.number}</DialogTitle>
          <DialogContent>
            <Text block style={{ marginBottom: 10 }}>
              Cancels this invoice ({money(inv.total, inv.currency, inv.exponent)}) on the customer's account. If it was already paid, the money stays on the
              account as credit. It cannot be undone.
            </Text>
            <div className={s.form}>
              <TextField label="Reason" required maxLength={255} placeholder="e.g. Damaged cartons" value={reason} onChange={setReason} />
              <Choose label="Goods returned into (optional)" options={inv.actions.credit!.warehouses} value={warehouse} onChange={setWarehouse}
                placeholder="No goods come back" hint="Choose a warehouse only if the goods came back; they go back into its stock." />
            </div>
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 10 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Back</Button>
            <Button appearance="primary" disabled={busy || !reason.trim()} onClick={go}>{busy ? 'Working…' : 'Issue credit note'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
