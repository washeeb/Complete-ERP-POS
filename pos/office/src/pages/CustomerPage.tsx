import {
  Badge, Button, Link, makeStyles, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger,
  MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import {
  ArchiveRegular, ArrowLeftRegular, ArrowUndoRegular, BriefcaseRegular, CreditCardPersonRegular, DocumentTextRegular, EditRegular, HeartPulseRegular,
  MoneyRegular, MoreHorizontalRegular, AlertRegular, ArrowDownloadRegular,
} from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type CustomerAction, type CustomerDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, Tags, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { appPathFor } from '../paths'

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
  form: { display: 'grid', gap: '10px', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', '@media (max-width: 560px)': { gridTemplateColumns: '1fr' } },
})

const END = { style: { justifyContent: 'flex-end' } }
const day = (d: string | null) => (d ? new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const today = () => new Date().toISOString().slice(0, 10)
type Dialogs = 'pay' | 'statement' | 'credit' | 'opening' | 'patient' | 'follow_up' | 'archive' | 'restore'

/**
 * One customer (release 4): their details, account (balance, overdue, credit limit and what is left of it, terms) and
 * what they still owe, oldest first; and every button of the current page — record a payment, print a statement,
 * change credit, record the opening balance, patient details (pharmacy), plan a follow-up or start a deal (CRM),
 * edit, archive and restore.
 */
export function CustomerPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [c, setC] = useState<CustomerDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<Dialogs | null>(null)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.customer(id).then((x) => { setC(x); onTitle(x.name) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !c) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!c) return <div className={page.page}><Spinner label="Opening the customer…" /></div>
  const m = (v: number) => money(v, c.currency, c.exponent)
  const a = c.actions
  const finish = (next: CustomerDetail, text: string) => { setC(next); setDialog(null); setDone(text) }
  const run = (action: CustomerAction, text: string) => (body: Record<string, unknown>) => api.customerAction(c.id, action, body).then((n) => finish(n, text))
  const more = [
    a.opening && { key: 'opening', icon: <ArrowDownloadRegular />, label: 'Opening balance', on: () => setDialog('opening') },
    a.follow_up && { key: 'follow', icon: <AlertRegular />, label: 'Follow-up', on: () => setDialog('follow_up') },
    a.new_deal && { key: 'deal', icon: <BriefcaseRegular />, label: 'New deal', on: () => navigate(appPathFor(a.new_deal!)) },
    a.archive && { key: 'archive', icon: <ArchiveRegular />, label: 'Archive', on: () => setDialog('archive') },
    a.restore && { key: 'restore', icon: <ArrowUndoRegular />, label: 'Restore', on: () => setDialog('restore') },
  ].filter(Boolean) as { key: string; icon: ReactNode; label: string; on: () => void }[]

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/customers'))}>Customers</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          {c.name}
          <Text font="monospace" size={300} className={s.muted}>{c.code}</Text>
          {c.archived && <Badge appearance="tint" color="subtle">Archived</Badge>}
          {c.hold && <Badge appearance="tint" color="danger">On credit hold</Badge>}
        </h1>
        <div className={s.actions}>
          {a.pay && <Button appearance="primary" icon={<MoneyRegular />} onClick={() => setDialog('pay')}>Record payment</Button>}
          {a.statement && <Button icon={<DocumentTextRegular />} onClick={() => setDialog('statement')}>Statement</Button>}
          {a.credit && <Button icon={<CreditCardPersonRegular />} onClick={() => setDialog('credit')}>Credit</Button>}
          {a.patient && <Button icon={<HeartPulseRegular />} onClick={() => setDialog('patient')}>Patient details</Button>}
          {a.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/customers/${c.id}/edit`)}>Edit</Button>}
          {more.length > 0 && (
            <Menu>
              <MenuTrigger disableButtonEnhancement><Button icon={<MoreHorizontalRegular />}>More</Button></MenuTrigger>
              <MenuPopover><MenuList>{more.map((x) => <MenuItem key={x.key} icon={x.icon as never} onClick={x.on}>{x.label}</MenuItem>)}</MenuList></MenuPopover>
            </Menu>
          )}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {c.archive_note && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>{c.archive_note}. Restore it to sell to them on account again.</MessageBarBody></MessageBar>}

      <div className={s.tiles}>
        <Tile label="Balance" value={m(c.account.balance)} tone={c.account.balance > 0 ? 'var(--zx-warn)' : undefined} />
        <Tile label="Overdue" value={m(c.account.overdue)} tone={c.account.overdue > 0 ? 'var(--zx-bad)' : undefined} />
        <Tile label="Credit limit" value={c.account.limit === null ? 'No credit (cash)' : m(c.account.limit)} />
        <Tile label="Available credit" value={c.account.available === null ? '—' : m(c.account.available)} />
        <Tile label="Terms" value={c.account.terms} />
      </div>

      <Panel title="Details">
        <div className={s.facts}>
          {Object.entries(c.facts).map(([k, v]) => <div key={k}><Text size={200} block className={s.muted}>{k}</Text><Text weight="semibold">{v}</Text></div>)}
        </div>
        {c.notes && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{c.notes}</Text>}
      </Panel>
      {c.patient && Object.keys(c.patient).length > 0 && (
        <Panel title="Patient">
          <div className={s.facts}>
            {Object.entries(c.patient).map(([k, v]) => <div key={k}><Text size={200} block className={s.muted}>{k}</Text>
              <Text weight="semibold" style={k === 'Allergies' ? { color: 'var(--zx-bad)' } : undefined}>{v}</Text></div>)}
          </div>
        </Panel>
      )}
      <Panel title="Open items">
        <Text block size={200} className={s.muted} style={{ marginBottom: 6 }}>Invoices and till credit sales not yet fully paid, oldest first.</Text>
        {c.open_items.length === 0 ? <Text className={s.muted}>Nothing outstanding.</Text> : (
          <Table size="small" aria-label="Open items">
            <TableHeader><TableRow>
              <TableHeaderCell>Document</TableHeaderCell><TableHeaderCell>Due</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Amount</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Still owed</TableHeaderCell>
            </TableRow></TableHeader>
            <TableBody>{c.open_items.map((r, i) => (
              <TableRow key={`${r.number}-${i}`}>
                <TableCell>{r.url ? <Link onClick={() => navigate(appPathFor(r.url!))}>{r.number}</Link> : r.number}</TableCell>
                <TableCell>{day(r.due)}{r.overdue && <> <Badge appearance="tint" color="danger" size="small">overdue</Badge></>}</TableCell>
                <TableCell className={s.end}>{m(r.amount)}</TableCell>
                <TableCell className={s.end}><Text weight="semibold">{m(r.open)}</Text></TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </Panel>

      {dialog === 'pay' && a.pay && <Pay c={c} onClose={() => setDialog(null)} run={run('pay', 'Payment recorded.')} />}
      {dialog === 'statement' && a.statement && <Statement url={a.statement} onClose={() => setDialog(null)} />}
      {dialog === 'credit' && a.credit && <Credit c={c} onClose={() => setDialog(null)} run={run('credit', 'Credit updated.')} />}
      {dialog === 'opening' && <Opening onClose={() => setDialog(null)} run={run('opening', 'Opening balance recorded.')} prefix={c.currency} />}
      {dialog === 'patient' && a.patient && <Patient c={c} onClose={() => setDialog(null)} run={run('patient', 'Patient details saved.')} />}
      {dialog === 'follow_up' && a.follow_up && <FollowUp c={c} onClose={() => setDialog(null)} run={run('follow_up', 'Follow-up saved.')} />}
      {dialog === 'archive' && a.archive && <Archive c={c} onClose={() => setDialog(null)} run={run('archive', 'Archived.')} />}
      {dialog === 'restore' && (
        <Ask title={`Restore ${c.name}?`} go="Restore" ready onClose={() => setDialog(null)} run={() => run('restore', 'Restored.')({})}>
          <Text>They come back into the lists people choose from.</Text>
        </Ask>
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

type Run = (body: Record<string, unknown>) => Promise<unknown>

function Pay({ c, onClose, run }: { c: CustomerDetail; onClose: () => void; run: Run }) {
  const s = useStyles()
  const p = c.actions.pay!
  const [v, setV] = useState({ branch_id: p.default_branch ?? '', amount: '', method: '', provider: '', reference: '', received_on: today(), note: '' })
  const set = (k: keyof typeof v) => (x: string) => setV({ ...v, [k]: x })
  const needsRef = ['momo', 'bank', 'cheque'].includes(v.method)
  const ready = !!v.branch_id && Number(v.amount) > 0 && !!v.method && (v.method !== 'momo' || !!v.provider) && (!needsRef || !!v.reference.trim())
  return (
    <Ask title="Record payment" go="Record payment" ready={ready} onClose={onClose} run={() => run(v)}>
      <Text block size={200} className={s.muted} style={{ marginBottom: 8 }}>It settles what they owe, oldest first. Balance now: {money(c.account.balance, c.currency, c.exponent)}.</Text>
      <div className={s.form}>
        <Choose label="Received at" required options={p.branches} value={v.branch_id} onChange={set('branch_id')} />
        <TextField label="Amount" required type="number" min={0} step="any" prefix={c.currency} value={v.amount} onChange={set('amount')} />
        <Choose label="Method" required options={p.methods} value={v.method} onChange={set('method')} />
        {v.method === 'momo' && <Choose label="Network" required options={p.providers} value={v.provider} onChange={set('provider')} />}
        <TextField label="Transaction / cheque no." required={needsRef} maxLength={80} value={v.reference} onChange={set('reference')} />
        <TextField label="Received on" type="date" value={v.received_on} onChange={set('received_on')} />
        <TextField label="Note" maxLength={255} value={v.note} onChange={set('note')} />
      </div>
    </Ask>
  )
}

function Statement({ url, onClose }: { url: string; onClose: () => void }) {
  const s = useStyles()
  const first = `${today().slice(0, 8)}01`
  const [from, setFrom] = useState(first)
  const [to, setTo] = useState(today())
  return (
    <Ask title="Statement" go="Open the statement" ready={!!from && !!to && from <= to} onClose={onClose}
      run={async () => { window.open(`${url}?from=${from}&to=${to}`, '_blank', 'noopener'); onClose() }}>
      <Text block size={200} className={s.muted} style={{ marginBottom: 8 }}>Opens ready to print or save as PDF, with what was owed at the start and each document since.</Text>
      <div className={s.form}>
        <TextField label="From" required type="date" value={from} onChange={setFrom} />
        <TextField label="To" required type="date" value={to} onChange={setTo} />
      </div>
    </Ask>
  )
}

function Credit({ c, onClose, run }: { c: CustomerDetail; onClose: () => void; run: Run }) {
  const s = useStyles()
  const cur = c.actions.credit!
  const [v, setV] = useState({ limit: cur.limit, terms: cur.terms, hold: cur.hold, reason: '' })
  return (
    <Ask title="Credit" go="Save" ready={!!v.reason.trim() && v.terms !== '' && Number(v.terms) >= 0 && Number(v.terms) <= 365} onClose={onClose} run={() => run(v)}>
      <div className={s.form}>
        <TextField label="Credit limit" type="number" min={0} step="any" prefix={c.currency} value={v.limit} onChange={(x) => setV({ ...v, limit: x })} hint="Blank = no credit (cash only)." />
        <TextField label="Payment terms (days)" required type="number" min={0} max={365} value={v.terms} onChange={(x) => setV({ ...v, terms: x })} />
        <Toggle label="Stop credit (credit hold)" value={v.hold} onChange={(x) => setV({ ...v, hold: x })} />
        <TextField label="Reason" required maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
      </div>
    </Ask>
  )
}

function Opening({ onClose, run, prefix }: { onClose: () => void; run: Run; prefix: string }) {
  const s = useStyles()
  const [v, setV] = useState({ amount: '', as_of: today() })
  return (
    <Ask title="Opening balance" go="Record" ready={v.amount.trim() !== '' && !!v.as_of} onClose={onClose} run={() => run(v)}>
      <Text block size={200} className={s.muted} style={{ marginBottom: 8 }}>What the customer owed when you started using this system (from your old books). Once only.</Text>
      <div className={s.form}>
        <TextField label="Amount" required type="number" step="any" prefix={prefix} value={v.amount} onChange={(x) => setV({ ...v, amount: x })} />
        <TextField label="As of" required type="date" value={v.as_of} onChange={(x) => setV({ ...v, as_of: x })} />
      </div>
    </Ask>
  )
}

function Patient({ c, onClose, run }: { c: CustomerDetail; onClose: () => void; run: Run }) {
  const s = useStyles()
  const f = useFieldStyles()
  const [v, setV] = useState(c.actions.patient!)
  const set = (k: keyof typeof v) => (x: string) => setV({ ...v, [k]: x })
  return (
    <Ask title="Patient details" go="Save" ready onClose={onClose} run={() => run(v)} width={600}>
      <div className={s.form}>
        <TextField label="Date of birth" type="date" value={v.date_of_birth} onChange={set('date_of_birth')} />
        <Choose label="Sex" options={[{ id: 'female', label: 'Female' }, { id: 'male', label: 'Male' }]} value={v.sex} onChange={set('sex')} />
        <Tags label="Allergies (ingredients)" className={f.spanAll} value={v.allergies} onChange={(x) => setV({ ...v, allergies: x })} placeholder="e.g. penicillin, then Enter"
          hint="The till warns the pharmacist when a medicine contains one of these." />
        <LongText label="Conditions / notes" className={f.spanAll} maxLength={2000} value={v.conditions} onChange={set('conditions')} />
        <TextField label="NHIS membership no." maxLength={30} value={v.nhis_no} onChange={set('nhis_no')} />
        <TextField label="NHIS card expires" type="date" value={v.nhis_expires_on} onChange={set('nhis_expires_on')} />
      </div>
    </Ask>
  )
}

function FollowUp({ c, onClose, run }: { c: CustomerDetail; onClose: () => void; run: Run }) {
  const s = useStyles()
  const f = useFieldStyles()
  const opts = c.actions.follow_up!
  const [v, setV] = useState(() => ({ type: 'call', subject: '', due_at: `${new Date(Date.now() + 86400000).toISOString().slice(0, 10)}T09:00`, assigned_to: '', outcome: '', details: '' }))
  const set = (k: keyof typeof v) => (x: string) => setV({ ...v, [k]: x })
  const note = v.type === 'note'
  return (
    <Ask title="Plan a follow-up or note what happened" go="Save" ready={!!v.type && !!v.subject.trim()} onClose={onClose} width={600}
      run={() => run({ ...v, due_at: note || !v.due_at ? null : v.due_at, assigned_to: note || !v.due_at ? null : v.assigned_to || null, outcome: note || v.due_at ? null : v.outcome })}>
      <div className={s.form}>
        <Choose label="What" required options={opts.types} value={v.type} onChange={set('type')} />
        <TextField label="About" required maxLength={255} placeholder="e.g. Call back about the price" value={v.subject} onChange={set('subject')} />
        {!note && <TextField label="When (leave empty if it already happened)" type="datetime-local" value={v.due_at} onChange={set('due_at')} />}
        {!note && v.due_at && <Choose label="Who does it" options={opts.people} value={v.assigned_to} onChange={set('assigned_to')} placeholder="You" />}
        {!note && !v.due_at && <TextField label="What came of it" maxLength={255} value={v.outcome} onChange={set('outcome')} />}
        <LongText label="Details" className={f.spanAll} value={v.details} onChange={set('details')} />
      </div>
    </Ask>
  )
}

function Archive({ c, onClose, run }: { c: CustomerDetail; onClose: () => void; run: Run }) {
  const blockers = c.actions.archive!.blockers
  const [reason, setReason] = useState('')
  return (
    <Ask title={`Archive ${c.name}?`} go="Archive" ready={!blockers.length && reason.trim().length >= 3} onClose={onClose} run={() => run({ reason: reason.trim() })}>
      {blockers.length ? <Text block>It cannot be archived yet. {blockers.join(' ')}</Text> : (
        <>
          <Text block style={{ marginBottom: 8 }}>
            They leave every list people choose from, so nothing new is sold to them on account. Their history stays: past sales, invoices, the books and
            the audit log keep showing them. You can restore them at any time.
          </Text>
          <TextField label="Why" required maxLength={255} placeholder="e.g. created by mistake, no longer used" value={reason} onChange={setReason} />
        </>
      )}
    </Ask>
  )
}
