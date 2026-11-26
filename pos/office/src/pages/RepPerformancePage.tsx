import { Field, Input, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type RepPerformance } from '../api'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { END, Panel, useProcStyles } from './ProcurementParts'

const pct = (bp: number | null) => (bp === null ? '—' : `${(bp / 100).toFixed(1)} %`)

/**
 * Distribution → Rep performance (part 34): reps against target for a period (sales on van tills, visits, strike rate,
 * orders, money collected), customers on a route not visited on a day, and empties customers hold. Dates live in the
 * address.
 */
export function RepPerformancePage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [d, setD] = useState<RepPerformance | null>(null)
  const [error, setError] = useState('')
  const query = serialize(params)
  useEffect(() => {
    const c = new AbortController()
    api.repPerformance(query, c.signal).then((x) => { setD(x); setError('') }).catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
    return () => c.abort()
  }, [query])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Adding up the reps' figures…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  return (
    <div className={page.page}>
      <PageHead title="Rep performance" hint="Reps against target, customers not visited, and empties held by customers." />
      <div className={s.bar} style={{ alignItems: 'flex-end' }}>
        <Field label="From"><Input type="date" value={d.from} onChange={(_, e) => e.value && set({ from: e.value })} /></Field>
        <Field label="To"><Input type="date" value={d.to} onChange={(_, e) => e.value && set({ to: e.value })} /></Field>
      </div>
      <Panel title="Reps against target" about="Sales before tax on van tills (returns netted), visits recorded on the phone, strike rate (visits that sold ÷ visits), orders taken and money collected on customer accounts.">
        <Table size="small" aria-label="Reps against target" style={{ minWidth: 820 }}>
          <TableHeader><TableRow><TableHeaderCell>Rep</TableHeaderCell><TableHeaderCell button={END}>Sales</TableHeaderCell><TableHeaderCell button={END}>Target</TableHeaderCell>
            <TableHeaderCell button={END}>Achieved</TableHeaderCell><TableHeaderCell button={END}>Receipts</TableHeaderCell><TableHeaderCell button={END}>Visits</TableHeaderCell>
            <TableHeaderCell button={END}>Strike rate</TableHeaderCell><TableHeaderCell button={END}>Orders</TableHeaderCell><TableHeaderCell button={END}>Collected</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {d.reps.length === 0 && <TableRow><TableCell colSpan={9}><Text className={s.muted}>No van sales, visits or targets in this period.</Text></TableCell></TableRow>}
            {d.reps.map((r, i) => (
              <TableRow key={i}><TableCell>{r.name}</TableCell><TableCell className={s.end}>{m(r.sales_minor)}</TableCell><TableCell className={s.end}>{r.target_minor ? m(r.target_minor) : '—'}</TableCell>
                <TableCell className={s.end}>{pct(r.achieved_bp)}</TableCell><TableCell className={s.end}>{r.receipts}</TableCell>
                <TableCell className={s.end}>{r.visits}{r.visit_target ? ` / ${r.visit_target}` : ''}</TableCell><TableCell className={s.end}>{pct(r.strike_bp)}</TableCell>
                <TableCell className={s.end}>{r.orders}</TableCell><TableCell className={s.end}>{m(r.collections_minor)}</TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
      <Panel title="Customers not visited" about="Customers on a route that runs on this day with no visit recorded.">
        <Field label="Day" style={{ maxWidth: 200, marginBottom: 8 }}><Input type="date" value={d.date} onChange={(_, e) => e.value && set({ date: e.value })} /></Field>
        <Table size="small" aria-label="Customers not visited" style={{ minWidth: 520 }}>
          <TableHeader><TableRow><TableHeaderCell>Route</TableHeaderCell><TableHeaderCell button={END}>Stop</TableHeaderCell><TableHeaderCell>Customer</TableHeaderCell><TableHeaderCell>Phone</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {d.missed.length === 0 && <TableRow><TableCell colSpan={4}><Text className={s.muted}>Every customer due that day was visited (or no route runs that day).</Text></TableCell></TableRow>}
            {d.missed.map((x, i) => (
              <TableRow key={i}><TableCell>{x.route}</TableCell><TableCell className={s.end}>{x.sequence}</TableCell>
                <TableCell>{x.name} <Text size={200} className={s.muted}>{x.code}</Text></TableCell><TableCell>{x.phone ?? '—'}</TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
      <Panel title="Empties held by customers" about="Returnable containers sold to each customer minus those handed back, and the deposits they paid.">
        <Table size="small" aria-label="Empties held by customers" style={{ minWidth: 520 }}>
          <TableHeader><TableRow><TableHeaderCell>Customer</TableHeaderCell><TableHeaderCell>Container</TableHeaderCell><TableHeaderCell button={END}>Held</TableHeaderCell>
            <TableHeaderCell button={END}>Deposits</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {d.empties.length === 0 && <TableRow><TableCell colSpan={4}><Text className={s.muted}>No empties held by customers.</Text></TableCell></TableRow>}
            {d.empties.map((x, i) => (
              <TableRow key={i}><TableCell>{x.customer} <Text size={200} className={s.muted}>{x.code}</Text></TableCell><TableCell>{x.container}</TableCell>
                <TableCell className={s.end}>{x.held}</TableCell><TableCell className={s.end}>{m(x.deposit)}</TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
    </div>
  )
}
