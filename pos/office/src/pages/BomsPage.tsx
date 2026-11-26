import { Button, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { BomList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = BomList['rows'][number]

/**
 * Manufacturing → Bills of materials (part 35): what each product is made from, every revision, by status. New bill of
 * materials; a row opens it (put in use, new revision, make, stop using).
 */
export function BomsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.boms, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className={page.page}>
      <PageHead title="Bills of materials" hint="What each product is made from, and the steps. Only a draft can change; a BOM in use changes through a new revision." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Code or product" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search bills of materials" />
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/manufacturing/boms/new')}>New bill of materials</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Bills of materials" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No bills of materials yet."
        onSort={() => undefined} onOpen={(r) => navigate(`/office/manufacturing/boms/${r.id}`)}
        columns={[
          { key: 'code', label: 'BOM', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
          { key: 'product', label: 'Makes', render: (r) => <Text weight="semibold">{r.product ?? '—'}</Text> },
          { key: 'batch', label: 'Batch', end: true, render: (r) => r.batch },
          { key: 'lines', label: 'Lines', end: true, render: (r) => r.lines },
          { key: 'qc', label: 'Quality check', render: (r) => (r.qc ? 'Yes' : '—') },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
