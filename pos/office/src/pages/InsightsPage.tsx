import { Dropdown, Field, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Tab, TabList } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type InsightsView } from '../api'
import { ExportMenu } from '../list/ExportMenu'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { ReportTable } from '../list/ReportTables'
import { navigate } from '../nav'

const useStyles = makeStyles({
  tabs: { marginBottom: '10px', overflowX: 'auto' },
  bar: { display: 'flex', alignItems: 'flex-end', gap: '10px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
})

/**
 * Insights (release 7): the built-in analytics, no AI model needed — demand forecast and reorder, stock health,
 * customers, pricing, cash flow and loss prevention — as the current page has them, one tab each. Forecast, stock
 * health and pricing are for one warehouse at a time. Company-wide money and customer figures need company-wide access;
 * loss prevention its own permission (the server leaves out the tabs a person may not open). Export gives the tab shown.
 */
export function InsightsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<InsightsView | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const query = serialize(params)
  useEffect(() => {
    const c = new AbortController()
    setLoading(true)
    api.insights(query, c.signal).then((d) => { setData(d); setError('') })
      .catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
      .finally(() => { if (!c.signal.aborted) setLoading(false) })
    return () => c.abort()
  }, [query])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Working out the insights…" /></div>
  const where = data.warehouses.find((w) => w.id === data.warehouse)?.label ?? ''

  return (
    <div className={page.page}>
      <PageHead title="Insights" hint="Built-in analytics from your own sales and stock: what to order, stock at risk, customers, prices, cash for the coming weeks and unusual till patterns." classic={`/admin/insights${query ? `?${query}` : ''}`} />
      <TabList className={s.tabs} selectedValue={data.tab} onTabSelect={(_, d) => set({ tab: String(d.value) })} aria-label="Insights">
        {data.tabs.map((t) => <Tab key={t.id} value={t.id}>{t.label}</Tab>)}
      </TabList>
      <div className={s.bar}>
        {data.by_warehouse && (
          <Field label="Warehouse">
            <Dropdown aria-label="Warehouse" value={where} selectedOptions={[data.warehouse]} style={{ minWidth: 220 }}
              onOptionSelect={(_, d) => d.optionValue && set({ warehouse: d.optionValue })}>
              {data.warehouses.map((w) => <Option key={w.id} value={w.id}>{w.label}</Option>)}
            </Dropdown>
          </Field>
        )}
        <span className={s.grow} />
        {loading && <Spinner size="tiny" />}
        <ExportMenu url="/office-api/insights/export" query={serialize({ ...params, tab: data.tab, warehouse: data.warehouse })}
          total={data.tables.reduce((n, t) => n + t.rows.length, 0) || null} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {data.tables.map((t, i) => <ReportTable key={`${data.tab}-${i}`} table={t} caption onOpen={navigate} />)}
    </div>
  )
}
