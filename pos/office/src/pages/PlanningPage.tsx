import { Button, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { WrenchRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api } from '../api'
import { Ask } from '../form/Ask'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { END, Panel, useProcStyles } from './ProcurementParts'

/**
 * Manufacturing → Production planning (MRP, part 35), as the current page: for one warehouse, what to make (level by level,
 * from customer orders, reorder levels and open work orders), what to buy, and the load on each work centre. Raise a work
 * order for one suggestion, or all of them; they start as drafts.
 */
export function PlanningPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.mfgPlan, serialize(params), refresh)
  const [ask, setAsk] = useState<{ product: string | null; name: string } | null>(null)
  const [done, setDone] = useState('')
  const wh = data?.warehouse ?? str(params, 'warehouse')
  return (
    <div className={page.page}>
      <PageHead title="Production planning" hint="What to make and buy for customer orders, reorder levels and open work orders. Suggestions become draft work orders." />
      <div className={s.bar}>
        <Pick label="Warehouse" any="Choose a warehouse" options={data?.warehouses ?? []} value={wh} width={220} onChange={(v) => { setDone(''); set({ warehouse: v }) }} />
        <span className={s.grow} />
        {data?.can_make && data.make.length > 0 && <Button appearance="primary" icon={<WrenchRegular />} onClick={() => setAsk({ product: null, name: '' })}>Raise all work orders</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {!data ? (loading ? <Spinner label="Planning…" /> : null) : (
        <>
          <Panel title="To make" about="Products with a bill of materials in use, level by level: making a product needs its materials, so their need follows.">
            {data.make.length === 0 ? <Text className={s.muted}>Nothing to make for this warehouse.</Text> : (
              <Table size="small" aria-label="To make">
                <TableHeader><TableRow><TableHeaderCell>Product</TableHeaderCell><TableHeaderCell>Level</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Needed</TableHeaderCell>
                  <TableHeaderCell className={s.end} button={END}>On hand</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Coming</TableHeaderCell>
                  <TableHeaderCell className={s.end} button={END}>Make</TableHeaderCell><TableHeaderCell>BOM</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
                <TableBody>{data.make.map((r) => (
                  <TableRow key={r.product_id}><TableCell><Text weight="semibold">{r.name}</Text><Text size={200} block className={s.muted}>{r.sku}</Text></TableCell>
                    <TableCell>{r.level}</TableCell><TableCell className={s.end}>{r.needed}</TableCell><TableCell className={s.end}>{r.on_hand}</TableCell>
                    <TableCell className={s.end}>{r.coming}</TableCell><TableCell className={s.end}><b>{r.suggested}</b></TableCell><TableCell>{r.bom}</TableCell>
                    <TableCell>{data.can_make && <Button size="small" onClick={() => setAsk({ product: r.product_id, name: r.name })}>Raise work order</Button>}</TableCell></TableRow>
                ))}</TableBody>
              </Table>
            )}
          </Panel>
          <Panel title="To buy" about="Materials the plan needs that are not made here. Order them in Purchasing.">
            {data.buy.length === 0 ? <Text className={s.muted}>Nothing to buy.</Text> : (
              <Table size="small" aria-label="To buy">
                <TableHeader><TableRow><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Needed</TableHeaderCell>
                  <TableHeaderCell className={s.end} button={END}>On hand</TableHeaderCell><TableHeaderCell className={s.end} button={END}>On order</TableHeaderCell>
                  <TableHeaderCell className={s.end} button={END}>Buy</TableHeaderCell></TableRow></TableHeader>
                <TableBody>{data.buy.map((r) => (
                  <TableRow key={r.sku}><TableCell><Text weight="semibold">{r.name}</Text><Text size={200} block className={s.muted}>{r.sku}</Text></TableCell>
                    <TableCell className={s.end}>{r.needed}</TableCell><TableCell className={s.end}>{r.on_hand}</TableCell><TableCell className={s.end}>{r.on_order}</TableCell>
                    <TableCell className={s.end}><b>{r.suggested}</b></TableCell></TableRow>
                ))}</TableBody>
              </Table>
            )}
          </Panel>
          <Panel title="Work centre load" about="Hours the open work orders still need on each work centre, and how many working days that is.">
            {data.load.length === 0 ? <Text className={s.muted}>No open work on the work centres.</Text> : (
              <Table size="small" aria-label="Work centre load">
                <TableHeader><TableRow><TableHeaderCell>Work centre</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Hours</TableHeaderCell>
                  <TableHeaderCell className={s.end} button={END}>Hours a day</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Days</TableHeaderCell></TableRow></TableHeader>
                <TableBody>{data.load.map((r) => (
                  <TableRow key={r.work_centre}><TableCell>{r.work_centre}</TableCell><TableCell className={s.end}>{r.hours}</TableCell>
                    <TableCell className={s.end}>{r.per_day}</TableCell><TableCell className={s.end}>{r.days ?? '—'}</TableCell></TableRow>
                ))}</TableBody>
              </Table>
            )}
          </Panel>
        </>
      )}
      {ask && (
        <Ask title={ask.product ? `Raise a work order for ${ask.name}?` : 'Raise all work orders?'} go="Raise" ready onClose={() => setAsk(null)}
          run={() => api.raiseMfgOrders(wh, ask.product).then((x) => { setAsk(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <Text>Draft work orders for the suggested quantities. They do nothing until released.</Text>
        </Ask>
      )}
    </div>
  )
}
