import { Button, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type LeaveRow, type MyHrData } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { LeaveAsk } from './LeavePage'
import { day, END, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * HR & payroll → My leave & payslips (part 21): your leave left this year by type, your requests (cancel one not started
 * yet), Request leave, and your payslips once the payroll is approved.
 */
export function MyHrPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<MyHrData | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [asking, setAsking] = useState(false)
  const [cancel, setCancel] = useState<LeaveRow | null>(null)
  const load = useCallback(() => { api.myHr().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening your leave and payslips…" /></div>
  const finish = (m: string) => { setAsking(false); setCancel(null); setDone(m); load() }
  return (
    <div className={page.page}>
      <PageHead title="My leave & payslips" hint={`${d.name}: your leave and your payslips.`} />
      <div className={s.bar}><span className={s.grow} /><Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setAsking(true) }}>Request leave</Button></div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Leave left this year">
        <Cards items={d.balances.map((b) => ({ label: `${b.name} (${b.allowance === null ? 'as needed' : `of ${b.allowance} days`})`, value: b.left === null ? '—' : b.left }))} />
      </Panel>
      <Panel title="My leave requests">
        <Table size="small" aria-label="My leave requests" style={{ minWidth: 560 }}>
          <TableHeader><TableRow><TableHeaderCell>Type</TableHeaderCell><TableHeaderCell>From</TableHeaderCell><TableHeaderCell>To</TableHeaderCell>
            <TableHeaderCell button={END}>Days</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {d.requests.length === 0 && <TableRow><TableCell colSpan={6}><Text className={s.muted}>No leave requested yet.</Text></TableCell></TableRow>}
            {d.requests.map((r) => (
              <TableRow key={r.id}><TableCell>{r.type}</TableCell><TableCell>{day(r.starts_on)}</TableCell><TableCell>{day(r.ends_on)}</TableCell><TableCell className={s.end}>{r.days}</TableCell>
                <TableCell><ToneBadge tone={r.tone} label={r.status_label} />{r.note && <Text size={200} className={s.muted}> — {r.note}</Text>}</TableCell>
                <TableCell>{r.can_cancel && <Button size="small" appearance="subtle" onClick={() => setCancel(r)}>Cancel</Button>}</TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
      <Panel title="My payslips">
        <Table size="small" aria-label="My payslips">
          <TableBody>
            {d.payslips.length === 0 && <TableRow><TableCell><Text className={s.muted}>No payslips yet.</Text></TableCell></TableRow>}
            {d.payslips.map((p) => (
              <TableRow key={p.id}><TableCell>{p.month}</TableCell><TableCell className={s.end}>{money(p.net, d.currency, d.exponent)}</TableCell>
                <TableCell><Link href={p.open} target="_blank" rel="noopener">Open payslip</Link></TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
      {asking && <LeaveAsk mine onClose={() => setAsking(false)} onDone={finish} />}
      {cancel && (
        <Ask title="Cancel this leave?" go="Cancel the leave" ready onClose={() => setCancel(null)} run={() => api.leaveAction(cancel.id, 'cancel', { mine: true }).then((x) => finish(x.message))}>
          <Text>{cancel.type}, {day(cancel.starts_on)} to {day(cancel.ends_on)}.</Text>
        </Ask>
      )}
    </div>
  )
}
