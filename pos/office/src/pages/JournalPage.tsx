import {
  Badge, Button, Field, Input, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, ArrowUndoRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type JournalDetail } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '10px 16px', marginBottom: '12px' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', overflowX: 'auto', marginBottom: '12px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  total: { fontWeight: tokens.fontWeightBold, borderTop: `2px solid ${tokens.colorNeutralForeground1}` },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

const END = { style: { justifyContent: 'flex-end' } }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * One journal (release 8): its date, kind, description and who posted it; the lines (debit and credit, by account and
 * branch); what it reverses or what reversed it. Reverse posts the opposite journal on the date chosen, with the
 * reason — a journal typed by hand or opening balances only; automatic ones are corrected at their source.
 */
export function JournalPage({ id }: { id: string }) {
  const page = usePageStyles()
  const s = useStyles()
  const [j, setJ] = useState<JournalDetail | null>(null)
  const [error, setError] = useState('')
  const [reversing, setReversing] = useState(false)
  useEffect(() => { setJ(null); api.journal(id).then((d) => setJ(d.journal)).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [id])

  if (error && !j) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!j) return <div className={page.page}><Spinner label="Opening the journal…" /></div>
  const m = (v: number) => (v ? money(v, j.currency, j.exponent) : '')
  const fact = (label: string, value: React.ReactNode) => <div><Text block size={200} className={s.muted}>{label}</Text><Text>{value}</Text></div>

  return (
    <div className={page.page}>
      <PageHead title={`Journal ${j.number}`} hint={j.memo ?? 'A journal in the ledger.'} classic={`/admin/journal-entries/${j.id}`} />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/journals')}>Journals</Button>
        <span className={s.grow} />
        {j.can_reverse && <Button icon={<ArrowUndoRegular />} onClick={() => setReversing(true)} style={{ color: 'var(--zx-bad)' }}>Reverse</Button>}
      </div>
      <div className={s.facts}>
        {fact('Date', day(j.date))}
        {fact('Kind', <Badge appearance="tint" color={j.kind === 'auto' ? 'subtle' : 'brand'}>{j.kind_label}</Badge>)}
        {fact('Posted by', j.by ?? '—')}
        {fact('Reverses', j.reverses ? <Link onClick={() => navigate(`/office/journals/${j.reverses!.id}`)}>{j.reverses.number}</Link> : '—')}
        {fact('Reversed by', j.reversed_by ? <Link onClick={() => navigate(`/office/journals/${j.reversed_by!.id}`)}>{j.reversed_by.number}</Link> : '—')}
      </div>
      <section className={s.panel} aria-label="Journal lines">
        <Table size="small" aria-label="Journal lines" style={{ minWidth: 560 }}>
          <TableHeader><TableRow><TableHeaderCell>Account</TableHeaderCell><TableHeaderCell>Branch</TableHeaderCell><TableHeaderCell>Line</TableHeaderCell>
            <TableHeaderCell className={s.end} button={END}>Debit</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Credit</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {j.lines.map((l, i) => (
              <TableRow key={i}>
                <TableCell><Link onClick={() => navigate(`/office/financial-reports?report=ledger&account=${l.account_id}&from=${j.date}&to=${j.date}`)}>{l.account}</Link></TableCell>
                <TableCell>{l.branch ?? '—'}</TableCell><TableCell>{l.memo ?? ''}</TableCell>
                <TableCell className={s.end}>{m(l.debit)}</TableCell><TableCell className={s.end}>{m(l.credit)}</TableCell>
              </TableRow>
            ))}
            <TableRow>
              <TableCell className={s.total} colSpan={3}>Total</TableCell>
              <TableCell className={`${s.end} ${s.total}`}>{money(j.debits, j.currency, j.exponent)}</TableCell>
              <TableCell className={`${s.end} ${s.total}`}>{money(j.credits, j.currency, j.exponent)}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </section>
      {reversing && <Reverse id={j.id} number={j.number} onClose={() => setReversing(false)} onDone={(open) => navigate(open)} />}
    </div>
  )
}

function Reverse({ id, number, onClose, onDone }: { id: string; number: string; onClose: () => void; onDone: (open: string) => void }) {
  const s = useStyles()
  const today = new Date().toISOString().slice(0, 10)
  const [date, setDate] = useState(today)
  const [reason, setReason] = useState('')
  return (
    <Ask title={`Reverse ${number}?`} go="Post the reversal" ready={date !== '' && reason.trim() !== ''} onClose={onClose}
      run={async () => onDone((await api.reverseJournal(id, date, reason)).journal.open)}>
      <div className={s.form}>
        <Text>Posts the same lines the other way round on the date chosen, so the two cancel out. The journal itself stays as it is.</Text>
        <Field label="Date" required><Input type="date" value={date} max={today} onChange={(_, d) => setDate(d.value)} /></Field>
        <Field label="Why" required><Input value={reason} maxLength={200} onChange={(_, d) => setReason(d.value)} /></Field>
      </div>
    </Ask>
  )
}
