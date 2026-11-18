import {
  Badge, Button, Checkbox, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Field, makeStyles, Menu, MenuItem, MenuList,
  MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import {
  ArchiveRegular, ArrowDownloadRegular, ArrowLeftRegular, ArrowUndoRegular, ChartMultipleRegular, CheckmarkCircleRegular, DeleteRegular, DocumentCheckmarkRegular,
  EditRegular, GlobeRegular, MoreHorizontalRegular, OpenRegular, AttachRegular, ProhibitedRegular, ShieldCheckmarkRegular,
} from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type Scorecard, type SupplierAction, type SupplierActionResult, type SupplierDetail, type SupplierDoc } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
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
  panelHead: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: 0 },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '10px 18px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  form: { display: 'grid', gap: '10px', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', '@media (max-width: 560px)': { gridTemplateColumns: '1fr' } },
  one: { display: 'grid', gap: '10px' },
  link: { wordBreak: 'break-all', fontFamily: tokens.fontFamilyMonospace, padding: '8px', backgroundColor: tokens.colorNeutralBackground3, borderRadius: tokens.borderRadiusMedium },
  score: { width: '100%', borderCollapse: 'collapse', '& td': { padding: '6px 4px', borderBottom: `1px solid ${tokens.colorNeutralStroke2}` } },
})

const END = { style: { justifyContent: 'flex-end' } }
const TONE: Record<string, 'success' | 'danger' | 'warning'> = { approved: 'success', blocked: 'danger', pending: 'warning' }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const today = () => new Date().toISOString().slice(0, 10)
type Dialogs = 'approve' | 'block' | 'unblock' | 'verify_bank' | 'portal' | 'scorecard' | 'opening' | 'archive' | 'restore' | 'add_doc'
type Run = (body: Record<string, unknown>) => Promise<SupplierActionResult>

/**
 * One supplier (release 5): status (with what is missing before they can be approved), details, how they are paid
 * (and whether someone has checked the bank details), the products they supply and their documents; and every button
 * of the current screen — Approve, Block / Unblock, Verify bank details, Portal link, Scorecard, Opening balance, Edit,
 * Archive and Restore, and on the documents Add, Open, Checked against the original and Remove.
 */
export function SupplierPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [sup, setSup] = useState<SupplierDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<Dialogs | null>(null)
  const [doc, setDoc] = useState<{ d: SupplierDoc; what: 'verify' | 'remove' } | null>(null)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.supplier(id).then((x) => { setSup(x); onTitle(x.name) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !sup) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!sup) return <div className={page.page}><Spinner label="Opening the supplier…" /></div>
  const m = (v: number) => money(v, sup.currency, sup.exponent)
  const a = sup.actions
  const finish = (next: SupplierDetail, text: string) => { setSup(next); setDialog(null); setDoc(null); setDone(text) }
  const run = (action: SupplierAction, text: string): Run => (body) => api.supplierAction(sup.id, action, body).then((r) => { finish(r.supplier, text); return r })
  const more = [
    a.block && { key: 'block', icon: <ProhibitedRegular />, label: 'Block', on: () => setDialog('block') },
    a.unblock && { key: 'unblock', icon: <ArrowUndoRegular />, label: 'Unblock', on: () => setDialog('unblock') },
    a.opening && { key: 'opening', icon: <ArrowDownloadRegular />, label: 'Opening balance', on: () => setDialog('opening') },
    a.archive && { key: 'archive', icon: <ArchiveRegular />, label: 'Archive', on: () => setDialog('archive') },
    a.restore && { key: 'restore', icon: <ArrowUndoRegular />, label: 'Restore', on: () => setDialog('restore') },
  ].filter(Boolean) as { key: string; icon: ReactNode; label: string; on: () => void }[]

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/suppliers'))}>Suppliers</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          {sup.name}
          <Text font="monospace" size={300} className={s.muted}>{sup.code}</Text>
          <Badge appearance="tint" color={TONE[sup.status] ?? 'informative'}>{sup.status_label}</Badge>
          {sup.archived && <Badge appearance="tint" color="subtle">Archived</Badge>}
        </h1>
        <div className={s.actions}>
          {a.approve && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => setDialog('approve')}>Approve</Button>}
          {a.verify_bank && <Button icon={<ShieldCheckmarkRegular />} onClick={() => setDialog('verify_bank')}>Verify bank details</Button>}
          {a.portal && <Button icon={<GlobeRegular />} onClick={() => setDialog('portal')}>Portal link</Button>}
          <Button icon={<ChartMultipleRegular />} onClick={() => setDialog('scorecard')}>Scorecard</Button>
          {a.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/suppliers/${sup.id}/edit`)}>Edit</Button>}
          {more.length > 0 && (
            <Menu>
              <MenuTrigger disableButtonEnhancement><Button icon={<MoreHorizontalRegular />}>More</Button></MenuTrigger>
              <MenuPopover><MenuList>{more.map((x) => <MenuItem key={x.key} icon={x.icon as never} onClick={x.on}>{x.label}</MenuItem>)}</MenuList></MenuPopover>
            </Menu>
          )}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {sup.status === 'blocked' && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>Blocked: {sup.blocked_reason}. No new orders go to them.</MessageBarBody></MessageBar>}
      {sup.status === 'pending' && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>
        Waiting for approval: someone other than the person who added them checks their TIN and documents, then presses Approve. Orders go to approved suppliers only.
      </MessageBarBody></MessageBar>}
      {sup.gaps.length > 0 && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>Missing or out of date: {sup.gaps.join(', ')}.</MessageBarBody></MessageBar>}
      {sup.archive_note && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>{sup.archive_note}. Restore it to order from them again.</MessageBarBody></MessageBar>}

      <div className={s.tiles}>
        {sup.owed !== null && <Tile label="We owe" value={m(sup.owed)} tone={sup.owed > 0 ? 'var(--zx-warn)' : undefined} />}
        <Tile label="Terms" value={sup.facts['Payment terms'] ?? 'Cash'} />
        <Tile label="Score (12 months)" value={sup.scorecard.grade ? `${sup.scorecard.grade} · ${sup.scorecard.score}` : '—'} />
        <Tile label="Products supplied" value={sup.products.length.toLocaleString('en-GB')} />
      </div>

      <Panel title="Details">
        <div className={s.facts}>
          {Object.entries(sup.facts).map(([k, v]) => <Fact key={k} label={k}>{v}</Fact>)}
        </div>
        {sup.notes && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}>{sup.notes}</Text>}
      </Panel>

      <Panel title="How they are paid">
        {Object.keys(sup.bank.details).length === 0 ? <Text className={s.muted}>No bank or MoMo details. They are paid in cash or by cheque.</Text> : (
          <>
            <div className={s.facts}>{Object.entries(sup.bank.details).map(([k, v]) => <Fact key={k} label={k}>{v}</Fact>)}</div>
            <Text block style={{ marginTop: 10, color: sup.bank.unchecked ? 'var(--zx-warn)' : undefined }}>
              {sup.bank.checked ? `Checked: ${sup.bank.checked}.` : 'Not checked yet: no transfer or MoMo payment goes to them until someone else verifies the details.'}
            </Text>
          </>
        )}
      </Panel>

      <Panel title="Products supplied">
        {sup.products.length === 0 ? <Text className={s.muted}>None listed. Add them on Edit; purchase orders fill in from here.</Text> : (
          <Table size="small" aria-label="Products supplied">
            <TableHeader><TableRow>
              <TableHeaderCell>Product and their pack</TableHeaderCell><TableHeaderCell>Their code</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Cost per pack</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Minimum</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>In multiples of</TableHeaderCell>
            </TableRow></TableHeader>
            <TableBody>{sup.products.map((p, i) => (
              <TableRow key={`${p.label}-${i}`}>
                <TableCell>{p.label}{p.preferred && <> <Badge appearance="tint" color="brand" size="small">preferred</Badge></>}</TableCell>
                <TableCell>{p.sku ?? '—'}</TableCell>
                <TableCell className={s.end}>{p.cost === null ? '—' : m(p.cost)}</TableCell>
                <TableCell className={s.end}>{p.min === '0' ? 'any' : p.min}</TableCell>
                <TableCell className={s.end}>{p.multiple === '0' ? 'any' : p.multiple}</TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </Panel>

      <Panel title="Documents" action={sup.documents.can_add && <Button icon={<AttachRegular />} onClick={() => setDialog('add_doc')}>Add a document</Button>}>
        <Text block size={200} className={s.muted} style={{ marginBottom: 8 }}>
          Required by your company: {sup.documents.required.join(', ') || 'none'} (Settings → Purchasing).
        </Text>
        {sup.documents.rows.length === 0 ? <Text className={s.muted}>No documents yet.</Text> : (
          <Table size="small" aria-label="Documents">
            <TableHeader><TableRow>
              <TableHeaderCell>Document</TableHeaderCell><TableHeaderCell>Number</TableHeaderCell><TableHeaderCell>Expires</TableHeaderCell>
              <TableHeaderCell>Added by</TableHeaderCell><TableHeaderCell>Checked by</TableHeaderCell><TableHeaderCell />
            </TableRow></TableHeader>
            <TableBody>{sup.documents.rows.map((d) => (
              <TableRow key={d.id}>
                <TableCell>{d.kind}{d.required && <Text block size={200} className={s.muted}>required</Text>}</TableCell>
                <TableCell>{d.number ?? '—'}</TableCell>
                <TableCell><Text style={{ color: d.expired ? 'var(--zx-bad)' : d.soon ? 'var(--zx-warn)' : undefined }}>
                  {d.expires_on ? day(d.expires_on) : 'does not expire'}{d.expired && ' · expired'}</Text></TableCell>
                <TableCell>{d.by}</TableCell>
                <TableCell>{d.checked_by ?? <Text className={s.muted}>not yet</Text>}</TableCell>
                <TableCell>
                  <div className={s.actions}>
                    {d.file && <Button size="small" icon={<OpenRegular />} onClick={() => window.open(api.supplierDocumentUrl(sup.id, d.id), '_blank', 'noopener')}>Open</Button>}
                    {d.can_verify && <Button size="small" icon={<DocumentCheckmarkRegular />} onClick={() => setDoc({ d, what: 'verify' })}>Checked</Button>}
                    {d.can_remove && <Button size="small" icon={<DeleteRegular />} onClick={() => setDoc({ d, what: 'remove' })}>Remove</Button>}
                  </div>
                </TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </Panel>

      {dialog === 'approve' && (
        <Note title={`Approve ${sup.name}?`} go="Approve" about="Check their TIN and documents first. You cannot approve a supplier you added." label="Note (optional)"
          optional onClose={() => setDialog(null)} run={(note) => run('approve', 'Supplier approved.')({ note: note || null })} />
      )}
      {dialog === 'block' && (
        <Note title={`Block ${sup.name}?`} go="Block" about="No new orders go to them; buyers see the reason." label="Reason"
          placeholder="e.g. Licence suspended by FDA; repeated short deliveries" onClose={() => setDialog(null)} run={(reason) => run('block', 'Supplier blocked.')({ reason })} />
      )}
      {dialog === 'unblock' && (
        <Note title={`Unblock ${sup.name}?`} go="Unblock" label="Reason" onClose={() => setDialog(null)} run={(reason) => run('unblock', 'Supplier unblocked.')({ reason })} />
      )}
      {dialog === 'verify_bank' && a.verify_bank && (
        <Note title="Verify bank details" go="Verified" label="How you confirmed them" placeholder="e.g. Called Mr Mensah on 024… (number from our first order)"
          about={`Call ${sup.name} on a number you already had (not one from the message that changed the details) and confirm: ${a.verify_bank.confirm}`}
          onClose={() => setDialog(null)} run={(how) => run('verify_bank', 'Bank details verified.')({ how })} />
      )}
      {dialog === 'portal' && a.portal && <Portal sup={sup} onClose={() => setDialog(null)} run={run('portal', 'Portal link ready.')} />}
      {dialog === 'scorecard' && <ScorecardDialog name={sup.name} c={sup.scorecard} onClose={() => setDialog(null)} />}
      {dialog === 'opening' && <Opening prefix={sup.currency} onClose={() => setDialog(null)} run={run('opening', 'Opening balance recorded.')} />}
      {dialog === 'archive' && a.archive && <ArchiveDialog name={sup.name} blockers={a.archive.blockers} onClose={() => setDialog(null)} run={run('archive', 'Archived.')} />}
      {dialog === 'restore' && (
        <Ask title={`Restore ${sup.name}?`} go="Restore" ready onClose={() => setDialog(null)} run={() => run('restore', 'Restored.')({})}>
          <Text>They come back into the lists people choose from.</Text>
        </Ask>
      )}
      {dialog === 'add_doc' && <AddDocument sup={sup} onClose={() => setDialog(null)} onDone={(next) => finish(next, 'Document added.')} />}
      {doc?.what === 'verify' && (
        <Ask title={`${doc.d.kind}: checked against the original?`} go="Checked" ready onClose={() => setDoc(null)}
          run={() => api.supplierDocument(sup.id, doc.d.id, 'verify').then((r) => finish(r.supplier, 'Document checked.'))}>
          <Text>Confirm you have seen the original (or checked it with the issuer) and it matches.</Text>
        </Ask>
      )}
      {doc?.what === 'remove' && (
        <Note title={`Remove ${doc.d.kind}?`} go="Remove" label="Why (kept in the audit log)" onClose={() => setDoc(null)}
          run={(reason) => api.supplierDocument(sup.id, doc.d.id, 'remove', reason).then((r) => finish(r.supplier, 'Document removed.'))} />
      )}
    </div>
  )
}

function Tile({ label, value, tone }: { label: string; value: string; tone?: string }) {
  const s = useStyles()
  return <div className={s.tile}><Text size={200} className={s.muted}>{label}</Text><span className={s.tileValue} style={tone ? { color: tone } : undefined}>{value}</span></div>
}

function Panel({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><div className={s.panelHead}><h2 className={s.h2}>{title}</h2>{action}</div>{children}</section>
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{label}</Text><Text weight="semibold">{children}</Text></div>
}

/** A dialog asking one line of text (a reason, a note, how it was checked). */
function Note({ title, go, about, label, placeholder, optional, onClose, run }: {
  title: string; go: string; about?: string; label: string; placeholder?: string; optional?: boolean; onClose: () => void; run: (text: string) => Promise<unknown>
}) {
  const [text, setText] = useState('')
  return (
    <Ask title={title} go={go} ready={optional || text.trim().length >= 3} onClose={onClose} run={() => run(text.trim())}>
      {about && <Text block style={{ marginBottom: 10 }}>{about}</Text>}
      <TextField label={label} required={!optional} maxLength={255} placeholder={placeholder} value={text} onChange={setText} />
    </Ask>
  )
}

function Portal({ sup, onClose, run }: { sup: SupplierDetail; onClose: () => void; run: Run }) {
  const s = useStyles()
  const p = sup.actions.portal!
  const [channel, setChannel] = useState(p.channels[0]?.id ?? 'none')
  const [to, setTo] = useState(p.email ?? p.phone ?? '')
  const [renew, setRenew] = useState(false)
  const [result, setResult] = useState<SupplierActionResult | null>(null)
  if (result) {
    return (
      <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
        <DialogSurface style={{ maxWidth: 560 }}>
          <DialogBody>
            <DialogTitle>{result.open ? 'Ready in WhatsApp' : result.sent ? 'Sent' : 'Their link'}</DialogTitle>
            <DialogContent>
              {result.open ? <Text block>Open WhatsApp and press send.</Text> : <div className={s.link}>{result.link}</div>}
            </DialogContent>
            <DialogActions>
              {result.open && <Button appearance="primary" icon={<OpenRegular />} onClick={() => window.open(result.open!, '_blank', 'noopener')}>Open WhatsApp</Button>}
              <Button onClick={onClose}>Close</Button>
            </DialogActions>
          </DialogBody>
        </DialogSurface>
      </Dialog>
    )
  }
  return (
    <Ask title={`Supplier portal: ${sup.name}`} go={channel === 'none' ? 'Show the link' : 'Send'} ready={channel === 'none' || !!to.trim()} onClose={onClose} width={560}
      run={() => run({ channel, to: channel === 'none' ? null : to.trim(), renew }).then((r) => setResult(r))}>
      <Text block style={{ marginBottom: 10 }}>
        Their own page: quotes, orders to confirm, goods on the way, invoices, and what is owed and paid. No password: send it only to their own e-mail or WhatsApp.
      </Text>
      <div className={s.one}>
        <Field label="Send it by" required>
          <RadioGroup value={channel} onChange={(_, d) => { setChannel(d.value); setTo(d.value === 'email' ? (p.email ?? '') : (p.phone ?? '')) }}>
            {p.channels.map((c) => <Radio key={c.id} value={c.id} label={c.label} />)}
          </RadioGroup>
        </Field>
        {channel !== 'none' && <TextField label={channel === 'email' ? 'E-mail address' : 'WhatsApp number'} required maxLength={160} value={to} onChange={setTo} />}
        {p.has_link && <Checkbox checked={renew} onChange={(_, d) => setRenew(!!d.checked)} label="Make a new link (the old one stops working)" />}
      </div>
    </Ask>
  )
}

function ScorecardDialog({ name, c, onClose }: { name: string; c: Scorecard; onClose: () => void }) {
  const s = useStyles()
  const rows: [string, string | null, string][] = [
    ['On time', c.on_time !== null ? `${c.on_time} %` : null, 'Orders whose first delivery came by the expected date (40 % of the score).'],
    ['Fill rate', c.fill !== null ? `${c.fill} %` : null, 'Of what was ordered on finished orders, how much came (30 %).'],
    ['Sent back for faults', c.quality_issues !== null ? `${c.quality_issues} %` : null, 'Damaged, recalled or wrong goods returned, of what was received; expiry returns not counted (20 %).'],
    ['Price changes on bills', c.price_change !== null ? `${c.price_change} %` : null, 'Bills above (+) or below (−) the order price (10 %).'],
    ['Lead time', c.lead_days !== null ? `${c.lead_days} days` : null, 'From approving an order to its first delivery.'],
    ['Orders', String(c.orders), 'Approved in the last 12 months.'],
  ]
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 560 }}>
        <DialogBody>
          <DialogTitle>Scorecard: {name} (last 12 months)</DialogTitle>
          <DialogContent>
            {!c.orders ? <Text>No approved orders in the last 12 months yet.</Text> : (
              <>
                <Text block size={500} weight="bold" style={{ marginBottom: 8 }}>{c.grade ?? '—'} {c.score !== null && <Text className={s.muted}>{c.score} / 100</Text>}</Text>
                <table className={s.score}><tbody>{rows.map(([l, v, why]) => (
                  <tr key={l}><td><Text weight="semibold">{l}</Text><Text block size={200} className={s.muted}>{why}</Text></td><td className={s.end}>{v ?? '—'}</td></tr>
                ))}</tbody></table>
              </>
            )}
          </DialogContent>
          <DialogActions><Button onClick={onClose}>Close</Button></DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}

function Opening({ prefix, onClose, run }: { prefix: string; onClose: () => void; run: Run }) {
  const s = useStyles()
  const [v, setV] = useState({ amount: '', as_of: today(), due_on: '', reference: '' })
  return (
    <Ask title="Opening balance" go="Record" ready={Number(v.amount) > 0 && !!v.as_of} onClose={onClose}
      run={() => run({ ...v, due_on: v.due_on || null, reference: v.reference || null })}>
      <Text block size={200} className={s.muted} style={{ marginBottom: 8 }}>What you owed this supplier when you started using this system (from your old books). Once only.</Text>
      <div className={s.form}>
        <TextField label="Amount" required type="number" min={0} step="any" prefix={prefix} value={v.amount} onChange={(x) => setV({ ...v, amount: x })} />
        <TextField label="As at" required type="date" value={v.as_of} onChange={(x) => setV({ ...v, as_of: x })} />
        <TextField label="Due" type="date" value={v.due_on} onChange={(x) => setV({ ...v, due_on: x })} />
        <TextField label="Their invoice number(s)" maxLength={60} value={v.reference} onChange={(x) => setV({ ...v, reference: x })} />
      </div>
    </Ask>
  )
}

function ArchiveDialog({ name, blockers, onClose, run }: { name: string; blockers: string[]; onClose: () => void; run: Run }) {
  const [reason, setReason] = useState('')
  return (
    <Ask title={`Archive ${name}?`} go="Archive" ready={!blockers.length && reason.trim().length >= 3} onClose={onClose} run={() => run({ reason: reason.trim() })}>
      {blockers.length ? <Text block>It cannot be archived yet. {blockers.join(' ')}</Text> : (
        <>
          <Text block style={{ marginBottom: 8 }}>
            They leave every list people choose from, so nothing new is ordered from them. Their history stays: past orders, bills, the books and the audit log
            keep showing them. You can restore them at any time.
          </Text>
          <TextField label="Why" required maxLength={255} placeholder="e.g. replaced, no longer used" value={reason} onChange={setReason} />
        </>
      )}
    </Ask>
  )
}

function AddDocument({ sup, onClose, onDone }: { sup: SupplierDetail; onClose: () => void; onDone: (next: SupplierDetail) => void }) {
  const s = useStyles()
  const [v, setV] = useState({ kind: '', number: '', issued_on: '', expires_on: '' })
  const [file, setFile] = useState<File | null>(null)
  return (
    <Ask title="Add a document" go="Add" ready={!!v.kind && (!!file || !!v.number.trim())} onClose={onClose} width={560}
      run={() => {
        const body = new FormData()
        Object.entries(v).forEach(([k, x]) => { if (x) body.append(k, x) })
        if (file) body.append('file', file)
        return api.addSupplierDocument(sup.id, body).then((r) => onDone(r.supplier))
      }}>
      <div className={s.form}>
        <Choose label="Document" required options={sup.documents.kinds} value={v.kind} onChange={(x) => setV({ ...v, kind: x })} />
        <TextField label="Number on it" maxLength={80} value={v.number} onChange={(x) => setV({ ...v, number: x })} />
        <TextField label="Issued" type="date" value={v.issued_on} onChange={(x) => setV({ ...v, issued_on: x })} />
        <TextField label="Expires" type="date" value={v.expires_on} onChange={(x) => setV({ ...v, expires_on: x })}
          hint="You are told before it expires, and orders stop when a required one has expired." />
        <Field label="Scan or photo (PDF or picture)" hint="Up to 10 MB. Attach it or enter its number." style={{ gridColumn: '1 / -1' }}>
          <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
      </div>
    </Ask>
  )
}
