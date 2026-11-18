import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Link, makeStyles, MessageBar, MessageBarBody, Option, SearchBox,
  Text, tokens,
} from '@fluentui/react-components'
import { ArrowUndoRegular, CheckmarkRegular, DocumentEditRegular, HandRightRegular, OpenRegular, SparkleRegular } from '@fluentui/react-icons'
import { useEffect, useState, type ReactNode } from 'react'
import { api, type Option as Choice, type SiOptions, type SiRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { appPathFor } from '../paths'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '320px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: '10px 18px', marginBottom: '10px' },
  buttons: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
})

const TONE: Record<string, 'warning' | 'success' | 'subtle'> = { received: 'warning', entered: 'success', rejected: 'subtle' }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const SHOW: [string, string][] = [['', 'To enter'], ['entered', 'Entered as a bill'], ['rejected', 'Turned back'], ['all', 'All']]

/**
 * Invoices from suppliers (release 5): what suppliers sent from their portal, To enter first. A row opens what may be done:
 * open the invoice, read it with the AI or enter it as a bill (both on the current bill form), mark it entered when it
 * was entered another way, or turn it back with the reason the supplier sees.
 */
export function SupplierInvoicesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<SiOptions | null>(null)
  const [open, setOpen] = useState<SiRow | null>(null)
  const [done, setDone] = useState('')
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.supplierInvoices, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.supplierInvoicesOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number, cur?: string) => (data ? money(v, cur ?? data.currency, data.exponent) : '')
  const label = (id: string) => options?.statuses.find((x) => x.id === id)?.label ?? id
  const columns: Column<SiRow>[] = [
    { key: 'received', label: 'Received', render: (r) => (r.received_at ? when(r.received_at) : '—') },
    { key: 'supplier', label: 'Supplier', render: (r) => r.supplier ?? '—' },
    { key: 'invoice', label: 'Their invoice', render: (r) => <>{r.invoice_no}<Text block size={200} className={s.muted}>Dated {day(r.invoice_date)}</Text></> },
    { key: 'amount', label: 'Amount', end: true, render: (r) => m(r.amount, r.currency) },
    { key: 'order', label: 'For order', render: (r) => r.order?.number ?? '—' },
    { key: 'sent_by', label: 'Sent by', render: (r) => r.sent_by ?? '—' },
    { key: 'status', label: 'Status', render: (r) => <>
      <Badge appearance="tint" color={TONE[r.status] ?? 'informative'}>{label(r.status)}</Badge>
      {r.note && <Text block size={200} className={s.muted}>{r.note}</Text>}
    </> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Invoices from suppliers" hint="Invoices suppliers sent from their portal. Enter each as a supplier bill, or turn it back with the reason they see." classic="/admin/supplier-invoices" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Their invoice number or supplier" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search invoices from suppliers" />
        <Dropdown aria-label="Show" value={SHOW.find(([k]) => k === str(params, 'status'))?.[1] ?? 'To enter'} selectedOptions={[str(params, 'status')]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
          {SHOW.map(([k, l]) => <Option key={k || 'enter'} value={k}>{l}</Option>)}
        </Dropdown>
        <ExportMenu list="supplier-invoices" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.consignment && <Button icon={<HandRightRegular />} onClick={() => navigate('/office/consignment-to-pay')}>Consignment to pay</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {data && <Cards items={[
        { label: 'Shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Amount shown', value: m(data.amount) },
        { label: 'To enter', value: data.waiting.toLocaleString('en-GB'), tone: data.waiting ? 'var(--zx-warn)' : undefined },
      ]} />}
      <DataTable label="Invoices from suppliers" columns={columns} rows={data?.rows ?? []} sort="created_at" dir={data?.dir ?? 'desc'} loading={loading}
        empty="No invoices from suppliers here. Suppliers send them from their portal page (Settings → Purchasing → Supplier portal)."
        onSort={(_, dir) => set({ dir })} onOpen={(r) => { setDone(''); setOpen(r) }} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {open && <Invoice r={open} money={m} label={label} onClose={() => setOpen(null)} onDone={(text) => { setOpen(null); setDone(text); setNonce((n) => n + 1) }} />}
    </div>
  )
}

/** One invoice and what may be done with it. */
function Invoice({ r, money: m, label, onClose, onDone }: {
  r: SiRow; money: (v: number, cur?: string) => string; label: (id: string) => string; onClose: () => void; onDone: (text: string) => void
}) {
  const s = useStyles()
  const [step, setStep] = useState<'mark' | 'reject' | 'read' | null>(null)
  const a = r.actions
  if (step === 'mark') return <Mark r={r} onClose={() => setStep(null)} onDone={() => onDone('Marked entered.')} />
  if (step === 'reject') return <Reject r={r} onClose={() => setStep(null)} onDone={() => onDone('Turned back: the supplier sees the reason.')} />
  if (step === 'read') {
    return (
      <Ask title="Read with AI" go="Read it" ready onClose={() => setStep(null)}
        run={() => api.supplierInvoiceAction(r.id, 'read').then((x) => { if (x.open) navigate(appPathFor(x.open)) })}>
        <Text>The AI reads the invoice into a draft bill, matched to what was received or confirmed on their orders. You check every line before creating it.</Text>
      </Ask>
    )
  }
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 600 }}>
        <DialogBody>
          <DialogTitle>{r.supplier}: invoice {r.invoice_no}</DialogTitle>
          <DialogContent>
            <div className={s.facts}>
              <Fact label="Amount">{m(r.amount, r.currency)}</Fact>
              <Fact label="Dated">{day(r.invoice_date)}</Fact>
              <Fact label="For order">{r.order ? (r.order.open ? <Link onClick={() => navigate(r.order!.open!)}>{r.order.number}</Link> : r.order.number) : '—'}</Fact>
              <Fact label="Sent by">{r.sent_by ?? '—'}{r.received_at && <Text block size={200} className={s.muted}>{when(r.received_at)}</Text>}</Fact>
              <Fact label="Status">{label(r.status)}{r.note && <Text block size={200} className={s.muted}>{r.note}</Text>}</Fact>
            </div>
            <div className={s.buttons}>
              {a.file && <Button icon={<OpenRegular />} onClick={() => window.open(a.file!, '_blank', 'noopener')}>Open the invoice</Button>}
              {a.read && <Button appearance="primary" icon={<SparkleRegular />} onClick={() => setStep('read')}>Read with AI</Button>}
              {a.enter && <Button icon={<DocumentEditRegular />} onClick={() => navigate(appPathFor(a.enter!))}>Enter as bill</Button>}
              {a.mark && <Button icon={<CheckmarkRegular />} onClick={() => setStep('mark')}>Mark entered</Button>}
              {a.reject && <Button icon={<ArrowUndoRegular />} onClick={() => setStep('reject')}>Turn back</Button>}
            </div>
          </DialogContent>
          <DialogActions><Button onClick={onClose}>Close</Button></DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text weight="semibold">{children}</Text></div>
}

function Mark({ r, onClose, onDone }: { r: SiRow; onClose: () => void; onDone: () => void }) {
  const [bills, setBills] = useState<Choice[] | null>(null)
  const [bill, setBill] = useState('')
  useEffect(() => { api.supplierInvoiceBills(r.id).then((x) => setBills(x.bills)).catch(() => setBills([])) }, [r.id])
  return (
    <Ask title="Mark entered" go="Mark entered" ready onClose={onClose} run={() => api.supplierInvoiceAction(r.id, 'mark', { bill: bill || null }).then(onDone)}>
      <Text block style={{ marginBottom: 10 }}>For an invoice already entered another way: choose its bill. (Enter as bill and Read with AI mark it entered by themselves.)</Text>
      <Choose label="The supplier bill" options={bills ?? []} value={bill} onChange={setBill} placeholder={bills === null ? 'Loading…' : 'Choose'} />
    </Ask>
  )
}

function Reject({ r, onClose, onDone }: { r: SiRow; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('')
  return (
    <Ask title="Turn back" go="Turn back" ready={reason.trim().length >= 3} onClose={onClose} run={() => api.supplierInvoiceAction(r.id, 'reject', { reason: reason.trim() }).then(onDone)}>
      <TextField label="Why (the supplier sees this)" required maxLength={255} placeholder="e.g. Prices differ from order PO-00012; send a corrected invoice" value={reason} onChange={setReason} />
    </Ask>
  )
}
