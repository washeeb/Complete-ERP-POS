import { Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type Aging } from '../api'
import { money } from '../home/format'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', overflowX: 'auto' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const END = { style: { justifyContent: 'flex-end' } }
const COLS: [keyof Aging['totals'], string][] = [['current', 'Not yet due'], ['d1_30', '1–30 days'], ['d31_60', '31–60'], ['d61_90', '61–90'], ['d90_plus', 'Over 90']]

/**
 * Aged receivables (part 15): what each customer owes, by how late (days past due), the largest first; anything past
 * due is in red. A customer opens their page, where a payment is taken.
 */
export function AgedReceivablesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [data, setData] = useState<Aging | null>(null)
  const [error, setError] = useState('')
  useEffect(() => { api.agedReceivables().then(setData).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Adding up what is owed…" /></div>
  const m = (v: number) => money(v, data.currency, data.exponent)
  const late = data.totals.d1_30 + data.totals.d31_60 + data.totals.d61_90 + data.totals.d90_plus

  return (
    <div className={page.page}>
      <PageHead title="Aged receivables" hint="What each customer owes, by how late. Press a customer to open them and take a payment." />
      <Cards items={[
        { label: 'Owed in all', value: m(data.totals.total) },
        { label: 'Not yet due', value: m(data.totals.current) },
        { label: 'Past due', value: m(late), tone: late ? 'var(--zx-bad)' : undefined },
      ]} />
      <section className={s.panel} aria-label="Owed by customers">
        {data.rows.length === 0 ? <Text className={s.muted}>Nobody owes anything.</Text> : (
          <Table size="small" aria-label="Owed by customers">
            <TableHeader><TableRow>
              <TableHeaderCell>Customer</TableHeaderCell>
              {COLS.map(([k, l]) => <TableHeaderCell key={k} className={s.end} button={END}>{l}</TableHeaderCell>)}
              <TableHeaderCell className={s.end} button={END}>Total</TableHeaderCell>
            </TableRow></TableHeader>
            <TableBody>
              {data.rows.map((r) => (
                <TableRow key={r.id}>
                  <TableCell>{r.open ? <Link onClick={() => navigate(r.open!)}>{r.name}</Link> : r.name}<Text block size={200} className={s.muted}>{r.code}</Text></TableCell>
                  {COLS.map(([k]) => (
                    <TableCell key={k} className={s.end}><Text style={r[k as keyof typeof r] && k !== 'current' ? { color: 'var(--zx-bad)' } : undefined}>{m(r[k as keyof typeof r] as number)}</Text></TableCell>
                  ))}
                  <TableCell className={s.end}><Text weight="semibold">{m(r.total)}</Text></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </section>
    </div>
  )
}
