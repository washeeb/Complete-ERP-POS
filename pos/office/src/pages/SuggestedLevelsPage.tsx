import { Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, Text, tokens } from '@fluentui/react-components'
import { ArrowClockwiseRegular, CheckmarkRegular, ListRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type Option as Choice, type SuggestedRow } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

/**
 * Suggested reorder levels (release 5): minimum and maximum worked out every Monday from the last 12 weeks of sales,
 * the supplier's delivery time and a safety margin. Tick the ones you agree with and accept: they replace the default
 * (or the product's own figure) in that store. Only changes of a fifth or more are listed.
 */
export function SuggestedLevelsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [warehouse, setWarehouse] = useState('')
  const [data, setData] = useState<{ warehouses: Choice[]; rows: SuggestedRow[] } | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState('')
  const [pageNo, setPageNo] = useState(1)
  const [per, setPer] = useState(25)
  const load = useCallback(() => {
    setLoading(true)
    api.suggestedLevels(warehouse).then((d) => { setData(d); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setLoading(false))
  }, [warehouse])
  useEffect(load, [load])

  const rows = data?.rows ?? []
  const run = (p: Promise<string>) => {
    setBusy(true)
    setError('')
    p.then((text) => { setDone(text); setTicked(new Set()); load() }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }
  const columns: Column<SuggestedRow>[] = [
    { key: 'product', label: 'Product', render: (r) => <><Text weight="semibold">{r.name}</Text><Text block size={200} className={s.muted}>{r.sku}</Text></> },
    { key: 'store', label: 'Store', render: (r) => r.store },
    { key: 'avg', label: 'Sells a day', end: true, render: (r) => r.avg_daily },
    { key: 'lead', label: 'Delivery (days)', end: true, render: (r) => r.lead_days },
    { key: 'now', label: 'Now (min / max)', render: (r) => <>{r.now}{r.now_source && <Text block size={200} className={s.muted}>{r.now_source}</Text>}</> },
    { key: 'suggested', label: 'Suggested min / max', render: (r) => <Text weight="semibold">{r.suggested}</Text> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Suggested reorder levels" hint="Worked out every Monday from the last 12 weeks of sales, the supplier's delivery time and a safety margin. Only changes of a fifth or more are listed." classic="/admin/suggested-levels" />
      <div className={s.bar}>
        <Dropdown aria-label="Store" value={warehouse ? (data?.warehouses.find((w) => w.id === warehouse)?.label ?? warehouse) : 'Every store'} selectedOptions={[warehouse]}
          onOptionSelect={(_, d) => { setWarehouse(d.optionValue ?? ''); setTicked(new Set()); setPageNo(1) }} style={{ minWidth: 180 }}>
          <Option value="">Every store</Option>{(data?.warehouses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        <Button icon={<ListRegular />} onClick={() => navigate('/office/reorder-levels')}>Reorder levels</Button>
        <Button icon={<ArrowClockwiseRegular />} disabled={busy} onClick={() => run(api.recalculateLevels().then((r) => `${r.suggested} suggestion(s).`))}>Work them out now</Button>
        <Button appearance="primary" icon={<CheckmarkRegular />} disabled={busy || ticked.size === 0}
          onClick={() => run(api.acceptLevels([...ticked]).then((r) => `${r.accepted} level(s) accepted.`))}>Accept{ticked.size ? ` (${ticked.size})` : ''}</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Suggested levels" columns={columns} rows={rows.slice((pageNo - 1) * per, pageNo * per)} sort="" dir="asc" loading={loading}
        empty="No suggestions: nothing has sold enough to suggest a different level, or the levels in use already match. Suggestions are worked out every Monday."
        onSort={() => undefined} onOpen={(r) => setTicked((t) => { const n = new Set(t); if (n.has(r.id)) n.delete(r.id); else n.add(r.id); return n })} selected={ticked}
        onSelect={(ids, on) => setTicked((t) => { const n = new Set(t); ids.forEach((i) => (on ? n.add(i) : n.delete(i))); return n })} />
      {rows.length > 0 && <Pager page={pageNo} per={per} total={rows.length} options={[10, 25, 50, 100]} onPage={setPageNo} onPer={(n) => { setPer(n); setPageNo(1) }} />}
    </div>
  )
}
