import { Button, Field, Input, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type CommissionData } from '../api'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { END, Panel, useProcStyles } from './ProcurementParts'

/**
 * Services → Commissions (part 26): commission earned by each staff member in a period (net of returns), and the lines
 * behind it. Paid with payroll as the earning "Commission". Dates and the staff member live in the address.
 */
export function CommissionsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [d, setD] = useState<CommissionData | null>(null)
  const [error, setError] = useState('')
  const query = serialize(params)
  useEffect(() => {
    const c = new AbortController()
    api.commissions(query, c.signal).then((x) => { setD(x); setError('') }).catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
    return () => c.abort()
  }, [query])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Adding up commission…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  return (
    <div className={page.page}>
      <PageHead title="Commissions" hint="Commission earned by each staff member in a period, net of returns. Paid with payroll." classic={`/admin/commissions${query ? `?${query}` : ''}`} />
      <div className={s.bar} style={{ alignItems: 'flex-end' }}>
        <Field label="From"><Input type="date" value={d.from} onChange={(_, e) => e.value && set({ from: e.value })} /></Field>
        <Field label="To"><Input type="date" value={d.to} onChange={(_, e) => e.value && set({ to: e.value })} /></Field>
      </div>
      <Panel title="By staff member" about="Commission on services done (and items sold) in the period, less returns. Payroll pays each month's commission as the earning “Commission”.">
        <Table size="small" aria-label="Commission by staff member" style={{ minWidth: 560 }}>
          <TableHeader><TableRow><TableHeaderCell>Staff member</TableHeaderCell><TableHeaderCell button={END}>Lines</TableHeaderCell><TableHeaderCell button={END}>Value (before tax)</TableHeaderCell>
            <TableHeaderCell button={END}>Commission</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {d.rows.length === 0 && <TableRow><TableCell colSpan={5}><Text className={s.muted}>No commission in this period. Set rates under HR → Employees (Commission %) or on the product (Services section).</Text></TableCell></TableRow>}
            {d.rows.map((r) => (
              <TableRow key={r.id}><TableCell>{r.name} <Text size={200} className={s.muted}>{r.number}</Text></TableCell><TableCell className={s.end}>{r.lines}</TableCell>
                <TableCell className={s.end}>{m(r.value)}</TableCell><TableCell className={s.end}><b>{m(r.amount)}</b></TableCell>
                <TableCell><Button size="small" appearance={d.employee === r.id ? 'primary' : 'secondary'} onClick={() => set({ employee: r.id })}>Details</Button></TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
      {d.employee && (
        <Panel title="Details">
          <Table size="small" aria-label="Commission lines" style={{ minWidth: 640 }}>
            <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>Receipt</TableHeaderCell><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell button={END}>Value</TableHeaderCell>
              <TableHeaderCell button={END}>Rate</TableHeaderCell><TableHeaderCell button={END}>Commission</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{d.lines.map((l) => (
              <TableRow key={l.id}><TableCell>{l.when}</TableCell><TableCell>{l.receipt}</TableCell><TableCell>{l.item}</TableCell><TableCell className={s.end}>{m(l.value)}</TableCell>
                <TableCell className={s.end}>{l.rate}</TableCell><TableCell className={s.end}>{m(l.amount)}</TableCell></TableRow>
            ))}</TableBody>
          </Table>
        </Panel>
      )}
    </div>
  )
}
