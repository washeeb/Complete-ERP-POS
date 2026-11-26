import { Field, Input, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type TipsVoids } from '../api'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { END, Panel, useProcStyles } from './ProcurementParts'

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

/**
 * Restaurant → Tips & voids (part 30): tips owed per person for a period (paid with payroll) and the items voided, with
 * who added and voided each and whether it had gone to the kitchen. The period lives in the address.
 */
export function TipsVoidsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [d, setD] = useState<TipsVoids | null>(null)
  const [error, setError] = useState('')
  const query = serialize(params)
  useEffect(() => {
    const c = new AbortController()
    api.tipsVoids(query, c.signal).then((x) => { setD(x); setError('') }).catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
    return () => c.abort()
  }, [query])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Adding up tips and voids…" /></div>
  const m = (x: number) => money(x, d.currency, d.exponent)
  return (
    <div className={page.page}>
      <PageHead title="Tips & voids" hint="Tips owed per person (paid with payroll) and voided items, for a period." />
      <div className={s.bar} style={{ alignItems: 'flex-end' }}>
        <Field label="From"><Input type="date" value={d.from} onChange={(_, e) => e.value && set({ from: e.value })} /></Field>
        <Field label="To"><Input type="date" value={d.to} onChange={(_, e) => e.value && set({ to: e.value })} /></Field>
      </div>
      <Panel title="Tips">
        <Table size="small" aria-label="Tips" style={{ minWidth: 420 }}>
          <TableHeader><TableRow><TableHeaderCell>Person</TableHeaderCell><TableHeaderCell button={END}>Bills</TableHeaderCell><TableHeaderCell button={END}>Tips</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {d.tips.length === 0 && <TableRow><TableCell colSpan={3}><Text className={s.muted}>No tips in this period.</Text></TableCell></TableRow>}
            {d.tips.map((t) => <TableRow key={t.name}><TableCell>{t.name}</TableCell><TableCell className={s.end}>{t.bills}</TableCell><TableCell className={s.end}>{m(t.tips)}</TableCell></TableRow>)}
          </TableBody>
        </Table>
      </Panel>
      <Panel title="Voided items" about="The newest 500 in the period.">
        <Table size="small" aria-label="Voided items" style={{ minWidth: 640 }}>
          <TableHeader><TableRow><TableHeaderCell>Bill</TableHeaderCell><TableHeaderCell>Added</TableHeaderCell><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell>In the kitchen</TableHeaderCell>
            <TableHeaderCell>Why</TableHeaderCell><TableHeaderCell>Voided by</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {d.voids.length === 0 && <TableRow><TableCell colSpan={6}><Text className={s.muted}>No voided items in this period.</Text></TableCell></TableRow>}
            {d.voids.map((v) => (
              <TableRow key={v.id}><TableCell>{v.bill}</TableCell><TableCell>{when(v.added)}{v.added_by && <Text size={200} block className={s.muted}>{v.added_by}</Text>}</TableCell>
                <TableCell>{v.item}</TableCell><TableCell>{v.fired ? 'Yes' : 'No'}</TableCell><TableCell>{v.reason ?? '—'}</TableCell><TableCell>{v.by ?? '—'}</TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
    </div>
  )
}
