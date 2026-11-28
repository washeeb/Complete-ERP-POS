import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Field, Input, makeStyles, MessageBar, MessageBarBody,
  Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, MoneyHandRegular, DocumentPdfRegular, EditRegular, LockClosedRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ShiftDetail } from '../api'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { VaultCount } from './VaultCount'

const TONE: Record<string, string> = { good: 'var(--zx-good)', warn: 'var(--zx-warn)', bad: 'var(--zx-bad)' }

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '6px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  tiles: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: '10px', margin: '12px 0' },
  tile: { padding: '12px 14px', borderRadius: tokens.borderRadiusLarge, backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}` },
  tileValue: { display: 'block', fontSize: '20px', fontWeight: tokens.fontWeightBold, fontVariantNumeric: 'tabular-nums' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '10px 18px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  foot: { fontWeight: tokens.fontWeightSemibold, backgroundColor: tokens.colorNeutralBackground2 },
})

/**
 * One till shift (release 2): the shift report's own tiles, facts and tables (sales by payment method, the cash, the
 * MoMo/card checks, the drawer, coupons), the report as a PDF, and the current screen's actions: correct a mistyped
 * count, close a shift no till can close, and the manager's blind vault count (VaultCount).
 */
export function ShiftPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [shift, setShift] = useState<ShiftDetail | null>(null)
  const [error, setError] = useState('')
  const [action, setAction] = useState<'close' | 'correct' | null>(null)
  const [counting, setCounting] = useState(false)
  const [done, setDone] = useState('')

  useEffect(() => {
    api.shift(id).then((x) => { setShift(x); onTitle(`Shift ${x.where}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (error) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!shift) return <div className={page.page}><Spinner label="Opening the shift…" /></div>
  const m = (v: number | null) => (v === null ? '—' : money(v, shift.currency, shift.exponent))

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/shifts'))}>Till shifts</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          Shift {shift.where}{shift.shift_name ? ` · ${shift.shift_name}` : ''}
          {shift.status === 'open' ? <Badge appearance="tint" color={shift.left_open ? 'danger' : 'success'}>{shift.left_open ? `Left open since ${shift.left_open}` : 'Open'}</Badge>
            : <Badge appearance="tint" color="informative">Closed</Badge>}
        </h1>
        <div className={s.actions}>
          {shift.actions.report && <Button appearance="primary" icon={<DocumentPdfRegular />} onClick={() => window.open(api.shiftReportUrl(shift.id), '_blank', 'noopener')}>Shift report</Button>}
          {shift.actions.correct && <Button icon={<EditRegular />} onClick={() => setAction('correct')}>Correct the count</Button>}
          {shift.actions.close && <Button icon={<LockClosedRegular />} onClick={() => setAction('close')}>Close shift</Button>}
          {shift.actions.count_vault && <Button appearance="primary" icon={<MoneyHandRegular />} onClick={() => setCounting(true)}>Count the vault</Button>}
        </div>
      </div>
      <Text className={s.muted}>{shift.branch}</Text>
      {done && <MessageBar intent="success" style={{ margin: '12px 0' }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      <div className={s.tiles}>
        {shift.tiles.map((t) => (
          <div key={t.label} className={s.tile}>
            <Text size={200} className={s.muted}>{t.label}</Text>
            <span className={s.tileValue} style={t.tone ? { color: TONE[t.tone] } : undefined}>{t.value}</span>
          </div>
        ))}
      </div>

      <section className={s.panel}>
        <div className={s.facts}>
          {Object.entries(shift.facts).map(([k, v]) => <div key={k}><Text size={200} block className={s.muted}>{k}</Text><Text weight="semibold">{v}</Text></div>)}
        </div>
      </section>

      {shift.sheets.map((sh) => (
        <section key={sh.title} className={s.panel}>
          <h2 className={s.h2}>{sh.title}</h2>
          {sh.rows.length === 0 ? <Text className={s.muted}>Nothing here for this shift.</Text> : (
            <Table size="small" aria-label={sh.title}>
              <TableHeader><TableRow>{sh.headings.map((h, i) => (
                <TableHeaderCell key={i} className={sh.numeric?.includes(i) ? s.end : undefined} button={sh.numeric?.includes(i) ? { style: { justifyContent: 'flex-end' } } : undefined}>{h}</TableHeaderCell>
              ))}</TableRow></TableHeader>
              <TableBody>
                {sh.rows.map((r, ri) => <TableRow key={ri}>{r.map((c, ci) => <TableCell key={ci} className={sh.numeric?.includes(ci) ? s.end : undefined}>{c}</TableCell>)}</TableRow>)}
                {sh.foot && <TableRow className={s.foot}>{sh.foot.map((c, ci) => <TableCell key={ci} className={sh.numeric?.includes(ci) ? s.end : undefined}>{c}</TableCell>)}</TableRow>}
              </TableBody>
            </Table>
          )}
        </section>
      ))}

      {counting && <VaultCount shift={shift} onCancel={() => setCounting(false)}
        onDone={(next) => { setShift(next); setCounting(false); setDone('Vault counted. Your count is booked; the over/short is worked out from it.') }} />}
      {action && <CountDialog shift={shift} kind={action} money={m} onCancel={() => setAction(null)}
        onDone={(next) => { setShift(next); setAction(null); setDone(action === 'close' ? 'Shift closed. The over/short goes to the books as for any close.' : 'Count corrected. The difference is posted; the original count stays on the shift.') }} />}
    </div>
  )
}

/** Close a shift no till can close, or correct a mistyped count: the amount and why, both kept with your name. */
function CountDialog({ shift, kind, money, onDone, onCancel }: {
  shift: ShiftDetail; kind: 'close' | 'correct'; money: (v: number | null) => string; onDone: (s: ShiftDetail) => void; onCancel: () => void
}) {
  const [counted, setCounted] = useState(kind === 'close' ? '0' : '')
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const save = () => {
    setBusy(true)
    setError('')
    ;(kind === 'close' ? api.closeShift : api.correctShift)(shift.id, counted, reason.trim())
      .then(onDone).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onCancel()}>
      <DialogSurface>
        <DialogBody>
          <DialogTitle>{kind === 'close' ? 'Close the shift here' : 'Correct the count'}</DialogTitle>
          <DialogContent>
            <Text block style={{ marginBottom: 10 }}>
              {kind === 'close' && shift.cash_vault
                ? 'For a shift no till can close (the till was wiped, reinstalled or replaced, or it was left open). It is on a cash-control vault: count the cash in the drawer without knowing what is expected. Your count stands for the teller’s; the cashier then receives the vault as usual (Cash → Shifts and vaults).'
                : kind === 'close'
                ? `For a shift no till can close (the till was wiped, reinstalled or replaced, or it was left open). Expected in the drawer: ${money(shift.expected_now)}. The over/short goes to the books as for any close.`
                : `Recorded count: ${money(shift.counted)}; expected: ${money(shift.expected)}. The shift keeps its original count; the correction and the difference in the books are recorded with your name.`}
            </Text>
            <Field label={kind === 'close' ? 'Cash actually there' : 'The right count'} required>
              <Input type="number" min={0} value={counted} onChange={(_, d) => setCounted(d.value)} />
            </Field>
            <Field label={kind === 'close' ? 'Why it is closed here' : 'Why'} required style={{ marginTop: 8 }}>
              <Input value={reason} onChange={(_, d) => setReason(d.value)} maxLength={200}
                placeholder={kind === 'close' ? 'e.g. Till reinstalled; test shift' : 'e.g. Typed 4000 instead of 40; cash found in the safe'} />
            </Field>
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 8 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onCancel}>Cancel</Button>
            <Button appearance="primary" disabled={busy || counted === '' || !reason.trim()} onClick={save}>{busy ? 'Saving…' : kind === 'close' ? 'Close shift' : 'Save the correction'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
