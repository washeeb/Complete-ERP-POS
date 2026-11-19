import {
  Button, Dropdown, Field, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowRightRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SlowRow, type SlowView } from '../api'
import { Ask } from '../form/Ask'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '10px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', overflowX: 'auto' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const END = { style: { justifyContent: 'flex-end' } }

/**
 * Slow and dead stock (release 7): products in a store not sold in the period chosen (or, never sold, since they came
 * in), the most money first, each with a suggestion: move it to the store where it sells (Move makes a draft transfer
 * to check and dispatch), ask the supplier to take it back, or mark it down.
 */
export function SlowStockPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<SlowView | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [moving, setMoving] = useState<SlowRow | null>(null)
  const query = serialize(params)
  useEffect(() => {
    const c = new AbortController()
    setLoading(true)
    api.slowStock(query, c.signal).then((d) => { setData(d); setError('') })
      .catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
      .finally(() => { if (!c.signal.aborted) setLoading(false) })
    return () => c.abort()
  }, [query])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Looking for slow stock…" /></div>
  const where = data.warehouses.find((w) => w.id === data.warehouse)?.label ?? ''
  const exportQuery = serialize({ warehouse: data.warehouse, days: String(data.days) })

  return (
    <div className={page.page}>
      <PageHead title="Slow and dead stock" hint="Not sold here in the period (or, if never sold, since it came in). Move it where it sells, ask the supplier to take it back, or mark it down."
        classic={`/admin/stock/slow-stock${query ? `?${query}` : ''}`} />
      <div className={s.bar}>
        <Field label="Store">
          <Dropdown aria-label="Store" value={where} selectedOptions={[data.warehouse]} style={{ minWidth: 220 }} onOptionSelect={(_, d) => d.optionValue && set({ warehouse: d.optionValue })}>
            {data.warehouses.map((w) => <Option key={w.id} value={w.id}>{w.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Not sold for">
          <Dropdown aria-label="Not sold for" value={data.periods.find((x) => x.id === String(data.days))?.label ?? ''} selectedOptions={[String(data.days)]}
            onOptionSelect={(_, d) => d.optionValue && set({ days: d.optionValue })}>
            {data.periods.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
          </Dropdown>
        </Field>
        <span className={s.grow} />
        {loading && <Spinner size="tiny" />}
        <ExportMenu url="/office-api/slow-stock/export" query={exportQuery} total={data.count || null} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Cards items={[{ label: 'Products', value: data.count.toLocaleString('en-GB') }, { label: 'Value at cost', value: data.value }]} />
      <section className={s.panel} aria-label="Slow and dead stock">
        {data.rows.length === 0 ? <Text>Nothing slow in this store. Well done.</Text> : (
          <Table size="small" aria-label="Slow and dead stock" style={{ minWidth: 720 }}>
            <TableHeader><TableRow>
              <TableHeaderCell>Product</TableHeaderCell><TableHeaderCell className={s.end} button={END}>On hand</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Value</TableHeaderCell><TableHeaderCell>Last sold</TableHeaderCell><TableHeaderCell>Suggestion</TableHeaderCell>
            </TableRow></TableHeader>
            <TableBody>{data.rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell>{r.name}<Text block size={200} className={s.muted}>{r.sku}</Text></TableCell>
                <TableCell className={s.end}>{r.on_hand}</TableCell>
                <TableCell className={s.end}>{r.value}</TableCell>
                <TableCell>{r.last_sold ?? 'never'}<Text block size={200} className={s.muted}>{r.idle_days} days</Text></TableCell>
                <TableCell>
                  {r.move_to ? <>
                    Sells at {r.move_to.code} ({r.move_to.sold} in 90 days)
                    {data.can_move && <Button size="small" appearance="subtle" icon={<ArrowRightRegular />} onClick={() => setMoving(r)} style={{ marginLeft: 6 }}>Move</Button>}
                  </> : r.supplier ? <>Ask {r.supplier} to take it back (Stock → Expiry &amp; returns).</> : 'Mark it down or put it in a bundle.'}
                </TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </section>
      {moving?.move_to && <Ask title={`Move ${moving.name} to ${moving.move_to.code}?`} go="Make the transfer" ready onClose={() => setMoving(null)}
        run={async () => navigate((await api.moveSlowStock(data.warehouse, moving.id, moving.move_to!.id)).transfer.open)}>
        A draft transfer of all of it ({moving.on_hand}) to {moving.move_to.name}. It opens next: check the quantity, then dispatch it.
      </Ask>}
    </div>
  )
}
