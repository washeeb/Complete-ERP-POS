import { Button, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { SerialList } from '../api'
import { Ask } from '../form/Ask'
import { Choose, Tags } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = SerialList['rows'][number]

/**
 * Electronics → Serial numbers (part 36): every serial / IMEI number, where it is, its warranty; a row opens its history.
 * Register numbers for units already in stock without one (held before tracking was switched on, or found in a count).
 */
export function SerialsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.serials, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [reg, setReg] = useState<{ warehouse_id: string; product_id: string; product: string | null; serials: string[] } | null>(null)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className={page.page}>
      <PageHead title="Serial numbers" hint="Every serial or IMEI number: where it is, who bought it and its warranty." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Serial / IMEI or product" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search serial numbers" />
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <span className={s.grow} />
        {data?.can_register && <Button appearance="primary" icon={<AddRegular />}
          onClick={() => { setDone(''); setReg({ warehouse_id: '', product_id: '', product: null, serials: [] }) }}>Register numbers for stock on hand</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {!!data?.gaps && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>{data.gaps} product and warehouse pair(s) do not match their stock now.</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Serial numbers" rows={data?.rows ?? []} sort="updated" dir="desc" loading={loading} empty="No serial numbers yet."
        onSort={() => undefined} onOpen={(r) => navigate(`/office/electronics/serials/${r.id}`)}
        columns={[
          { key: 'serial', label: 'Serial / IMEI', render: (r) => <Text font="monospace">{r.serial}</Text> },
          { key: 'product', label: 'Product', render: (r) => r.product ?? '—' },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'where', label: 'Where', render: (r) => r.where ?? '—' },
          { key: 'warranty', label: 'Warranty until', render: (r) => day(r.warranty) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {reg && data && (
        <Ask title="Register numbers for stock on hand" go="Register" width={620} onClose={() => setReg(null)} ready={!!reg.warehouse_id && !!reg.product_id && reg.serials.length > 0}
          run={() => api.registerSerials(reg).then((x) => { setReg(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <div className={s.one}>
            <Text>For units already in stock without a number (held before serial tracking was switched on, or found in a count).</Text>
            <Choose label="Warehouse" required options={data.warehouses} value={reg.warehouse_id} onChange={(x) => setReg({ ...reg, warehouse_id: x })} />
            <SearchPicker label="Product" required value={reg.product_id} text={reg.product} search={api.serialProducts} placeholder="Name or SKU"
              onChange={(x) => setReg({ ...reg, product_id: x?.id ?? '', product: x?.label ?? null })} />
            <Tags label="Serial / IMEI numbers" placeholder="Scan or type, then Enter" value={reg.serials} onChange={(x) => setReg({ ...reg, serials: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}
