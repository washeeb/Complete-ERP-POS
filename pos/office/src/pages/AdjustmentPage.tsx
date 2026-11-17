import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Field, Input, Link, makeStyles, Menu, MenuItem, MenuList, MenuPopover,
  MenuTrigger, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import {
  ArrowDownloadRegular, ArrowLeftRegular, ArrowUndoRegular, ArrowUploadRegular, CheckmarkCircleRegular, DismissCircleRegular, EditRegular, PrintRegular, SendRegular,
} from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type AdjDetail } from '../api'
import { money } from '../home/format'
import { download } from '../list/ExportMenu'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { ADJ_STATUS } from './AdjustmentsPage'

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
})

const END = { style: { justifyContent: 'flex-end' } }
type Ask = { action: 'post' | 'submit' | 'reopen' | 'cancel'; title: string; text: string; reason: boolean; go: string }

/**
 * One stock adjustment (release 3): who prepared, submitted and posted it, the lines — as posted, or as they would post
 * against the stock on hand now — the value at average cost, the photos, and what may be done next: edit the draft,
 * submit it for approval, approve and post, send it back or cancel it (with a reason), the voucher to sign.
 */
export function AdjustmentPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [a, setA] = useState<AdjDetail | null>(null)
  const [error, setError] = useState('')
  const [ask, setAsk] = useState<Ask | null>(null)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.adjustment(id).then((x) => { setA(x); onTitle(`Adjustment ${x.number}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !a) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!a) return <div className={page.page}><Spinner label="Opening the adjustment…" /></div>
  const m = (v: number) => money(v, a.currency, a.exponent)
  const voucher = (format: 'pdf' | 'csv') => download(api.adjustmentVoucherUrl(a.id, format), `${a.number}.${format}`).then((p) => p && setError(p))

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/adjustments'))}>Stock adjustments</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          Adjustment {a.number}
          <Badge appearance="tint" color={ADJ_STATUS[a.status] ?? 'informative'}>{a.status_label}</Badge>
          {a.kind === 'disposal' && <Badge appearance="outline" color="danger">Disposal</Badge>}
        </h1>
        <div className={s.actions}>
          {a.actions.edit && <Button icon={<EditRegular />} onClick={() => navigate(`/office/adjustments/${a.id}/edit`)}>Edit</Button>}
          {a.actions.submit && <Button icon={<SendRegular />} onClick={() => setAsk({ action: 'submit', title: 'Submit for approval', reason: false, go: 'Submit',
            text: 'A manager checks it and posts it. Until then nothing changes in stock.' })}>Submit for approval</Button>}
          {a.actions.post && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => setAsk({ action: 'post', title: a.actions.post!, reason: false, go: a.actions.post!,
            text: 'The changes are booked in the stock ledger now, against the stock on hand at this moment. A posted adjustment cannot be changed — correct it with another adjustment.' })}>{a.actions.post}</Button>}
          {a.actions.reopen && <Button icon={<ArrowUndoRegular />} onClick={() => setAsk({ action: 'reopen', title: 'Send back to draft', reason: true, go: 'Send back',
            text: 'It goes back to the person who prepared it, to correct. Say why.' })}>Send back to draft</Button>}
          {a.actions.cancel && <Button icon={<DismissCircleRegular />} onClick={() => setAsk({ action: 'cancel', title: 'Cancel the adjustment', reason: true, go: 'Cancel it',
            text: 'Nothing is booked; the adjustment stays on record as cancelled, with your reason.' })}>Cancel</Button>}
          <Menu>
            <MenuTrigger disableButtonEnhancement><Button icon={<ArrowDownloadRegular />}>Export</Button></MenuTrigger>
            <MenuPopover><MenuList>
              <MenuItem onClick={() => voucher('pdf')}>Adjustment voucher (PDF)</MenuItem>
              <MenuItem onClick={() => voucher('csv')}>Lines (CSV)</MenuItem>
            </MenuList></MenuPopover>
          </Menu>
          {a.actions.import && <Button icon={<ArrowUploadRegular />} onClick={() => navigate(a.actions.import!)}>Add lines from CSV</Button>}
          {a.actions.disposal_record && <Button icon={<PrintRegular />} onClick={() => window.open(a.actions.disposal_record!, '_blank', 'noopener')}>Disposal record</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {a.over_limit && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>
        Worth more than {a.over_limit}: someone other than the person who prepared it must approve it. Submit it for approval.
      </MessageBarBody></MessageBar>}

      <Panel title="Details">
        <div className={s.facts}>{Object.entries(a.facts).map(([k, v]) => <div key={k}><Text size={200} block className={s.muted}>{k}</Text><Text weight="semibold">{v}</Text></div>)}</div>
        {a.note && <Text block style={{ marginTop: 10 }}>{a.note}</Text>}
      </Panel>
      {a.disposal && (
        <Panel title="Disposal">
          <Text block size={200} className={s.muted} style={{ marginBottom: 8 }}>Removed from stock once approved by someone other than the person who prepared it. Keep the packs aside, marked "not for sale", until they are destroyed.</Text>
          <div className={s.facts}>
            <Fact k="Method" v={a.disposal.method ?? '—'} /><Fact k="Disposed of on" v={a.disposal.disposed_on ?? '—'} /><Fact k="Witness" v={a.disposal.witness ?? '—'} />
            <div><Text size={200} block className={s.muted}>Certificate</Text>{a.disposal.certificate ? <Link href={a.disposal.certificate} target="_blank" rel="noopener">Open</Link> : <Text weight="semibold">none</Text>}</div>
          </div>
        </Panel>
      )}
      <Panel title="Lines">
        <Table size="small" aria-label="Lines">
          <TableHeader><TableRow>{a.lines.headings.map((h, i) => <TableHeaderCell key={i} className={a.lines.numeric.includes(i) ? s.end : undefined} button={a.lines.numeric.includes(i) ? END : undefined}>{h}</TableHeaderCell>)}</TableRow></TableHeader>
          <TableBody>{a.lines.rows.map((r, ri) => <TableRow key={ri}>{r.map((c, ci) => <TableCell key={ci} className={a.lines.numeric.includes(ci) ? s.end : undefined}>{c}</TableCell>)}</TableRow>)}</TableBody>
        </Table>
        <Text block weight="semibold" style={{ marginTop: 10 }}>{a.value_note}: <span style={{ color: a.value < 0 ? 'var(--zx-bad)' : undefined }}>{m(a.value)}</span></Text>
        {a.photos.length > 0 && <Text block size={200} style={{ marginTop: 6 }}>Photos: {a.photos.map((p, i) => <span key={p.line}>{i > 0 && ' · '}<Link href={p.url} target="_blank" rel="noopener">Line {p.line}</Link></span>)}</Text>}
      </Panel>

      {ask && <Confirm ask={ask} onClose={() => setAsk(null)} onDone={(next, text) => { setA(next); setAsk(null); setError(''); setDone(text) }} id={a.id} />}
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}

function Fact({ k, v }: { k: string; v: string }) {
  const s = useStyles()
  return <div><Text size={200} block className={s.muted}>{k}</Text><Text weight="semibold">{v}</Text></div>
}

/** Say what the action does; a reason where one is kept (send back, cancel); then do it. */
function Confirm({ id, ask, onClose, onDone }: { id: string; ask: Ask; onClose: () => void; onDone: (a: AdjDetail, text: string) => void }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const said = { submit: 'Submitted for approval.', post: 'Posted to stock.', reopen: 'Sent back to draft.', cancel: 'Cancelled.' }[ask.action]
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 480 }}>
        <DialogBody>
          <DialogTitle>{ask.title}</DialogTitle>
          <DialogContent>
            <Text block>{ask.text}</Text>
            {ask.reason && <Field label="Reason" required style={{ marginTop: 10 }}><Input value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} /></Field>}
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 8 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Back</Button>
            <Button appearance="primary" disabled={busy || (ask.reason && !reason.trim())} onClick={() => {
              setBusy(true)
              setError('')
              api.adjustmentAction(id, ask.action, ask.reason ? reason.trim() : undefined).then((a) => onDone(a, said))
                .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Working…' : ask.go}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
