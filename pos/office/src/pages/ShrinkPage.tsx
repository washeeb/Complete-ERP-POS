import { Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type ShrinkView } from '../api'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { ReportTable } from '../list/ReportTables'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '10px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 580px), 1fr))', columnGap: '12px' },
})

/**
 * Shrink and write-offs (release 7): stock lost at cost — adjustments and stock-count shortages — by reason, by product,
 * month by month for the last six months, by category and by who recorded it, and the products lost again and again.
 * Dates and store live in the address. Export gives every table as shown.
 */
export function ShrinkPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<ShrinkView | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const query = serialize(params)
  useEffect(() => {
    const c = new AbortController()
    setLoading(true)
    api.shrink(query, c.signal).then((d) => { setData(d); setError('') })
      .catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
      .finally(() => { if (!c.signal.aborted) setLoading(false) })
    return () => c.abort()
  }, [query])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Adding up the losses…" /></div>
  const where = data.warehouses.find((w) => w.id === data.warehouse)?.label ?? 'All'
  const [first, second, ...rest] = data.tables

  return (
    <div className={page.page}>
      <PageHead title="Shrink and write-offs" hint="Stock lost at cost: damage, expiry, theft and other write-offs, and stock-count shortages. Watch the trend and the products lost again and again."
        />
      <div className={s.bar}>
        <Field label="From"><Input type="date" value={data.from} onChange={(_, d) => d.value && set({ from: d.value })} /></Field>
        <Field label="To"><Input type="date" value={data.to} onChange={(_, d) => d.value && set({ to: d.value })} /></Field>
        <Field label="Warehouse">
          <Dropdown aria-label="Warehouse" value={where} selectedOptions={[data.warehouse]} style={{ minWidth: 220 }} onOptionSelect={(_, d) => set({ warehouse: d.optionValue ?? '' })}>
            <Option value="">All</Option>{data.warehouses.map((w) => <Option key={w.id} value={w.id}>{w.label}</Option>)}
          </Dropdown>
        </Field>
        <span className={s.grow} />
        {loading && <Spinner size="tiny" />}
        <ExportMenu url="/office-api/shrink/export" query={query} total={data.tables.reduce((n, t) => n + t.rows.length, 0) || null} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Cards items={[{ label: 'Lost at cost', value: data.total, tone: data.entries ? 'var(--zx-bad)' : undefined }, { label: 'Entries', value: data.entries.toLocaleString('en-GB') }]} />
      {first && <ReportTable table={first} caption onOpen={navigate} />}
      {second && <ReportTable table={second} caption onOpen={navigate} />}
      <div className={s.grid}>{rest.map((t, i) => <ReportTable key={i} table={t} caption onOpen={navigate} />)}</div>
    </div>
  )
}
