import {
  Badge, Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { AddRegular, LockClosedRegular, LockOpenRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type MonthEnd, type PeriodRow } from '../api'
import { Ask } from '../form/Ask'
import { PageHead, usePageStyles } from '../list/ListParts'
import { str, useQueryParams } from '../list/query'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '6px 10px', overflowX: 'auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

/**
 * Month-end (release 8): the financial year's twelve months, open or closed, who closed each and when. Close a month
 * once everything dated in it is posted and the ledger ties to its sub-ledgers (the page says what is still missing);
 * nothing more can then be posted into it. Reopen needs its own permission and a reason. Set up a financial year adds
 * its months.
 */
export function MonthEndPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<MonthEnd | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [nonce, setNonce] = useState(0)
  const [closing, setClosing] = useState<PeriodRow | null>(null)
  const [reopening, setReopening] = useState<PeriodRow | null>(null)
  const [setup, setSetup] = useState(false)
  const year = str(params, 'year')
  useEffect(() => { api.monthEnd(year).then((d) => { setData(d); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [year, nonce])

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Reading the months…" />}</div>
  const refresh = (m: string) => { setDone(m); setNonce((n) => n + 1) }

  return (
    <div className={page.page}>
      <PageHead title="Month-end" hint="Close each month once everything in it is posted and the books tie. A closed month takes no more postings; reopening needs a reason." classic="/admin/fiscal-periods" />
      <div className={s.bar}>
        <Field label="Financial year">
          <Dropdown aria-label="Financial year" style={{ minWidth: 130 }} value={`FY${data.year}`} selectedOptions={[String(data.year)]} onOptionSelect={(_, d) => d.optionValue && set({ year: d.optionValue })}>
            {data.years.map((y) => <Option key={y.id} value={y.id}>{y.label}</Option>)}
          </Dropdown>
        </Field>
        <span className={s.grow} />
        {data.can_setup && <Button icon={<AddRegular />} onClick={() => { setDone(''); setSetup(true) }}>Set up a financial year</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <section className={s.panel} aria-label="Months">
        <Table size="small" aria-label="Months" style={{ minWidth: 600 }}>
          <TableHeader><TableRow><TableHeaderCell>Month</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell>Closed by</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>{data.rows.map((p) => (
            <TableRow key={p.id}>
              <TableCell>{p.month}<Text block size={200} className={s.muted}>FY{data.year} · month {p.period}</Text></TableCell>
              <TableCell><Badge appearance="tint" color={p.status === 'closed' ? 'success' : 'warning'}>{p.status === 'closed' ? 'Closed' : 'Open'}</Badge></TableCell>
              <TableCell>{p.closed_by ?? '—'}{p.closed_at && <Text block size={200} className={s.muted}>{p.closed_at}</Text>}</TableCell>
              <TableCell>
                {p.can_close && <Button size="small" icon={<LockClosedRegular />} onClick={() => { setDone(''); setClosing(p) }}>Close month</Button>}
                {p.can_reopen && <Button size="small" appearance="subtle" icon={<LockOpenRegular />} style={{ color: 'var(--zx-bad)' }} onClick={() => { setDone(''); setReopening(p) }}>Reopen</Button>}
              </TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </section>
      {closing && <Ask title={`Close ${closing.month}?`} go="Close the month" ready={closing.blockers.length === 0} onClose={() => setClosing(null)}
        run={async () => { const r = await api.closeMonth(closing.id); setClosing(null); refresh(r.message) }}>
        {closing.blockers.length ? <Text>Not ready yet: {closing.blockers.join(' ')}</Text>
          : <Text>Everything dated in this month is posted and the ledger ties to its sub-ledgers. Once closed, nothing more can be posted into it.</Text>}
      </Ask>}
      {reopening && <Reopen period={reopening} onClose={() => setReopening(null)} onDone={(m) => { setReopening(null); refresh(m) }} />}
      {setup && <SetupYear onClose={() => setSetup(false)} onDone={(y) => { setSetup(false); setDone(`FY${y} set up.`); set({ year: String(y) }); setNonce((n) => n + 1) }} />}
    </div>
  )
}

function Reopen({ period, onClose, onDone }: { period: PeriodRow; onClose: () => void; onDone: (m: string) => void }) {
  const s = useStyles()
  const [reason, setReason] = useState('')
  return (
    <Ask title={`Reopen ${period.month}?`} go="Reopen" ready={reason.trim() !== ''} onClose={onClose} run={async () => onDone((await api.reopenMonth(period.id, reason)).message)}>
      <div className={s.form}>
        <Text>Postings dated in the month are allowed again until it is closed again. The reason is kept in the audit log.</Text>
        <Field label="Why" required><Input value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} /></Field>
      </div>
    </Ask>
  )
}

function SetupYear({ onClose, onDone }: { onClose: () => void; onDone: (year: number) => void }) {
  const s = useStyles()
  const [year, setYear] = useState(String(new Date().getFullYear()))
  const n = Number(year)
  return (
    <Ask title="Set up a financial year" go="Set it up" ready={Number.isInteger(n) && n >= 2000 && n <= 2100} onClose={onClose} run={async () => onDone((await api.setupYear(n)).year)}>
      <div className={s.form}>
        <Field label="Year (the calendar year it ends in)" required><Input type="number" min={2000} max={2100} value={year} onChange={(_, d) => setYear(d.value)} /></Field>
      </div>
    </Ask>
  )
}
