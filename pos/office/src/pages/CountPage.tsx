import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Field, Input, makeStyles, Menu, MenuItem, MenuList, MenuPopover,
  MenuTrigger, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import {
  AddRegular, ArrowDownloadRegular, ArrowLeftRegular, ArrowUploadRegular, CheckmarkCircleRegular, DeleteRegular, DismissCircleRegular, EditRegular, SendRegular,
} from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type CountDetail, type Option as Choice } from '../api'
import { UnitPicker } from '../form/UnitPicker'
import { download } from '../list/ExportMenu'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { COUNT_STATUS } from './CountsPage'

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
  item: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 110px 32px', gap: '8px', alignItems: 'end', marginBottom: '8px' },
})

const END = { style: { justifyContent: 'flex-end' } }
type Ask = { action: 'submit' | 'post' | 'cancel'; title: string; text: string; reason: boolean; go: string }

/** Short is red, over is amber, exact is green; no difference yet (not counted) has no colour. */
export const diffColour = (d: string | null) => (d === null ? undefined : d === '0' ? 'var(--zx-good)' : d.startsWith('-') ? 'var(--zx-bad)' : 'var(--zx-warn)')

/** Lines that name the same product twice: only the later count would stand (a recount replaces). */
export function repeated(items: { product_id: string }[]): number[] {
  return items.flatMap((it, i) => (it.product_id && items.findIndex((x) => x.product_id === it.product_id) < i ? [i + 1] : []))
}

/**
 * One stock count (release 3): what was counted, by whom, and — unless it is a blind count still being counted — what
 * was expected and the difference. Enter counts (several products at once, in any of their units; a product counted
 * again replaces its earlier count), submit it, and someone else approves and posts it. The count sheet to print or to
 * fill in Excel, and the import of a filled-in sheet, are here too.
 */
export function CountPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [c, setC] = useState<CountDetail | null>(null)
  const [error, setError] = useState('')
  const [ask, setAsk] = useState<Ask | null>(null)
  const [entering, setEntering] = useState(false)
  const [done, setDone] = useState('')
  const load = useCallback(() => {
    api.count(id).then((x) => { setC(x); onTitle(`Count ${x.number}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !c) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!c) return <div className={page.page}><Spinner label="Opening the count…" /></div>
  const sheet = (format: 'pdf' | 'csv') => download(api.countSheetUrl(c.id, format), `${c.number}.${format}`).then((p) => p && setError(p))

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/counts'))}>Stock counts</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          Count {c.number}
          <Badge appearance="tint" color={COUNT_STATUS[c.status] ?? 'informative'}>{c.status_label}</Badge>
          {c.blind && <Badge appearance="outline">Blind</Badge>}
        </h1>
        <div className={s.actions}>
          {c.actions.count && <Button appearance="primary" icon={<EditRegular />} onClick={() => setEntering(true)}>Enter counts</Button>}
          {c.actions.submit && <Button icon={<SendRegular />} onClick={() => setAsk({ action: 'submit', title: 'Submit for approval', reason: false, go: 'Submit',
            text: 'Products nobody counted are left unchanged (not set to zero). Someone else then approves the count and posts the differences.' })}>Submit for approval</Button>}
          {c.actions.post && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => setAsk({ action: 'post', title: 'Approve and post', reason: false, go: 'Approve and post',
            text: 'Books the differences into stock as count movements.' })}>Approve and post</Button>}
          {c.actions.cancel && <Button icon={<DismissCircleRegular />} onClick={() => setAsk({ action: 'cancel', title: 'Cancel the count', reason: true, go: 'Cancel it',
            text: 'Nothing is booked; the count stays on record as cancelled, with your reason.' })}>Cancel count</Button>}
          <Menu>
            <MenuTrigger disableButtonEnhancement><Button icon={<ArrowDownloadRegular />}>Count sheet</Button></MenuTrigger>
            <MenuPopover><MenuList>
              <MenuItem onClick={() => sheet('pdf')}>Count sheet (PDF, to count on paper)</MenuItem>
              <MenuItem onClick={() => sheet('csv')}>Count sheet (CSV, to fill in and import)</MenuItem>
            </MenuList></MenuPopover>
          </Menu>
          {c.actions.import && <Button icon={<ArrowUploadRegular />} onClick={() => navigate(c.actions.import!)}>Import counted quantities (CSV)</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {c.blind && !c.shows_expected && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>
        A blind count: the expected quantities stay hidden until it is submitted, so what is counted is what is there.
      </MessageBarBody></MessageBar>}

      <Panel title="Details">
        <div className={s.facts}>{Object.entries(c.facts).map(([k, v]) => <div key={k}><Text size={200} block className={s.muted}>{k}</Text><Text weight="semibold">{v}</Text></div>)}</div>
        {c.note && <Text block style={{ marginTop: 10 }}>{c.note}</Text>}
      </Panel>
      <Panel title={`Lines · ${c.counted} counted`}>
        {c.lines.length === 0 ? <Text className={s.muted}>Nothing counted yet.</Text> : (
          <Table size="small" aria-label="Lines">
            <TableHeader><TableRow>
              <TableHeaderCell>Product</TableHeaderCell><TableHeaderCell>Bin</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Counted</TableHeaderCell>
              {c.shows_expected && <><TableHeaderCell className={s.end} button={END}>Expected</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Difference</TableHeaderCell></>}
              <TableHeaderCell>Counted by</TableHeaderCell>
            </TableRow></TableHeader>
            <TableBody>{c.lines.map((l, i) => (
              <TableRow key={i}>
                <TableCell><Text weight="semibold">{l.product}</Text><Text block size={200} font="monospace" className={s.muted}>{l.sku}</Text></TableCell>
                <TableCell>{l.bin ?? '—'}</TableCell>
                <TableCell className={s.end}>{l.counted === null ? <Text className={s.muted}>not counted</Text> : `${l.counted} ${l.unit ?? ''}`}</TableCell>
                {c.shows_expected && <>
                  <TableCell className={s.end}>{l.expected ?? '—'}</TableCell>
                  <TableCell className={s.end}><Text weight="semibold" style={{ color: diffColour(l.difference) }}>
                    {l.difference === null ? '—' : `${l.difference.startsWith('-') || l.difference === '0' ? '' : '+'}${l.difference}`}</Text></TableCell>
                </>}
                <TableCell>{l.by ?? '—'}</TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </Panel>

      {entering && <EnterCounts c={c} onClose={() => setEntering(false)} onSaved={(next, n) => { setC(next); setEntering(false); setError(''); setDone(`${n} count(s) saved.`) }} />}
      {ask && <Confirm id={c.id} ask={ask} onClose={() => setAsk(null)}
        onDone={(next, text) => { setC(next); setAsk(null); setError(''); setDone(text) }} />}
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  const s = useStyles()
  return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
}

/** Counted quantities, several products at once: product and unit (scan or type), and how many were counted. */
function EnterCounts({ c, onClose, onSaved }: { c: CountDetail; onClose: () => void; onSaved: (c: CountDetail, n: number) => void }) {
  const s = useStyles()
  const [bin, setBin] = useState('')
  const [items, setItems] = useState([{ k: 1, product_uom_id: '', product_id: '', label: null as string | null, qty: '' }])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const twice = repeated(items)
  const ready = (c.bins.length === 0 || bin) && items.every((i) => i.product_uom_id && i.qty.trim() !== '')
  const set = (k: number, v: Partial<(typeof items)[number]>) => setItems((xs) => xs.map((x) => (x.k === k ? { ...x, ...v } : x)))
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 640 }}>
        <DialogBody>
          <DialogTitle>Enter counts</DialogTitle>
          <DialogContent>
            <Text block size={200} className={s.muted} style={{ marginBottom: 10 }}>Count each product in whichever unit is easiest. A product counted again replaces its earlier count (a recount).</Text>
            {c.bins.length > 0 && (
              <Field label="Bin" required style={{ marginBottom: 10 }}>
                <Dropdown placeholder="Scan or choose the bin" value={c.bins.find((b: Choice) => b.id === bin)?.label ?? ''} selectedOptions={bin ? [bin] : []}
                  onOptionSelect={(_, d) => setBin(d.optionValue ?? '')}>
                  {c.bins.map((b: Choice) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
                </Dropdown>
              </Field>
            )}
            {items.map((it, i) => (
              <div key={it.k} className={s.item}>
                <UnitPicker value={it.product_uom_id} label={it.label} onChange={(u) => set(it.k, { product_uom_id: u?.id ?? '', product_id: u?.product_id ?? '', label: u?.label ?? null })} />
                <Field label="Counted" required style={{ minWidth: 0 }}>
                  <Input style={{ width: '100%', minWidth: 0 }} type="number" min={0} step="any" inputMode="decimal" value={it.qty} onChange={(_, d) => set(it.k, { qty: d.value })} />
                </Field>
                {items.length > 1 ? <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${i + 1}`} onClick={() => setItems((xs) => xs.filter((x) => x.k !== it.k))} /> : <span />}
              </div>
            ))}
            {twice.length > 0 && <Text block size={200} style={{ color: 'var(--zx-warn)' }}>Line {twice.join(', ')} counts a product already above: only the later count would stand. Add them up on one line.</Text>}
            <Button appearance="subtle" icon={<AddRegular />} onClick={() => setItems((xs) => [...xs, { k: Math.max(...xs.map((x) => x.k)) + 1, product_uom_id: '', product_id: '', label: null, qty: '' }])}>Add another product</Button>
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 8 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Cancel</Button>
            <Button appearance="primary" disabled={busy || !ready || twice.length > 0} onClick={() => {
              setBusy(true)
              setError('')
              api.recordCount(c.id, items.map((x) => ({ product_uom_id: x.product_uom_id, qty: x.qty.trim() })), bin || null)
                .then((next) => onSaved(next, items.length)).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Saving…' : 'Save the counts'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}

function Confirm({ id, ask, onClose, onDone }: { id: string; ask: Ask; onClose: () => void; onDone: (c: CountDetail, text: string) => void }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
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
              api.countAction(id, ask.action, ask.reason ? reason.trim() : undefined)
                .then((c) => onDone(c, ask.action === 'post' ? `Posted: ${c.posted_lines ?? 0} difference(s) booked into stock.` : ask.action === 'submit' ? 'Submitted for approval.' : 'Cancelled.'))
                .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Working…' : ask.go}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
