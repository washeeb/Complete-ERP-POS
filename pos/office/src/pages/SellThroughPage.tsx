import { Button, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { useState } from 'react'
import { api } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { END, Panel, useProcStyles } from './ProcurementParts'

type Owed = { consignor_id: string; name: string; branch_id: string; branch: string; owed: number }

/**
 * Fashion → Sell-through & consignment (part 36), as the current page: for a period and season, each style's units sold
 * (net of returns), on hand now and sell-through; and what consignors are owed, settled as a supplier bill.
 */
export function SellThroughPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.sellThrough, serialize(params), refresh)
  const [settle, setSettle] = useState<Owed | null>(null)
  const [stmt, setStmt] = useState('')
  const [done, setDone] = useState('')
  const [bill, setBill] = useState<string | null>(null)
  const m =(x: number) => (data ? money(x, data.currency, data.exponent) : '')
  return (
    <div className={page.page}>
      <PageHead title="Sell-through & consignment" hint="How each style sells against what is left, and what consignors are owed for their items sold." classic="/admin/fashion-report" />
      <div className={s.bar}>
        <TextField label="From" type="date" value={data?.from ?? str(params, 'from')} onChange={(v) => set({ from: v })} />
        <TextField label="To" type="date" value={data?.to ?? str(params, 'to')} onChange={(v) => set({ to: v })} />
        <div style={{ alignSelf: 'flex-end' }}><Pick label="Season" any="All seasons" options={data?.seasons ?? []} value={str(params, 'season')} onChange={(v) => set({ season: v })} /></div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}{bill && <> <Link onClick={() => navigate(bill)}>Open the bill</Link></>}</MessageBarBody></MessageBar>}
      {!data ? (loading ? <Spinner label="Adding up…" /> : null) : (
        <>
          <Panel title="Sell-through by style" about="Units sold (net of returns) in the period, on hand now, and sold ÷ (sold + on hand).">
            {data.styles.length === 0 ? <Text className={s.muted}>No styles yet.</Text> : (
              <Table size="small" aria-label="Sell-through by style">
                <TableHeader><TableRow><TableHeaderCell>Style</TableHeaderCell><TableHeaderCell>Season</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Sold</TableHeaderCell>
                  <TableHeaderCell className={s.end} button={END}>On hand</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Sell-through</TableHeaderCell>
                  <TableHeaderCell className={s.end} button={END}>Sales (before tax)</TableHeaderCell></TableRow></TableHeader>
                <TableBody>{data.styles.map((r) => (
                  <TableRow key={r.id}><TableCell><Text weight="semibold">{r.name}</Text> <Text size={200} className={s.muted}>{r.code}</Text></TableCell>
                    <TableCell>{[r.season, r.collection].filter(Boolean).join(' · ') || '—'}</TableCell><TableCell className={s.end}>{r.sold}</TableCell>
                    <TableCell className={s.end}>{r.on_hand}</TableCell><TableCell className={s.end}>{r.sell_through}</TableCell><TableCell className={s.end}>{m(r.revenue)}</TableCell></TableRow>
                ))}</TableBody>
              </Table>
            )}
          </Panel>
          {data.consignment && (
            <Panel title="Consignment — owed to consignors" about="Their share of consignment items sold (less returns), not yet settled, up to the end date above.">
              {data.owed.length === 0 ? <Text className={s.muted}>Nothing owed.</Text> : (
                <Table size="small" aria-label="Owed to consignors">
                  <TableHeader><TableRow><TableHeaderCell>Consignor</TableHeaderCell><TableHeaderCell>Branch</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Items</TableHeaderCell>
                    <TableHeaderCell className={s.end} button={END}>Sold (before tax)</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Owed</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
                  <TableBody>{data.owed.map((o) => (
                    <TableRow key={`${o.consignor_id}-${o.branch_id}`}><TableCell>{o.name}</TableCell><TableCell>{o.branch}</TableCell><TableCell className={s.end}>{o.qty}</TableCell>
                      <TableCell className={s.end}>{m(o.sold)}</TableCell><TableCell className={s.end}><b>{m(o.owed)}</b></TableCell>
                      <TableCell>{data.can_settle && <Button size="small" onClick={() => { setDone(''); setStmt(''); setSettle(o) }}>Settle (raise bill)</Button>}</TableCell></TableRow>
                  ))}</TableBody>
                </Table>
              )}
            </Panel>
          )}
        </>
      )}
      {settle && data && (
        <Ask title={`Settle ${settle.name}?`} go="Raise bill" ready onClose={() => setSettle(null)}
          run={() => api.settleConsignor({ consignor_id: settle.consignor_id, branch_id: settle.branch_id, to: data.to, statement_no: stmt }).then((x) => {
            setSettle(null); setDone(x.message); setBill(x.open ?? null); setRefresh((n) => n + 1)
          })}>
          <div className={s.one}>
            <Text>A supplier bill of {m(settle.owed)} for {settle.branch}, for sales up to {data.to}. It is approved and paid as usual in Supplier bills.</Text>
            <TextField label="Statement no. (optional)" maxLength={60} value={stmt} onChange={setStmt} />
          </div>
        </Ask>
      )}
    </div>
  )
}
