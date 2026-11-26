import { MessageBar, MessageBarBody, Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type ScaleData } from '../api'
import { money } from '../home/format'
import { DataTable } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useProcStyles } from './ProcurementParts'

type Row = ScaleData['rows'][number]

/**
 * Grocery → Scale items (part 20): the weighed products with a scale code (PLU), their unit and retail price, as the
 * scales need them. Export it to load the scales; the till reads the labels they print (prefix, PLU, weight).
 */
export function ScaleItemsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [data, setData] = useState<ScaleData | null>(null)
  const [error, setError] = useState('')
  useEffect(() => { api.scaleItems().then(setData).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])
  return (
    <div className={page.page}>
      <PageHead title="Scale items" hint="The weighed products the scales are loaded with." />
      <div className={s.bar}>
        {data && <Text className={s.muted} style={{ flex: '1 1 300px' }}>Load these into the scales. Their labels start with {data.prefixes}, then the scale code (PLU), then the weight in grams; the till
          reads them and charges by weight. Give a product its scale code under Catalog → Products.</Text>}
        <span className={s.grow} />
        {data && <ExportMenu url="/office-api/scale-items/export" query="" total={data.rows.length || null} />}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Scale items" rows={data?.rows ?? []} sort="plu" dir="asc" loading={!data && !error} empty="No product has a scale code yet." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'plu', label: 'Scale code (PLU)', render: (r) => <Text font="monospace">{r.plu}</Text> },
          { key: 'sku', label: 'SKU', render: (r) => r.sku ?? '—' },
          { key: 'name', label: 'Product', render: (r) => r.name },
          { key: 'unit', label: 'Unit', render: (r) => r.unit ?? '—' },
          { key: 'price', label: 'Retail price per unit', end: true, render: (r) => (r.price === null || !data ? <Text className={s.muted}>No retail price</Text> : money(r.price, data.currency, data.exponent)) },
        ]} />
    </div>
  )
}
